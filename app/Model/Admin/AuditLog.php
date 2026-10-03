<?php

declare(strict_types=1);

namespace App\Model\Admin;

use Nette\Utils\Json;
use PDO;


/** Record of state-changing actions in the administration (table audit_log). */
final class AuditLog
{
	/** Czech descriptions of the actions for the admin UI. */
	public const Labels = [
		'admin.login' => 'Přihlášení',
		'admin.password' => 'Změna vlastního hesla',
		'admin.setup' => 'Nastavení webu (první účet)',
		'admin.created' => 'Nový administrátor',
		'admin.updated' => 'Úprava administrátora',
		'admin.deleted' => 'Smazání administrátora',
		'reservation.paid' => 'Označeno jako zaplacené',
		'reservation.cancelled' => 'Rezervace zrušena',
		'reservation.email' => 'Znovu odeslán potvrzovací e-mail',
		'reservation.note' => 'Změna poznámky',
		'settings.changed' => 'Změna nastavení',
		'payments.imported' => 'Načtení plateb z banky',
		'payment.assigned' => 'Platba přiřazena ručně',
		'payment.ignored' => 'Platba ignorována',
	];


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/** @param array<string, mixed> $details */
	public function record(?int $adminUserId, string $action, ?int $reservationId = null, array $details = []): void
	{
		$this->db->prepare(
			'INSERT INTO audit_log (admin_user_id, action, reservation_id, details) VALUES (?, ?, ?, ?)',
		)->execute([
			$adminUserId,
			$action,
			$reservationId,
			$details ? mb_substr(Json::encode($details), 0, 2000) : null,
		]);
	}


	/** @return list<array<string, mixed>> newest first */
	public function recent(int $limit = 20, ?int $reservationId = null): array
	{
		$stmt = $this->db->prepare(
			'SELECT l.*, u.name AS admin_name
			FROM audit_log l LEFT JOIN admin_users u ON u.id = l.admin_user_id
			WHERE ? IS NULL OR l.reservation_id = ?
			ORDER BY l.id DESC LIMIT ' . max(1, $limit),
		);
		$stmt->execute([$reservationId, $reservationId]);
		return $stmt->fetchAll();
	}
}
