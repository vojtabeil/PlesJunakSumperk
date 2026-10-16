<?php

declare(strict_types=1);

namespace App\Model\Hall;

use App\Model\Log\Actor;
use App\Model\Log\EventLog;
use App\Model\Reservation\ReservationAdmin;
use PDO;
use Throwable;


/**
 * The hall plan in the database: the only version of the SVG (hall_plan) and the tables and seats
 * synchronized from it. Replacing the plan cancels the reservations of seats that disappear, so the
 * administration shows preview() first and apply() only goes ahead with the same affected reservations.
 */
final class HallSync
{
	public const CancelReason = 'nový plánek sálu';


	public function __construct(
		private readonly PDO $db,
		private readonly ReservationAdmin $reservationAdmin,
		private readonly EventLog $eventLog,
		private readonly Actor $actor,
	) {
	}


	/** @return array{svg: string, file_name: string, applied_at: string, applied_by: ?string, hash: string}|null */
	public function plan(): ?array
	{
		$row = $this->db->query(
			'SELECT p.svg, p.file_name, p.applied_at, a.name AS applied_by
			FROM hall_plan p LEFT JOIN admin_users a ON a.id = p.applied_by WHERE p.id = 1',
		)->fetch();
		if (!$row) {
			return null;
		}
		return [
			'svg' => (string) $row['svg'],
			'file_name' => (string) $row['file_name'],
			'applied_at' => (string) $row['applied_at'],
			'applied_by' => $row['applied_by'] !== null ? (string) $row['applied_by'] : null,
			'hash' => substr(sha1((string) $row['svg']), 0, 12),
		];
	}


	/** What replacing the plan would change, without changing anything. */
	public function preview(HallPlan $plan): HallChanges
	{
		return $this->changes($plan, lock: false);
	}


	/**
	 * Replaces the plan. $confirmedReservations = the affected reservations the organizer saw in the
	 * preview; when they differ now (somebody bought a removed seat meanwhile), nothing is changed.
	 * @param list<int> $confirmedReservations
	 */
	public function apply(HallPlan $plan, string $fileName, array $confirmedReservations): HallChanges
	{
		if (!$plan->isValid()) {
			throw new HallError('Plánek obsahuje chyby, nelze ho použít.');
		}
		$this->db->beginTransaction();
		try {
			$changes = $this->changes($plan, lock: true);
			$affected = $changes->affectedIds();
			sort($affected);
			sort($confirmedReservations);
			if ($affected !== $confirmedReservations) {
				throw new HallError('Mezitím se změnily rezervace, kterých se nový plánek týká. Zkontrolujte prosím znovu jejich seznam.');
			}

			foreach ($affected as $reservationId) {
				$this->reservationAdmin->cancelInTransaction($reservationId, self::CancelReason);
			}
			if ($changes->removedSeatIds) {
				// Seats being selected right now are only released.
				$this->db->prepare('DELETE FROM seat_bookings WHERE seat_id IN (' . self::placeholders($changes->removedSeatIds) . ')')
					->execute($changes->removedSeatIds);
			}
			$this->storeLayout($plan);
			$this->db->prepare(
				'REPLACE INTO hall_plan (id, svg, file_name, applied_by, applied_at) VALUES (1, ?, ?, ?, NOW())',
			)->execute([$plan->svg, mb_substr($fileName, 0, 255), $this->actor->adminId()]);

			$this->eventLog->record('hall.replaced', null, ['file' => $fileName] + $changes->counts(), $changes->removedSeatIds);
			$this->db->commit();
			return $changes;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	private function changes(HallPlan $plan, bool $lock): HallChanges
	{
		// Tables and seats are matched by their labels ("A1", "A1/5"), the ids stay.
		$seatIds = [];
		foreach ($this->db->query('SELECT id, label FROM seats WHERE active = 1' . ($lock ? ' FOR UPDATE' : ''))->fetchAll() as $row) {
			$seatIds[(string) $row['label']] = (int) $row['id'];
		}
		$tableNames = [];
		foreach ($this->db->query('SELECT label, name FROM hall_tables WHERE active = 1')->fetchAll() as $row) {
			$tableNames[(string) $row['label']] = $row['name'] !== null ? (string) $row['name'] : null;
		}
		$planSeats = array_map('strval', array_keys($plan->seats));
		$planTables = array_map('strval', array_keys($plan->tables));

		$renamed = [];
		foreach ($plan->tables as $label => $table) {
			if (array_key_exists($label, $tableNames) && $tableNames[$label] !== $table['name']) {
				$renamed[(string) $label] = ['from' => $tableNames[$label], 'to' => $table['name']];
			}
		}
		// Labels of an older plan may be numeric ("12/87"), so PHP turns some keys into integers.
		$removedSeats = array_values(array_diff(array_map('strval', array_keys($seatIds)), $planSeats));
		$removedSeatIds = array_map(static fn(string $label): int => $seatIds[$label], $removedSeats);
		[$affected, $held] = $this->affected($removedSeatIds);

		return new HallChanges(
			newSeats: array_values(array_diff($planSeats, array_map('strval', array_keys($seatIds)))),
			removedSeats: $removedSeats,
			removedSeatIds: $removedSeatIds,
			newTables: array_values(array_diff($planTables, array_map('strval', array_keys($tableNames)))),
			removedTables: array_values(array_diff(array_map('strval', array_keys($tableNames)), $planTables)),
			renamedTables: $renamed,
			affected: $affected,
			heldSeats: $held,
		);
	}

	/**
	 * Finished reservations holding a removed seat (they will be cancelled), and the number of removed
	 * seats held by drafts (only released).
	 * @param list<int> $removedSeats ids
	 * @return array{list<array{id: int, name: ?string, email: ?string, phone: ?string, status: string, channel: string, paid: int, seats: list<string>}>, int}
	 */
	private function affected(array $removedSeats): array
	{
		if (!$removedSeats) {
			return [[], 0];
		}
		$stmt = $this->db->prepare(
			'SELECT r.id, r.name, r.email, r.phone, r.status, r.channel, r.paid_amount, s.label, b.state
			FROM seat_bookings b
			JOIN reservations r ON r.id = b.reservation_id
			JOIN seats s ON s.id = b.seat_id
			WHERE b.seat_id IN (' . self::placeholders($removedSeats) . ')
			ORDER BY r.id, s.sort_order, s.id',
		);
		$stmt->execute($removedSeats);

		$affected = [];
		$held = 0;
		foreach ($stmt->fetchAll() as $row) {
			if ($row['status'] === 'draft') {
				$held++;
				continue;
			}
			$id = (int) $row['id'];
			$affected[$id] ??= [
				'id' => $id,
				'name' => $row['name'],
				'email' => $row['email'],
				'phone' => $row['phone'],
				'status' => (string) $row['status'],
				'channel' => (string) $row['channel'],
				'paid' => (int) round((float) $row['paid_amount']),
				'seats' => [],
			];
			$affected[$id]['seats'][] = (string) $row['label'];
		}
		return [array_values($affected), $held];
	}


	/**
	 * Tables and seats of the plan become active (inserted, or updated when the label exists), the others
	 * inactive; sort_order = the order of the plan (natural sort of the labels).
	 */
	private function storeLayout(HallPlan $plan): void
	{
		$this->db->exec('UPDATE hall_tables SET active = 0');
		$table = $this->db->prepare(
			'INSERT INTO hall_tables (label, name, svg_id, sort_order, active) VALUES (?, ?, ?, ?, 1)
			ON DUPLICATE KEY UPDATE name = VALUES(name), svg_id = VALUES(svg_id), sort_order = VALUES(sort_order), active = 1',
		);
		$order = 0;
		foreach ($plan->tables as $label => $t) {
			$table->execute([(string) $label, $t['name'], $t['svg_id'], ++$order]);
		}
		$tableIds = [];
		foreach ($this->db->query('SELECT id, label FROM hall_tables')->fetchAll() as $row) {
			$tableIds[(string) $row['label']] = (int) $row['id'];
		}

		$this->db->exec('UPDATE seats SET active = 0');
		$seat = $this->db->prepare(
			'INSERT INTO seats (label, table_id, svg_id, sort_order, active) VALUES (?, ?, ?, ?, 1)
			ON DUPLICATE KEY UPDATE table_id = VALUES(table_id), svg_id = VALUES(svg_id), sort_order = VALUES(sort_order), active = 1',
		);
		$order = 0;
		foreach ($plan->seats as $label => $s) {
			$seat->execute([(string) $label, $tableIds[$s['table']], $s['svg_id'], ++$order]);
		}
	}

	/** @param list<int> $values */
	private static function placeholders(array $values): string
	{
		return implode(',', array_fill(0, count($values), '?'));
	}
}
