<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use App\Model\Payment\VariableSymbol;
use PDO;
use Throwable;


/** Reservation management for organizers (overview, search, payments by hand, cancelling). */
final class ReservationAdmin
{
	public const Statuses = ['draft', 'confirmed', 'partially_paid', 'paid', 'cancelled'];


	public function __construct(
		private readonly PDO $db,
		private readonly Settings $settings,
		private readonly VariableSymbol $variableSymbol,
		private readonly EventLog $eventLog,
	) {
	}


	/** @return array<string, int> */
	public function stats(): array
	{
		$seats = $this->db->query(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(state = 'reserved'), 0) AS reserved,
				COALESCE(SUM(state = 'book'), 0) AS held
			FROM seats",
		)->fetch();
		$reservations = $this->db->query(
			"SELECT
				COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
				COALESCE(SUM(status = 'partially_paid'), 0) AS partially_paid,
				COALESCE(SUM(status = 'paid'), 0) AS paid,
				COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
				COALESCE(SUM(IF(status IN (" . ReservationService::FinishedStatuses . "), standing_tickets, 0)), 0) AS standing,
				COALESCE(SUM(IF(status IN ('confirmed', 'partially_paid'), total_price - paid_amount, 0)), 0) AS unpaid_amount,
				COALESCE(SUM(IF(status IN (" . ReservationService::FinishedStatuses . "), paid_amount, 0)), 0) AS paid_amount
			FROM reservations",
		)->fetch();

		$stats = array_map('intval', $seats + $reservations);
		$stats['free'] = $stats['total'] - $stats['reserved'] - $stats['held'];
		$stats['standing_capacity'] = $this->settings->int('standing_capacity');
		return $stats;
	}


	/** @return array{test: int, vip: int, public: int} tickets of finished reservations by channel */
	public function ticketsByChannel(): array
	{
		$result = ['test' => 0, 'vip' => 0, 'public' => 0];
		$rows = $this->db->query(
			"SELECT r.channel, COALESCE(SUM(r.standing_tickets), 0) + (SELECT COUNT(*) FROM seats s JOIN reservations x ON x.id = s.reservation_id
				WHERE x.channel = r.channel AND x.status IN (" . ReservationService::FinishedStatuses . ")) AS tickets
			FROM reservations r WHERE r.status IN (" . ReservationService::FinishedStatuses . ") GROUP BY r.channel",
		)->fetchAll();
		foreach ($rows as $row) {
			$result[(string) $row['channel']] = (int) $row['tickets'];
		}
		return $result;
	}


	/** Problem filters of the reservation list (key => Czech label). */
	public const Problems = [
		'email' => 'Neodeslaný potvrzovací e-mail',
		'overdue' => 'Nezaplacené déle než ' . self::OverdueDays . ' dní',
	];

	public const OverdueDays = 7;


	/**
	 * Finished and cancelled reservations (drafts only when asked for), newest first.
	 * @return list<array<string, mixed>>
	 */
	public function search(?string $status = null, string $query = '', ?string $problem = null): array
	{
		$where = ["r.status <> 'draft'"];
		$params = [];
		if ($status !== null && in_array($status, self::Statuses, true)) {
			$where = ['r.status = ?'];
			$params[] = $status;
		}
		if ($problem !== null && isset(self::Problems[$problem])) {
			$where[] = self::problemCondition($problem);
		}
		$query = trim($query);
		if ($query !== '') {
			$where[] = '(r.email LIKE ? OR r.name LIKE ? OR r.id = ?)';
			$like = '%' . addcslashes($query, '%_\\') . '%';
			array_push($params, $like, $like, ctype_digit($query) ? (int) $query : 0);
		}

		$stmt = $this->db->prepare(
			'SELECT r.*, GROUP_CONCAT(s.label ORDER BY s.id SEPARATOR \', \') AS seat_labels, COUNT(s.id) AS seat_count
			FROM reservations r LEFT JOIN seats s ON s.reservation_id = r.id
			WHERE ' . implode(' AND ', $where) . '
			GROUP BY r.id
			ORDER BY r.id DESC',
		);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}


	/** @return array<string, int> problem => number of reservations (for "Co je potřeba udělat") */
	public function problemCounts(): array
	{
		$counts = [];
		foreach (array_keys(self::Problems) as $problem) {
			$counts[$problem] = (int) $this->db->query(
				'SELECT COUNT(*) FROM reservations r WHERE ' . self::problemCondition($problem),
			)->fetchColumn();
		}
		return $counts;
	}


	/**
	 * Customers = people grouped by e-mail (one e-mail may have several reservations).
	 * @return list<array{email: string, names: string, reservations: list<array<string, mixed>>,
	 *     tickets: int, total: int, paid: int}>
	 */
	public function customers(string $query = ''): array
	{
		$customers = [];
		foreach ($this->search(null, $query) as $r) {
			$email = (string) $r['email'];
			$customers[$email] ??= ['email' => $email, 'names' => [], 'reservations' => [], 'tickets' => 0, 'total' => 0, 'paid' => 0];
			$c = &$customers[$email];
			$c['names'][(string) $r['name']] = true;
			$c['reservations'][] = $r;
			if ($r['status'] !== 'cancelled') {
				$c['tickets'] += (int) $r['seat_count'] + (int) $r['standing_tickets'];
				$c['total'] += (int) $r['total_price'];
				$c['paid'] += (int) $r['paid_amount'];
			}
			unset($c);
		}
		ksort($customers);
		return array_values(array_map(static function (array $c): array {
			$c['names'] = implode(', ', array_keys($c['names']));
			return $c;
		}, $customers));
	}


	private static function problemCondition(string $problem): string
	{
		return match ($problem) {
			'email' => "r.status IN ('confirmed', 'partially_paid', 'paid') AND r.email_sent_at IS NULL",
			'overdue' => "r.status IN ('confirmed', 'partially_paid') AND r.confirmed_at < NOW() - INTERVAL " . self::OverdueDays . ' DAY',
			default => throw new \InvalidArgumentException("Unknown problem '$problem'."),
		};
	}


	/** @return array<string, mixed>|null reservation with 'seats' (list of labels) */
	public function get(int $id): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM reservations WHERE id = ?');
		$stmt->execute([$id]);
		$reservation = $stmt->fetch();
		if (!$reservation) {
			return null;
		}
		$seats = $this->db->prepare('SELECT label FROM seats WHERE reservation_id = ? ORDER BY id');
		$seats->execute([$id]);
		$reservation['seats'] = $seats->fetchAll(PDO::FETCH_COLUMN);
		$reservation['variable_symbol'] = $this->variableSymbol->forReservation($id);
		return $reservation;
	}


	/** Payment received outside the bank (e.g. cash). */
	public function markPaid(int $id): void
	{
		$reservation = $this->get($id);
		$stmt = $this->db->prepare(
			"UPDATE reservations SET status = 'paid', paid_at = NOW(), paid_amount = total_price
			WHERE id = ? AND status IN ('confirmed', 'partially_paid')",
		);
		$stmt->execute([$id]);
		if ($stmt->rowCount() === 0 || $reservation === null) {
			throw new ReservationError('Zaplacenou lze označit jen potvrzenou nezaplacenou rezervaci.');
		}
		// The rest was paid in cash, so its partial bank payments are no longer a problem.
		$this->db->prepare("UPDATE bank_transactions SET match_status = 'matched' WHERE reservation_id = ? AND match_status = 'underpaid'")
			->execute([$id]);
		$this->eventLog->record('reservation.paid_manually', $id, [
			'amount' => (int) $reservation['total_price'] - (int) $reservation['paid_amount'],
		]);
	}


	/** Cancels a reservation and frees its seats; its standing tickets stop counting as sold. */
	public function cancel(int $id): void
	{
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare(
				"UPDATE reservations SET status = 'cancelled', session_id = NULL WHERE id = ? AND status <> 'cancelled'",
			);
			$stmt->execute([$id]);
			if ($stmt->rowCount() === 0) {
				throw new ReservationError('Rezervace neexistuje nebo už je zrušená.');
			}
			// The log keeps which seats were freed.
			$this->eventLog->record('reservation.cancelled', $id);
			$this->db->prepare(
				"UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL WHERE reservation_id = ?",
			)->execute([$id]);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	public function testCount(): int
	{
		return (int) $this->db->query("SELECT COUNT(*) FROM reservations WHERE channel = 'test'")->fetchColumn();
	}


	/**
	 * Removes all reservations made by testers: their seats become free and their bank payments
	 * are set aside as ignored (a real payment of a tester has to be refunded by hand).
	 * @return int number of deleted reservations
	 */
	public function deleteTestReservations(): int
	{
		$this->db->beginTransaction();
		try {
			$rows = $this->db->query("SELECT id, name, email FROM reservations WHERE channel = 'test' ORDER BY id FOR UPDATE")->fetchAll();
			foreach ($rows as $row) {
				$id = (int) $row['id'];
				// Logged first, so the event keeps the freed seats.
				$this->eventLog->record('reservation.test_deleted', $id, ['name' => $row['name'], 'email' => $row['email']]);
				$this->db->prepare("UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL WHERE reservation_id = ?")
					->execute([$id]);
				$this->db->prepare("UPDATE bank_transactions SET reservation_id = NULL, match_status = 'ignored' WHERE reservation_id = ?")
					->execute([$id]);
				$this->db->prepare('DELETE FROM reservations WHERE id = ?')->execute([$id]);
			}
			$this->db->commit();
			return count($rows);
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	public function saveNote(int $id, string $note): void
	{
		$note = trim($note);
		$this->db->prepare('UPDATE reservations SET note = ? WHERE id = ?')
			->execute([$note === '' ? null : mb_substr($note, 0, 1000), $id]);
		$this->eventLog->record('reservation.note', $id, ['note' => mb_substr($note, 0, 200)]);
	}


	/**
	 * Guests for the entrance: confirmed and paid reservations ordered by name.
	 * @return list<array<string, mixed>>
	 */
	public function guestList(): array
	{
		return $this->db->query(
			"SELECT r.id, r.name, r.email, r.phone, r.status, r.standing_tickets, r.total_price, r.paid_amount, r.paid_at, r.note,
				GROUP_CONCAT(s.label ORDER BY s.id SEPARATOR ', ') AS seat_labels, COUNT(s.id) AS seat_count
			FROM reservations r LEFT JOIN seats s ON s.reservation_id = r.id
			WHERE r.status IN (" . ReservationService::FinishedStatuses . ") AND r.channel <> 'test'
			GROUP BY r.id
			ORDER BY r.name, r.id",
		)->fetchAll();
	}
}
