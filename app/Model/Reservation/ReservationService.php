<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use App\Model\Payment\VariableSymbol;
use PDO;
use Throwable;


/**
 * Ticket reservation rules. All limits are enforced here, never only in the browser.
 * A draft reservation belongs to an "owner" (random key of one browser, see ReservationSession).
 * See docs/legacy-backend.md for how this maps to the original site.
 */
final class ReservationService
{
	/** Reservations that hold their seats and tickets (as an SQL list). */
	public const FinishedStatuses = "'confirmed', 'partially_paid', 'paid'";

	/** Static decorations of the hall map, in map units like hall_tables. */
	private const HallAreas = [
		['kind' => 'stage', 'x' => 350, 'y' => 18, 'width' => 300, 'height' => 56, 'label' => 'Pódium'],
		['kind' => 'floor', 'x' => 250, 'y' => 110, 'width' => 500, 'height' => 380, 'label' => 'Parket'],
	];


	public function __construct(
		private readonly PDO $db,
		private readonly Settings $settings,
		private readonly VariableSymbol $variableSymbol,
		private readonly EventLog $eventLog,
	) {
	}


	// --- Read models ---------------------------------------------------------

	/**
	 * Tables, seats and decorations for rendering the hall map.
	 * @return array{width: int, height: int, areas: list<array<string, int|string>>, tables: list<array<string, int|string>>, seats: list<array<string, int|string>>}
	 */
	public function hallLayout(): array
	{
		// query() uses the text protocol, so numeric columns arrive as strings.
		$cast = static function (array $row, array $intColumns): array {
			foreach ($intColumns as $column) {
				$row[$column] = (int) $row[$column];
			}
			return $row;
		};
		return [
			'width' => $this->settings->int('map_width', 1000),
			'height' => $this->settings->int('map_height', 640),
			'areas' => self::HallAreas,
			'tables' => array_map(
				static fn(array $row): array => $cast($row, ['id', 'x', 'y', 'width', 'height']),
				$this->db->query('SELECT id, label, x, y, width, height FROM hall_tables ORDER BY id')->fetchAll(),
			),
			'seats' => array_map(
				static fn(array $row): array => $cast($row, ['id', 'table_id', 'x', 'y']),
				$this->db->query('SELECT id, label, table_id, x, y FROM seats ORDER BY id')->fetchAll(),
			),
		];
	}


	/**
	 * Everything the reservation page needs; never exposes other people's data.
	 * @return array<string, mixed>
	 */
	public function state(string $owner): array
	{
		$draft = $this->currentDraft($owner);
		$draftId = $draft !== null ? (int) $draft['id'] : 0;

		$taken = [];
		$mine = [];
		foreach ($this->db->query("SELECT id, reservation_id FROM seats WHERE state <> 'free'")->fetchAll() as $row) {
			if ($draftId && (int) $row['reservation_id'] === $draftId) {
				$mine[] = (int) $row['id'];
			} else {
				$taken[] = (int) $row['id'];
			}
		}

		$reservation = null;
		if ($draft !== null) {
			$reservation = [
				'email' => $draft['email'],
				'standing' => (int) $draft['standing_tickets'],
				'seats' => $this->seatLabels($mine),
				'expires_in' => $this->holdExpiresIn($draftId),
			];
		}

		return [
			'sale_open' => $this->settings->mode()->isSelling(),
			'reservation' => $reservation,
			'taken' => $taken,
			'mine' => $mine,
			'limits' => [
				'max_tickets' => $this->settings->int('max_ticket', 10),
				'standing_left' => $this->standingLeft(),
				'hold_seconds' => $this->holdSeconds(),
			],
			'prices' => [
				'seat' => $this->settings->int('price_seat'),
				'standing' => $this->settings->int('price_standing'),
			],
		];
	}


	/**
	 * A finished (confirmed or paid) reservation with its seat labels.
	 * @return array<string, mixed>|null
	 */
	public function findFinished(int $id): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM reservations WHERE id = ? AND status IN (' . self::FinishedStatuses . ')');
		$stmt->execute([$id]);
		$reservation = $stmt->fetch();
		if (!$reservation) {
			return null;
		}
		$seats = $this->db->prepare('SELECT label FROM seats WHERE reservation_id = ? ORDER BY id');
		$seats->execute([$id]);
		$reservation['seat_labels'] = $seats->fetchAll(PDO::FETCH_COLUMN);
		$reservation['variable_symbol'] = $this->variableSymbol->forReservation($id);
		return $reservation;
	}


	// --- Commands ------------------------------------------------------------

	/** Frees seats whose temporary hold has run out. Called at the start of every request. */
	public function releaseExpiredHolds(): void
	{
		$this->db->prepare(
			"UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
			WHERE state = 'book' AND booked_at < NOW() - INTERVAL ? SECOND",
		)->execute([$this->holdSeconds()]);
	}


	/**
	 * Starts this browser's draft reservation, or changes its e-mail (held seats are kept).
	 * One e-mail may have any number of reservations. Other reservations are never looked up
	 * by e-mail, so the answer reveals nothing about them and nobody can take over a draft
	 * of another browser.
	 */
	public function start(string $owner, string $email): void
	{
		$this->assertSaleOpen();
		$email = mb_strtolower(trim($email));
		if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ReservationError('Zadejte platný e-mail.');
		}

		$this->transaction(function () use ($owner, $email): void {
			$current = $this->currentDraft($owner, lock: true);
			if ($current !== null) {
				$this->db->prepare('UPDATE reservations SET email = ? WHERE id = ?')
					->execute([$email, $current['id']]);
				return;
			}
			// Who may buy in the current stage is checked by the entry point (VisitorGate);
			// the stage decides the channel (test / vip / public).
			$this->db->prepare('INSERT INTO reservations (email, session_id, channel) VALUES (?, ?, ?)')
				->execute([$email, $owner, $this->settings->mode()->channel()]);
		});
	}


	/** Temporarily holds a seat for the current draft. */
	public function hold(string $owner, int $seatId): void
	{
		$this->assertSaleOpen();
		$this->transaction(function () use ($owner, $seatId): void {
			$draft = $this->requireDraft($owner);
			$draftId = (int) $draft['id'];
			$held = $this->heldSeatIds($draftId);
			if (in_array($seatId, $held, true)) {
				return;
			}

			$max = $this->settings->int('max_ticket', 10);
			if (count($held) + (int) $draft['standing_tickets'] + 1 > $max) {
				throw new ReservationError("Na jednu rezervaci lze koupit nejvýše $max lístků.");
			}

			$stmt = $this->db->prepare(
				"UPDATE seats SET state = 'book', reservation_id = ?, booked_at = NOW()
				WHERE id = ? AND state = 'free'",
			);
			$stmt->execute([$draftId, $seatId]);
			if ($stmt->rowCount() === 0) {
				throw new ReservationError('Toto místo už je obsazené.');
			}
			$this->refreshHolds($draftId);
		});
	}


	public function release(string $owner, int $seatId): void
	{
		$this->transaction(function () use ($owner, $seatId): void {
			$draftId = (int) $this->requireDraft($owner)['id'];
			$this->db->prepare(
				"UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
				WHERE id = ? AND reservation_id = ? AND state = 'book'",
			)->execute([$seatId, $draftId]);
			$this->refreshHolds($draftId);
		});
	}


	/** Sets the number of tickets without a seat. */
	public function setStanding(string $owner, int $count): void
	{
		$this->assertSaleOpen();
		$this->transaction(function () use ($owner, $count): void {
			$draft = $this->requireDraft($owner);
			$draftId = (int) $draft['id'];
			$current = (int) $draft['standing_tickets'];
			$max = $this->settings->int('max_ticket', 10);

			if ($count < 0) {
				throw new ReservationError('Neplatný počet lístků.');
			}
			if ($count > $current && $count + count($this->heldSeatIds($draftId)) > $max) {
				throw new ReservationError("Na jednu rezervaci lze koupit nejvýše $max lístků.");
			}
			if ($count > $current && $count > $this->standingLeft()) {
				throw new ReservationError('Lístky bez místenky jsou vyprodané.');
			}

			$this->db->prepare('UPDATE reservations SET standing_tickets = ? WHERE id = ?')
				->execute([$count, $draftId]);
			$this->refreshHolds($draftId);
		});
	}


	/** Turns the draft into a binding reservation. Returns the reservation id. */
	public function confirm(string $owner, string $name, string $phone, bool $consent): int
	{
		$this->assertSaleOpen();
		$name = trim((string) preg_replace('/\s+/u', ' ', $name));
		$phone = trim($phone);
		if (mb_strlen($name) < 3 || mb_strlen($name) > 255) {
			throw new ReservationError('Vyplňte jméno a příjmení.');
		}
		if ($phone !== '' && !preg_match('/^\+?[0-9 ]{9,20}$/', $phone)) {
			throw new ReservationError('Telefon zadejte jen jako čísla, např. +420 777 123 456.');
		}
		if (!$consent) {
			throw new ReservationError('Pro rezervaci je potřeba souhlas se zpracováním osobních údajů.');
		}

		return $this->transaction(function () use ($owner, $name, $phone): int {
			// Serializes confirmations so the standing capacity cannot be oversold.
			$this->db->query("SELECT value FROM settings WHERE name = 'standing_capacity' FOR UPDATE")->fetch();

			$draft = $this->requireDraft($owner);
			$draftId = (int) $draft['id'];
			$seats = count($this->heldSeatIds($draftId));
			$standing = (int) $draft['standing_tickets'];

			if ($seats + $standing === 0) {
				throw new ReservationError('Vyberte alespoň jeden lístek. Pokud jste místa vybrali dříve, mohla vypršet.');
			}
			if ($standing > $this->standingLeft()) {
				throw new ReservationError('Lístky bez místenky jsou vyprodané.');
			}

			$total = $seats * $this->settings->int('price_seat') + $standing * $this->settings->int('price_standing');
			$this->db->prepare(
				"UPDATE seats SET state = 'reserved', booked_at = NULL WHERE reservation_id = ? AND state = 'book'",
			)->execute([$draftId]);
			$this->db->prepare(
				"UPDATE reservations
				SET status = 'confirmed', name = ?, phone = ?, total_price = ?, confirmed_at = NOW(), session_id = NULL
				WHERE id = ?",
			)->execute([$name, $phone === '' ? null : $phone, $total, $draftId]);

			$this->eventLog->record('reservation.confirmed', $draftId, [
				'name' => $name,
				'email' => $draft['email'],
				'tickets' => $seats + $standing,
				'standing' => $standing,
				'total' => $total,
				'channel' => $draft['channel'] ?? 'public',
			]);
			return $draftId;
		});
	}


	/** Gives up the current draft and frees its seats. */
	public function cancel(string $owner): void
	{
		$this->transaction(function () use ($owner): void {
			$draft = $this->currentDraft($owner, lock: true);
			if ($draft !== null) {
				$this->abandonDraft((int) $draft['id']);
			}
		});
	}


	/** Stores whether the confirmation e-mail went out ($error = null means sent). */
	public function recordEmailResult(int $reservationId, ?string $error): void
	{
		$this->db->prepare(
			'UPDATE reservations SET email_sent_at = IF(? IS NULL, NOW(), email_sent_at), email_error = ? WHERE id = ?',
		)->execute([$error, $error === null ? null : mb_substr($error, 0, 1000), $reservationId]);
	}


	// --- Internals -----------------------------------------------------------

	private function holdSeconds(): int
	{
		return $this->settings->int('hold_seconds', 120);
	}


	private function assertSaleOpen(): void
	{
		if (!$this->settings->mode()->isSelling()) {
			throw new ReservationError('Prodej lístků je ukončený.');
		}
	}


	/** @return array<string, mixed>|null */
	private function currentDraft(string $owner, bool $lock = false): ?array
	{
		$stmt = $this->db->prepare(
			"SELECT * FROM reservations WHERE session_id = ? AND status = 'draft' LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
		);
		$stmt->execute([$owner]);
		return $stmt->fetch() ?: null;
	}


	/**
	 * Must be called inside a transaction; locks the draft row to serialize changes.
	 * @return array<string, mixed>
	 */
	private function requireDraft(string $owner): array
	{
		return $this->currentDraft($owner, lock: true)
			?? throw new ReservationError('Nejdřív zadejte svůj e-mail.');
	}


	private function abandonDraft(int $draftId): void
	{
		$this->releaseHolds($draftId);
		$this->db->prepare('UPDATE reservations SET session_id = NULL, standing_tickets = 0 WHERE id = ?')
			->execute([$draftId]);
	}


	private function releaseHolds(int $reservationId): void
	{
		$this->db->prepare(
			"UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
			WHERE reservation_id = ? AND state = 'book'",
		)->execute([$reservationId]);
	}


	/** Any change restarts the hold timer for all seats of the reservation. */
	private function refreshHolds(int $reservationId): void
	{
		$this->db->prepare("UPDATE seats SET booked_at = NOW() WHERE reservation_id = ? AND state = 'book'")
			->execute([$reservationId]);
	}


	/** @return list<int> */
	private function heldSeatIds(int $reservationId): array
	{
		$stmt = $this->db->prepare("SELECT id FROM seats WHERE reservation_id = ? AND state = 'book' FOR UPDATE");
		$stmt->execute([$reservationId]);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}


	private function holdExpiresIn(int $reservationId): ?int
	{
		$stmt = $this->db->prepare(
			"SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(booked_at) + INTERVAL ? SECOND)
			FROM seats WHERE reservation_id = ? AND state = 'book'",
		);
		$stmt->execute([$this->holdSeconds(), $reservationId]);
		$value = $stmt->fetchColumn();
		return $value === null ? null : max(0, (int) $value);
	}


	private function standingLeft(): int
	{
		$sold = (int) $this->db->query(
			'SELECT COALESCE(SUM(standing_tickets), 0) FROM reservations WHERE status IN (' . self::FinishedStatuses . ')',
		)->fetchColumn();
		return max(0, $this->settings->int('standing_capacity') - $sold);
	}


	/**
	 * @param list<int> $seatIds
	 * @return list<array{id: int, label: string}>
	 */
	private function seatLabels(array $seatIds): array
	{
		if (!$seatIds) {
			return [];
		}
		$placeholders = implode(',', array_fill(0, count($seatIds), '?'));
		$stmt = $this->db->prepare("SELECT id, label FROM seats WHERE id IN ($placeholders) ORDER BY id");
		$stmt->execute($seatIds);
		return array_map(
			static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label']],
			$stmt->fetchAll(),
		);
	}


	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function transaction(callable $work): mixed
	{
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
