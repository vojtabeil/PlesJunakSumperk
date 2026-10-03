<?php

declare(strict_types=1);

namespace App\Model\Reservation;

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


	/**
	 * Finished and cancelled reservations (drafts only when asked for), newest first.
	 * @return list<array<string, mixed>>
	 */
	public function search(?string $status = null, string $query = ''): array
	{
		$where = ["r.status <> 'draft'"];
		$params = [];
		if ($status !== null && in_array($status, self::Statuses, true)) {
			$where = ['r.status = ?'];
			$params[] = $status;
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
		$stmt = $this->db->prepare(
			"UPDATE reservations SET status = 'paid', paid_at = NOW(), paid_amount = total_price
			WHERE id = ? AND status IN ('confirmed', 'partially_paid')",
		);
		$stmt->execute([$id]);
		if ($stmt->rowCount() === 0) {
			throw new ReservationError('Zaplacenou lze označit jen potvrzenou nezaplacenou rezervaci.');
		}
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
			$this->db->prepare(
				"UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL WHERE reservation_id = ?",
			)->execute([$id]);
			$this->db->commit();
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
			WHERE r.status IN (" . ReservationService::FinishedStatuses . ")
			GROUP BY r.id
			ORDER BY r.name, r.id",
		)->fetchAll();
	}
}
