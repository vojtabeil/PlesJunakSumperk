<?php

declare(strict_types=1);

namespace App\Model\Hall;

use App\Model\Log\EventLog;
use PDO;
use Throwable;


/**
 * Which tables of the hall plan are for sale (hall_tables.available). Tables out of sale disappear from the seat
 * picker and their seats cannot be chosen; reservations already holding their seats stay as they are, only seats
 * somebody is selecting right now are released. The flag survives plan uploads.
 */
final class TableAvailability
{
	public function __construct(
		private readonly PDO $db,
		private readonly EventLog $eventLog,
	) {
	}


	/**
	 * Tables of the plan in hall order with their seats and what is sold there.
	 * @return list<array{id: int, label: string, name: ?string, svg_id: string, available: bool, seats: int, reserved: int, held: int}>
	 */
	public function tables(): array
	{
		$rows = $this->db->query(
			"SELECT t.id, t.label, t.name, t.svg_id, t.available,
				COUNT(s.id) AS seats,
				COALESCE(SUM(b.state = 'reserved'), 0) AS reserved,
				COALESCE(SUM(b.state = 'held'), 0) AS held
			FROM hall_tables t
			LEFT JOIN seats s ON s.table_id = t.id AND s.active = 1
			LEFT JOIN seat_bookings b ON b.seat_id = s.id
			WHERE t.active = 1
			GROUP BY t.id
			ORDER BY t.sort_order, t.id",
		)->fetchAll();
		return array_map(static fn(array $row): array => [
			'id' => (int) $row['id'],
			'label' => (string) $row['label'],
			'name' => $row['name'] !== null ? (string) $row['name'] : null,
			'svg_id' => (string) $row['svg_id'],
			'available' => (bool) $row['available'],
			'seats' => (int) $row['seats'],
			'reserved' => (int) $row['reserved'],
			'held' => (int) $row['held'],
		], $rows);
	}


	/**
	 * Takes the given tables out of sale and puts all others back. Logged when something changes.
	 * @param list<string> $unavailable labels of the tables out of sale
	 * @return array{unavailable: list<string>, available: list<string>, released: int} what changed
	 */
	public function save(array $unavailable): array
	{
		$this->db->beginTransaction();
		try {
			$current = [];
			foreach ($this->db->query('SELECT label, available FROM hall_tables WHERE active = 1 FOR UPDATE')->fetchAll() as $row) {
				$current[(string) $row['label']] = (bool) $row['available'];
			}
			// Labels of an older plan may be numeric, so PHP turns some keys into integers.
			$labels = array_map('strval', array_keys($current));
			$unavailable = array_values(array_intersect($labels, $unavailable));
			$off = array_values(array_filter($unavailable, static fn(string $label): bool => $current[$label]));
			$on = array_values(array_filter(
				$labels,
				static fn(string $label): bool => !$current[$label] && !in_array($label, $unavailable, true),
			));

			$released = 0;
			if ($off) {
				$in = implode(',', array_fill(0, count($off), '?'));
				$this->db->prepare("UPDATE hall_tables SET available = 0 WHERE label IN ($in)")->execute($off);
				// Seats being selected right now at these tables are released; reservations keep theirs.
				$stmt = $this->db->prepare(
					"DELETE b FROM seat_bookings b
					JOIN seats s ON s.id = b.seat_id
					JOIN hall_tables t ON t.id = s.table_id
					WHERE b.state = 'held' AND t.label IN ($in)",
				);
				$stmt->execute($off);
				$released = $stmt->rowCount();
			}
			if ($on) {
				$in = implode(',', array_fill(0, count($on), '?'));
				$this->db->prepare("UPDATE hall_tables SET available = 1 WHERE label IN ($in)")->execute($on);
			}
			$changes = ['unavailable' => $off, 'available' => $on, 'released' => $released];
			if ($off || $on) {
				$this->eventLog->record('hall.availability', null, array_filter($changes));
			}
			$this->db->commit();
			return $changes;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
