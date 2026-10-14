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
			if ($changes->removedSeats) {
				// Seats being selected right now are only released.
				$this->db->prepare('DELETE FROM seat_bookings WHERE seat_id IN (' . self::placeholders($changes->removedSeats) . ')')
					->execute($changes->removedSeats);
			}
			$this->storeLayout($plan);
			$this->db->prepare(
				'REPLACE INTO hall_plan (id, svg, file_name, applied_by, applied_at) VALUES (1, ?, ?, ?, NOW())',
			)->execute([$plan->svg, mb_substr($fileName, 0, 255), $this->actor->adminId()]);

			$this->eventLog->record('hall.replaced', null, ['file' => $fileName] + $changes->counts(), $changes->removedSeats);
			$this->db->commit();
			return $changes;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	private function changes(HallPlan $plan, bool $lock): HallChanges
	{
		$seats = [];
		foreach ($this->db->query('SELECT id, table_id, active FROM seats' . ($lock ? ' FOR UPDATE' : ''))->fetchAll() as $row) {
			$seats[(int) $row['id']] = ['table' => $row['table_id'] !== null ? (int) $row['table_id'] : null, 'active' => (bool) $row['active']];
		}
		$tables = [];
		foreach ($this->db->query('SELECT id, name, active FROM hall_tables')->fetchAll() as $row) {
			$tables[(int) $row['id']] = ['name' => $row['name'], 'active' => (bool) $row['active']];
		}
		$activeSeats = array_keys(array_filter($seats, static fn(array $s): bool => $s['active']));
		$activeTables = array_keys(array_filter($tables, static fn(array $t): bool => $t['active']));

		$moved = $renamed = [];
		foreach ($plan->seats as $number => $seat) {
			if (isset($seats[$number]) && $seats[$number]['active'] && $seats[$number]['table'] !== $seat['table']) {
				$moved[$number] = ['from' => $seats[$number]['table'], 'to' => $seat['table']];
			}
		}
		foreach ($plan->tables as $number => $table) {
			if (isset($tables[$number]) && $tables[$number]['active'] && $tables[$number]['name'] !== $table['name']) {
				$renamed[$number] = ['from' => $tables[$number]['name'], 'to' => $table['name']];
			}
		}
		$removedSeats = array_values(array_diff($activeSeats, array_keys($plan->seats)));
		[$affected, $held] = $this->affected($removedSeats);

		return new HallChanges(
			newSeats: array_values(array_diff(array_keys($plan->seats), $activeSeats)),
			removedSeats: $removedSeats,
			movedSeats: $moved,
			newTables: array_values(array_diff(array_keys($plan->tables), $activeTables)),
			removedTables: array_values(array_diff($activeTables, array_keys($plan->tables))),
			renamedTables: $renamed,
			affected: $affected,
			heldSeats: $held,
		);
	}


	/**
	 * Finished reservations holding a removed seat (they will be cancelled), and the number of removed
	 * seats held by drafts (only released).
	 * @param list<int> $removedSeats
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
			ORDER BY r.id, s.id',
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


	/** Tables and seats of the plan become active (inserted or updated), the others inactive. */
	private function storeLayout(HallPlan $plan): void
	{
		$this->db->exec('UPDATE hall_tables SET active = 0');
		$table = $this->db->prepare(
			'INSERT INTO hall_tables (id, label, name, svg_id, active) VALUES (?, ?, ?, ?, 1)
			ON DUPLICATE KEY UPDATE label = VALUES(label), name = VALUES(name), svg_id = VALUES(svg_id), active = 1',
		);
		foreach ($plan->tables as $number => $t) {
			$table->execute([$number, (string) $number, $t['name'], $t['svg_id']]);
		}

		$this->db->exec('UPDATE seats SET active = 0');
		$seat = $this->db->prepare(
			'INSERT INTO seats (id, label, table_id, svg_id, active) VALUES (?, ?, ?, ?, 1)
			ON DUPLICATE KEY UPDATE label = VALUES(label), table_id = VALUES(table_id), svg_id = VALUES(svg_id), active = 1',
		);
		foreach ($plan->seats as $number => $s) {
			$seat->execute([$number, $s['table'] . '/' . $number, $s['table'], $s['svg_id']]);
		}
	}


	/** @param list<int> $values */
	private static function placeholders(array $values): string
	{
		return implode(',', array_fill(0, count($values), '?'));
	}
}
