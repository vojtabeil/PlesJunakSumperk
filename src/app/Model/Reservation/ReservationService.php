<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use App\Model\Payment\VariableSymbol;
use App\Model\Settings\Settings;
use App\Model\Ticket\Tickets;
use PDO;
use Throwable;


/**
 * Ticket reservation rules. All limits are enforced here, never only in the browser.
 * A draft reservation belongs to an "owner" (random key of one browser, see ReservationSession).
 * See doc/legacy/original-backend.md for how this maps to the original site.
 */
final class ReservationService
{
	/** Reservations that hold their seats and tickets (as an SQL list). */
	public const FinishedStatuses = "'confirmed', 'partially_paid', 'paid'";

	/** MySQL / MariaDB error code of a seat held by somebody else. */
	private const DuplicateKey = 1062;

	public function __construct(
		private readonly PDO $db,
		private readonly Settings $settings,
		private readonly VariableSymbol $variableSymbol,
		private readonly EventLog $eventLog,
		private readonly Tickets $tickets,
	) {
	}


	// --- Read models ---------------------------------------------------------

	/**
	 * Tables and seats of the hall plan. Shapes and positions are only in the SVG (HallSync::plan());
	 * the seat picker finds a seat's shape by its svg_id and hides the tables out of sale (available false),
	 * whose seats are not listed.
	 * @return array{tables: list<array{id: int, label: string, name: ?string, available: bool}>, seats: list<array{id: int, label: string, table_id: ?int, svg_id: string}>}
	 */
	public function hallLayout(): array
	{
		// query() uses the text protocol, so numeric columns arrive as strings.
		return [
			'tables' => array_map(
				static fn(array $row): array => [
					'id' => (int) $row['id'],
					'label' => (string) $row['label'],
					'name' => $row['name'],
					'available' => (bool) $row['available'],
				],
				$this->db->query('SELECT id, label, name, available FROM hall_tables WHERE active = 1 ORDER BY sort_order, id')->fetchAll(),
			),
			'seats' => array_map(
				static fn(array $row): array => [
					'id' => (int) $row['id'],
					'label' => (string) $row['label'],
					'table_id' => $row['table_id'] !== null ? (int) $row['table_id'] : null,
					'svg_id' => (string) $row['svg_id'],
				],
				$this->db->query(
					'SELECT s.id, s.label, s.table_id, s.svg_id FROM seats s LEFT JOIN hall_tables t ON t.id = s.table_id
					WHERE s.active = 1 AND COALESCE(t.available, 1) = 1 ORDER BY s.sort_order, s.id',
				)->fetchAll(),
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
		// Only seats of tables for sale: the picker shows no others and counts the free ones from these.
		$bookings = $this->db->query(
			'SELECT b.seat_id, b.reservation_id FROM seat_bookings b
			JOIN seats s ON s.id = b.seat_id LEFT JOIN hall_tables t ON t.id = s.table_id
			WHERE COALESCE(t.available, 1) = 1 ORDER BY b.seat_id',
		)->fetchAll();
		foreach ($bookings as $row) {
			if ($draftId && (int) $row['reservation_id'] === $draftId) {
				$mine[] = (int) $row['seat_id'];
			} else {
				$taken[] = (int) $row['seat_id'];
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
	 * A finished (confirmed or paid) reservation with its seat labels, the amount still to pay
	 * (remaining) and the due date (due_on).
	 * @return array<string, mixed>|null
	 */
	public function findFinished(int $id): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM reservations WHERE id = ? AND status IN (' . self::FinishedStatuses . ')');
		$stmt->execute([$id]);
		$reservation = $stmt->fetch();
		return $reservation ? $this->complete($reservation) : null;
	}


	/**
	 * The reservation behind the link "Moje rezervace" (also a cancelled one), or null for a wrong token.
	 * @return array<string, mixed>|null
	 */
	public function findByToken(int $id, string $token): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM reservations WHERE id = ? AND status <> 'draft'");
		$stmt->execute([$id]);
		$reservation = $stmt->fetch();
		return $reservation && $reservation['access_token'] !== null && hash_equals((string) $reservation['access_token'], $token)
			? $this->complete($reservation)
			: null;
	}


	/** Last day to pay a reservation confirmed at the given time. */
	public function dueDate(\DateTimeInterface $confirmedAt): \DateTimeImmutable
	{
		return \DateTimeImmutable::createFromInterface($confirmedAt)
			->setTime(0, 0)
			->modify('+' . $this->settings->int('payment_days', 2) . ' days');
	}


	/**
	 * @param array<string, mixed> $reservation
	 * @return array<string, mixed>
	 */
	private function complete(array $reservation): array
	{
		$id = (int) $reservation['id'];
		$reservation['seat_labels'] = $this->reservationSeatLabels($id);
		$reservation['variable_symbol'] = $this->variableSymbol->forReservation($id);
		$reservation['remaining'] = max(0, (int) $reservation['total_price'] - (int) $reservation['paid_amount']);
		$reservation['due_on'] = $reservation['confirmed_at'] !== null
			? $this->dueDate(new \DateTimeImmutable((string) $reservation['confirmed_at']))
			: null;
		return $reservation;
	}


	// --- Commands ------------------------------------------------------------

	/** Frees seats whose temporary hold has run out. Called at the start of every request. */
	public function releaseExpiredHolds(): void
	{
		$this->db->prepare(
			"DELETE FROM seat_bookings WHERE state = 'held' AND held_at < NOW() - INTERVAL ? SECOND",
		)->execute([$this->holdSeconds()]);
	}


	/**
	 * Sets the e-mail of this browser's draft (creates the draft when there is none).
	 * One e-mail may have any number of reservations. Other reservations are never looked up
	 * by e-mail, so the answer reveals nothing about them and nobody can take over a draft
	 * of another browser.
	 */
	public function setEmail(string $owner, string $email): void
	{
		$this->assertSaleOpen();
		$email = self::validEmail($email);
		$this->transaction(function () use ($owner, $email): void {
			$draft = $this->draft($owner);
			$this->db->prepare('UPDATE reservations SET email = ? WHERE id = ?')->execute([$email, $draft['id']]);
		});
	}


	/** Temporarily holds a seat; the first chosen ticket starts the draft of this browser. */
	public function hold(string $owner, int $seatId): void
	{
		$this->assertSaleOpen();
		$this->transaction(function () use ($owner, $seatId): void {
			$draft = $this->draft($owner);
			$draftId = (int) $draft['id'];
			$held = $this->heldSeatIds($draftId);
			if (in_array($seatId, $held, true)) {
				return;
			}

			$max = $this->settings->int('max_ticket', 10);
			if (count($held) + (int) $draft['standing_tickets'] + 1 > $max) {
				throw new ReservationError("Na jednu rezervaci lze koupit nejvýše $max lístků.");
			}

			$table = $this->db->prepare(
				'SELECT COALESCE(t.available, 1) FROM seats s LEFT JOIN hall_tables t ON t.id = s.table_id WHERE s.id = ? AND s.active = 1',
			);
			$table->execute([$seatId]);
			$available = $table->fetchColumn();
			if ($available !== false && (int) $available === 0) {
				throw new ReservationError('Toto místo není v prodeji.');
			}

			// The primary key on seat_id lets only one reservation have the seat (a duplicate does not
			// abort the transaction); a seat not in the hall plan or at a table out of sale inserts nothing.
			try {
				$stmt = $this->db->prepare(
					"INSERT INTO seat_bookings (seat_id, reservation_id, state, held_at)
					SELECT s.id, ?, 'held', NOW() FROM seats s LEFT JOIN hall_tables t ON t.id = s.table_id
					WHERE s.id = ? AND s.active = 1 AND COALESCE(t.available, 1) = 1",
				);
				$stmt->execute([$draftId, $seatId]);
			} catch (\PDOException $e) {
				if (($e->errorInfo[1] ?? null) === self::DuplicateKey) {
					throw new ReservationError('Toto místo už je obsazené.');
				}
				throw $e;
			}
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
				"DELETE FROM seat_bookings WHERE seat_id = ? AND reservation_id = ? AND state = 'held'",
			)->execute([$seatId, $draftId]);
			$this->refreshHolds($draftId);
		});
	}


	/** Sets the number of tickets without a seat. */
	public function setStanding(string $owner, int $count): void
	{
		$this->assertSaleOpen();
		$this->transaction(function () use ($owner, $count): void {
			$draft = $this->draft($owner);
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


	/** The visitor is still filling in the form: the chosen seats are held for another hold period. */
	public function extend(string $owner): void
	{
		$this->assertSaleOpen();
		$this->transaction(function () use ($owner): void {
			$draft = $this->currentDraft($owner, lock: true);
			if ($draft !== null) {
				$this->refreshHolds((int) $draft['id']);
			}
		});
	}


	/**
	 * Turns the draft into a binding reservation. Returns the reservation id.
	 * @param string|null $email null = keep the e-mail set before (setEmail)
	 */
	/**
	 * Finishes the draft of this browser. $trap is the hidden field of the form: a person leaves it empty.
	 */
	public function confirm(string $owner, string $name, string $phone, bool $consent, ?string $email = null, string $trap = ''): int
	{
		$this->assertSaleOpen();
		$this->assertNotBot($owner, $trap);
		$email = $email !== null ? self::validEmail($email) : null;
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

		return $this->transaction(function () use ($owner, $name, $phone, $email): int {
			// Serializes confirmations so the standing capacity cannot be oversold.
			$this->db->query("SELECT value FROM settings WHERE name = 'standing_capacity' FOR UPDATE")->fetch();

			$draft = $this->requireDraft($owner);
			$draftId = (int) $draft['id'];
			$email ??= $draft['email'] ?? throw new ReservationError('Zadejte svůj e-mail.');
			$seats = count($this->heldSeatIds($draftId));
			$standing = (int) $draft['standing_tickets'];

			if ($seats + $standing === 0) {
				throw new ReservationError('Vyberte alespoň jeden lístek. Pokud jste místa vybrali dříve, mohla vypršet.');
			}
			if ($standing > $this->standingLeft()) {
				throw new ReservationError('Lístky bez místenky jsou vyprodané.');
			}

			$total = $seats * $this->settings->int('price_seat') + $standing * $this->settings->int('price_standing');
			// The stage at confirmation decides the channel: a draft started by a tester and confirmed
			// after the launch is a real reservation (it must not be deleted with the test ones).
			$channel = $this->settings->mode()->channel();
			$this->db->prepare(
				"UPDATE seat_bookings SET state = 'reserved', held_at = NULL WHERE reservation_id = ? AND state = 'held'",
			)->execute([$draftId]);
			$this->db->prepare(
				"UPDATE reservations
				SET status = 'confirmed', email = ?, name = ?, phone = ?, total_price = ?, channel = ?,
					access_token = ?, confirmed_at = NOW(), session_id = NULL
				WHERE id = ?",
			)->execute([$email, $name, $phone === '' ? null : $phone, $total, $channel, bin2hex(random_bytes(16)), $draftId]);
			$this->tickets->issue($draftId);

			$this->eventLog->record('reservation.confirmed', $draftId, [
				'name' => $name,
				'email' => $email,
				'tickets' => $seats + $standing,
				'standing' => $standing,
				'total' => $total,
				'channel' => $channel,
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


	/**
	 * Simple protection against scripts that confirm reservations: the hidden field of the form must stay empty
	 * (a person does not see it, a simple robot fills every field) and the order must not be confirmed sooner than
	 * the setting bot_min_seconds after the first ticket was chosen (0 = off). Logged, the message asks to try again.
	 */
	private function assertNotBot(string $owner, string $trap): void
	{
		$reason = null;
		$seconds = null;
		if (trim($trap) !== '') {
			$reason = 'trap';
		} else {
			$minSeconds = $this->settings->int('bot_min_seconds', 0);
			$draft = $minSeconds > 0 ? $this->currentDraft($owner) : null;
			if ($draft !== null) {
				$stmt = $this->db->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM reservations WHERE id = ?');
				$stmt->execute([(int) $draft['id']]);
				$seconds = (int) $stmt->fetchColumn();
				$reason = $seconds < $minSeconds ? 'fast' : null;
			}
		}
		if ($reason !== null) {
			$this->eventLog->record('reservation.bot_blocked', null, array_filter(['reason' => $reason, 'seconds' => $seconds], static fn($v): bool => $v !== null));
			throw new ReservationError('Rezervaci se nepodařilo dokončit. Zkontrolujte prosím údaje a odešlete ji znovu.');
		}
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
			?? throw new ReservationError('Nejdřív vyberte místa nebo lístky bez místenky.');
	}


	/**
	 * The draft of this browser, created when there is none (inside a transaction, locked).
	 * Who may buy in the current stage is checked by the entry point (VisitorGate).
	 * @return array<string, mixed>
	 */
	private function draft(string $owner): array
	{
		$draft = $this->currentDraft($owner, lock: true);
		if ($draft !== null) {
			return $draft;
		}
		$this->db->prepare('INSERT INTO reservations (session_id, channel) VALUES (?, ?)')
			->execute([$owner, $this->settings->mode()->channel()]);
		return $this->currentDraft($owner, lock: true) ?? throw new \LogicException('Draft not created.');
	}


	private static function validEmail(string $email): string
	{
		$email = mb_strtolower(trim($email));
		if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ReservationError('Zadejte e-mail ve tvaru jmeno@example.cz.');
		}
		return $email;
	}


	private function abandonDraft(int $draftId): void
	{
		$this->releaseHolds($draftId);
		$this->db->prepare('UPDATE reservations SET session_id = NULL, standing_tickets = 0 WHERE id = ?')
			->execute([$draftId]);
	}


	private function releaseHolds(int $reservationId): void
	{
		$this->db->prepare("DELETE FROM seat_bookings WHERE reservation_id = ? AND state = 'held'")
			->execute([$reservationId]);
	}


	/** Any change restarts the hold timer for all seats of the reservation. */
	private function refreshHolds(int $reservationId): void
	{
		$this->db->prepare("UPDATE seat_bookings SET held_at = NOW() WHERE reservation_id = ? AND state = 'held'")
			->execute([$reservationId]);
	}


	/** @return list<int> */
	private function heldSeatIds(int $reservationId): array
	{
		$stmt = $this->db->prepare("SELECT seat_id FROM seat_bookings WHERE reservation_id = ? AND state = 'held' FOR UPDATE");
		$stmt->execute([$reservationId]);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}


	/** @return list<string> labels of all seats of a reservation (held or reserved) */
	private function reservationSeatLabels(int $reservationId): array
	{
		$stmt = $this->db->prepare(
			'SELECT s.label FROM seat_bookings b JOIN seats s ON s.id = b.seat_id WHERE b.reservation_id = ? ORDER BY s.sort_order, s.id',
		);
		$stmt->execute([$reservationId]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}


	private function holdExpiresIn(int $reservationId): ?int
	{
		$stmt = $this->db->prepare(
			"SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(held_at) + INTERVAL ? SECOND)
			FROM seat_bookings WHERE reservation_id = ? AND state = 'held'",
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
		$stmt = $this->db->prepare("SELECT id, label FROM seats WHERE id IN ($placeholders) ORDER BY sort_order, id");
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
