<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use App\Model\Reservation\ReservationService;
use PDO;


/**
 * Tickets: one per seat and one per standing ticket of a reservation, and one Z-lístek per crew
 * member (crew_member_id instead of reservation_id). The code (6 random hex characters) is checked
 * at the entrance; the public print link also needs the secret print token.
 */
final class Tickets
{
	public const CodeLength = 6;

	/** MySQL / MariaDB error code of a duplicate key. */
	private const DuplicateKey = 1062;

	private const Columns = 't.id, t.reservation_id, t.crew_member_id, t.seat_id, t.code, t.print_token, t.created_at, t.used_at, t.used_by,
		s.label AS seat_label, a.name AS used_by_name, c.name AS crew_name, c.role AS crew_role';

	private const Joins = 'LEFT JOIN seats s ON s.id = t.seat_id LEFT JOIN admin_users a ON a.id = t.used_by
		LEFT JOIN crew_members c ON c.id = t.crew_member_id';


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/**
	 * Creates the tickets of a confirmed reservation: one per reserved seat and one per standing
	 * ticket. Called inside the confirmation transaction.
	 */
	public function issue(int $reservationId): void
	{
		$stmt = $this->db->prepare(
			"SELECT b.seat_id FROM seat_bookings b JOIN seats s ON s.id = b.seat_id WHERE b.reservation_id = ? AND b.state = 'reserved' ORDER BY s.sort_order, s.id",
		);
		$stmt->execute([$reservationId]);
		$seats = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

		$stmt = $this->db->prepare('SELECT standing_tickets FROM reservations WHERE id = ?');
		$stmt->execute([$reservationId]);
		$standing = (int) $stmt->fetchColumn();

		foreach ([...$seats, ...array_fill(0, $standing, null)] as $seatId) {
			$this->insert($reservationId, null, $seatId);
		}
	}


	/** Creates the Z-lístek of a new crew member (inside the transaction that creates the member). */
	public function issueCrew(int $crewMemberId): void
	{
		$this->insert(null, $crewMemberId, null);
	}


	/** @return array<string, mixed>|null the Z-lístek of a crew member */
	public function forCrewMember(int $crewMemberId): ?array
	{
		$stmt = $this->db->prepare('SELECT ' . self::Columns . ' FROM tickets t ' . self::Joins . ' WHERE t.crew_member_id = ?');
		$stmt->execute([$crewMemberId]);
		return $stmt->fetch() ?: null;
	}


	/**
	 * Tickets of a reservation, seats first (by seat), then standing tickets.
	 * @return list<array<string, mixed>>
	 */
	public function forReservation(int $reservationId): array
	{
		$stmt = $this->db->prepare(
			'SELECT ' . self::Columns . ' FROM tickets t ' . self::Joins . '
			WHERE t.reservation_id = ? ORDER BY t.seat_id IS NULL, t.seat_id, t.id',
		);
		$stmt->execute([$reservationId]);
		return $stmt->fetchAll();
	}


	/**
	 * All valid tickets of one kind for the lists on the dashboard: seat or standing (tickets of finished
	 * reservations with reservation id, name, status and channel) or crew (Z-lístky with name, role, pick-up).
	 * @param 'seat'|'standing'|'crew' $kind
	 * @return list<array<string, mixed>>
	 */
	public function listing(string $kind): array
	{
		$sql = match ($kind) {
			'seat' => 'SELECT t.code, t.used_at, s.label AS seat_label, r.id AS reservation_id, r.name, r.status, r.channel
				FROM tickets t JOIN reservations r ON r.id = t.reservation_id JOIN seats s ON s.id = t.seat_id
				WHERE r.status IN (' . ReservationService::FinishedStatuses . ') ORDER BY s.sort_order, s.id',
			'standing' => 'SELECT t.code, t.used_at, r.id AS reservation_id, r.name, r.status, r.channel
				FROM tickets t JOIN reservations r ON r.id = t.reservation_id
				WHERE t.seat_id IS NULL AND r.status IN (' . ReservationService::FinishedStatuses . ') ORDER BY r.id, t.id',
			'crew' => 'SELECT t.code, t.used_at, c.id AS crew_member_id, c.name, c.role, c.picked_up_at
				FROM tickets t JOIN crew_members c ON c.id = t.crew_member_id ORDER BY c.role, c.name, c.id',
		};
		return $this->db->query($sql)->fetchAll();
	}


	/** @return array<string, mixed>|null */
	public function findByCode(string $code): ?array
	{
		$code = self::normalizeCode($code);
		if ($code === null) {
			return null;
		}
		$stmt = $this->db->prepare('SELECT ' . self::Columns . ' FROM tickets t ' . self::Joins . ' WHERE t.code = ?');
		$stmt->execute([$code]);
		return $stmt->fetch() ?: null;
	}


	/**
	 * The ticket behind a public print link with its reservation (reservation_name, reservation_status,
	 * remaining in CZK; a Z-lístek has crew_name and crew_role instead), or null when the code or the token is wrong.
	 * @return array<string, mixed>|null
	 */
	public function findForPrint(string $code, string $printToken): ?array
	{
		$ticket = $this->findByCode($code);
		if ($ticket === null || !hash_equals((string) $ticket['print_token'], $printToken)) {
			return null;
		}
		if ($ticket['crew_member_id'] !== null) {
			return $ticket;
		}
		$stmt = $this->db->prepare('SELECT name, status, total_price, paid_amount FROM reservations WHERE id = ?');
		$stmt->execute([$ticket['reservation_id']]);
		$reservation = $stmt->fetch();
		return $ticket + [
			'reservation_name' => (string) $reservation['name'],
			'reservation_status' => (string) $reservation['status'],
			'remaining' => max(0, (int) $reservation['total_price'] - (int) round((float) $reservation['paid_amount'])),
		];
	}


	/**
	 * The code as stored (upper case), or null when the input cannot be a code.
	 * Accepts any case, spaces and dashes, and the letter O for zero.
	 */
	public static function normalizeCode(string $input): ?string
	{
		$code = strtr(strtoupper((string) preg_replace('~[\s-]+~', '', $input)), ['O' => '0']);
		return preg_match('~^[0-9A-F]{' . self::CodeLength . '}$~', $code) ? $code : null;
	}


	private function insert(?int $reservationId, ?int $crewMemberId, ?int $seatId): void
	{
		if (($reservationId === null) === ($crewMemberId === null)) {
			throw new \LogicException('A ticket belongs either to a reservation or to a crew member.');
		}
		$stmt = $this->db->prepare(
			'INSERT INTO tickets (reservation_id, crew_member_id, seat_id, code, print_token) VALUES (?, ?, ?, ?, ?)',
		);
		// 16.7 million codes for a few hundred tickets: a collision is rare, a few retries are plenty.
		for ($attempt = 1; ; $attempt++) {
			try {
				$stmt->execute([$reservationId, $crewMemberId, $seatId, self::randomCode(), bin2hex(random_bytes(16))]);
				return;
			} catch (\PDOException $e) {
				if (($e->errorInfo[1] ?? null) !== self::DuplicateKey || $attempt >= 10) {
					throw $e;
				}
			}
		}
	}


	private static function randomCode(): string
	{
		return strtoupper(bin2hex(random_bytes(self::CodeLength / 2)));
	}
}
