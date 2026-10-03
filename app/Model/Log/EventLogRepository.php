<?php

declare(strict_types=1);

namespace App\Model\Log;

use PDO;


/** Reading and filtering the event log for the administration. */
final class EventLogRepository
{
	public const PageSize = 50;


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/**
	 * @return array{events: list<array<string, mixed>>, total: int}
	 */
	public function search(EventFilter $filter, int $page = 1): array
	{
		[$where, $params] = $this->conditions($filter);
		$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

		$count = $this->db->prepare("SELECT COUNT(*) FROM event_log e $sqlWhere");
		$count->execute($params);
		$total = (int) $count->fetchColumn();

		$offset = (max(1, $page) - 1) * self::PageSize;
		$stmt = $this->db->prepare(
			"SELECT e.*, u.name AS admin_name, r.name AS reservation_name,
				(SELECT GROUP_CONCAT(s.label ORDER BY s.id SEPARATOR ', ')
					FROM event_log_seats es JOIN seats s ON s.id = es.seat_id WHERE es.event_id = e.id) AS seat_labels
			FROM event_log e
			LEFT JOIN admin_users u ON u.id = e.admin_user_id
			LEFT JOIN reservations r ON r.id = e.reservation_id
			$sqlWhere
			ORDER BY e.id DESC
			LIMIT " . self::PageSize . ' OFFSET ' . $offset,
		);
		$stmt->execute($params);
		return ['events' => $stmt->fetchAll(), 'total' => $total];
	}


	/** @return list<array<string, mixed>> newest first */
	public function recent(int $limit = 10, ?int $reservationId = null): array
	{
		$filter = new EventFilter(reservationId: $reservationId);
		$events = $this->search($filter)['events'];
		return array_slice($events, 0, $limit);
	}


	/** @return array{list<string>, list<mixed>} */
	private function conditions(EventFilter $f): array
	{
		$where = [];
		$params = [];
		if ($f->from !== null) {
			$where[] = 'e.created_at >= ?';
			$params[] = $f->from->format('Y-m-d 00:00:00');
		}
		if ($f->to !== null) {
			$where[] = 'e.created_at < ?';
			$params[] = $f->to->modify('+1 day')->format('Y-m-d 00:00:00');
		}
		if ($f->category !== null && isset(EventTypes::Categories[$f->category])) {
			$actions = EventTypes::actionsOf($f->category);
			$where[] = 'e.action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
			array_push($params, ...$actions);
		}
		if ($f->actor !== null && isset(EventTypes::Actors[$f->actor])) {
			$where[] = 'e.actor_type = ?';
			$params[] = $f->actor;
		}
		if ($f->reservationId !== null) {
			$where[] = 'e.reservation_id = ?';
			$params[] = $f->reservationId;
		}
		if ($f->seatId !== null) {
			$where[] = 'EXISTS (SELECT 1 FROM event_log_seats es WHERE es.event_id = e.id AND es.seat_id = ?)';
			$params[] = $f->seatId;
		}
		return [$where, $params];
	}
}
