<?php

declare(strict_types=1);

namespace App\Model\Log;

use Nette\Utils\Json;
use PDO;


/**
 * Writes the event log (table event_log). Called by the model, so every way to an action
 * (form, API, cron, CLI) is logged. Never put secrets or passwords into $details.
 */
final class EventLog
{
	public function __construct(
		private readonly PDO $db,
		private readonly Actor $actor,
	) {
	}


	/**
	 * @param array<string, mixed> $details shown in the log (amounts, e-mail, changed values...)
	 * @param list<int>|null $seatIds seats the event refers to; null = the current seats of the reservation
	 * @param bool $automatic consequence done by the application itself (e-mail sent...): logged as "system"
	 *     when a customer caused it; when an admin or cron caused it, they stay the actor
	 * @param int|null $adminId administrator the event is about when the request has no logged-in admin yet (login, setup)
	 */
	public function record(
		string $action,
		?int $reservationId = null,
		array $details = [],
		?array $seatIds = null,
		bool $automatic = false,
		?int $adminId = null,
	): void {
		$type = match (true) {
			$adminId !== null => Actor::Admin,
			$automatic && in_array($this->actor->type(), [Actor::Customer, Actor::System], true) => Actor::System,
			default => $this->actor->type(),
		};
		$admin = $type === Actor::Admin ? ($adminId ?? $this->actor->adminId()) : null;

		$this->db->prepare(
			'INSERT INTO event_log (actor_type, admin_user_id, action, reservation_id, details) VALUES (?, ?, ?, ?, ?)',
		)->execute([
			$type,
			$admin,
			$action,
			$reservationId,
			$details ? mb_substr(Json::encode($details), 0, 2000) : null,
		]);
		$eventId = (int) $this->db->lastInsertId();

		if ($seatIds === null && $reservationId !== null) {
			$stmt = $this->db->prepare('SELECT id FROM seats WHERE reservation_id = ?');
			$stmt->execute([$reservationId]);
			$seatIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		}
		if ($seatIds) {
			$insert = $this->db->prepare('INSERT IGNORE INTO event_log_seats (event_id, seat_id) VALUES (?, ?)');
			foreach ($seatIds as $seatId) {
				$insert->execute([$eventId, $seatId]);
			}
		}
	}
}
