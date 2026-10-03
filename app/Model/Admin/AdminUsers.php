<?php

declare(strict_types=1);

namespace App\Model\Admin;

use Nette\Security\Passwords;
use PDO;


/** Accounts of the organizers (table admin_users). */
final class AdminUsers
{
	public function __construct(
		private readonly PDO $db,
		private readonly Passwords $passwords,
	) {
	}


	/** @return array<string, mixed>|null */
	public function findByLogin(string $login): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT *, TIMESTAMPDIFF(SECOND, last_failed_at, NOW()) AS seconds_since_failure
			FROM admin_users WHERE login = ?',
		);
		$stmt->execute([$login]);
		return $stmt->fetch() ?: null;
	}


	/** @return array<string, mixed>|null */
	public function findById(int $id): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
		$stmt->execute([$id]);
		return $stmt->fetch() ?: null;
	}


	public function create(string $login, string $name, string $password): int
	{
		$this->db->prepare('INSERT INTO admin_users (login, name, password_hash) VALUES (?, ?, ?)')
			->execute([$login, $name, $this->passwords->hash($password)]);
		return (int) $this->db->lastInsertId();
	}


	public function changePassword(int $id, string $password): void
	{
		$this->db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
			->execute([$this->passwords->hash($password), $id]);
	}


	public function recordFailedLogin(int $id): void
	{
		$this->db->prepare(
			'UPDATE admin_users SET failed_logins = failed_logins + 1, last_failed_at = NOW() WHERE id = ?',
		)->execute([$id]);
	}


	public function recordLogin(int $id): void
	{
		$this->db->prepare(
			'UPDATE admin_users SET failed_logins = 0, last_failed_at = NULL, last_login_at = NOW() WHERE id = ?',
		)->execute([$id]);
	}
}
