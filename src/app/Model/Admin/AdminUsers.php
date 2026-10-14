<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Model\Log\EventLog;
use Nette\Security\Passwords;
use PDO;
use PDOException;
use Throwable;


/** Accounts of the organizers (table admin_users). All accounts have the same rights. */
final class AdminUsers
{
	public const MinPasswordLength = 10;
	public const LoginPattern = '[a-z0-9._-]{3,64}';

	private const SetupLock = 'ples_admin_setup';


	public function __construct(
		private readonly PDO $db,
		private readonly Passwords $passwords,
		private readonly EventLog $eventLog,
	) {
	}


	/** False = the site is not configured yet (first-run wizard). */
	public function exists(): bool
	{
		return (bool) $this->db->query('SELECT EXISTS (SELECT 1 FROM admin_users)')->fetchColumn();
	}


	/** @return list<array<string, mixed>> */
	public function all(): array
	{
		return $this->db->query(
			'SELECT id, login, name, failed_logins, last_failed_at, last_login_at, created_at FROM admin_users ORDER BY name, login',
		)->fetchAll();
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
		$id = $this->insert($login, $name, $password);
		$this->eventLog->record('admin.created', details: ['login' => $login, 'name' => trim($name)]);
		return $id;
	}


	/**
	 * Creates the very first account. A named DB lock makes parallel wizards wait,
	 * so at most one of them creates an account.
	 */
	public function createFirst(string $login, string $name, string $password): int
	{
		$this->assertValid($login, $name, $password);
		if (!$this->db->query("SELECT GET_LOCK('" . self::SetupLock . "', 10)")->fetchColumn()) {
			throw new AdminError('Nepodařilo se získat zámek, zkuste to prosím znovu.');
		}
		try {
			if ($this->exists()) {
				throw new AdminError('Web už je nastavený, přihlaste se.');
			}
			$id = $this->insert($login, $name, $password);
			$this->eventLog->record('admin.setup', details: ['login' => $login], adminId: $id);
			return $id;
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('" . self::SetupLock . "')");
		}
	}


	public function update(int $id, string $login, string $name): void
	{
		$this->assertValid($login, $name);
		try {
			$this->db->prepare('UPDATE admin_users SET login = ?, name = ? WHERE id = ?')
				->execute([$login, trim($name), $id]);
		} catch (PDOException $e) {
			throw $this->duplicateLogin($e, $login);
		}
		$this->eventLog->record('admin.updated', details: ['login' => $login, 'name' => trim($name)]);
	}


	/** Also ends all sessions of the account (see Authenticator::wakeupIdentity). */
	public function changePassword(int $id, string $password): void
	{
		$this->assertPassword($password);
		$this->db->prepare(
			'UPDATE admin_users SET password_hash = ?, session_version = session_version + 1,
			failed_logins = 0, last_failed_at = NULL WHERE id = ?',
		)->execute([$this->passwords->hash($password), $id]);
		$this->eventLog->record('admin.password', details: ['login' => $this->loginOf($id)]);
	}


	/** Silent rehash after login (stronger algorithm); does not end sessions. */
	public function rehashPassword(int $id, string $password): void
	{
		$this->db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
			->execute([$this->passwords->hash($password), $id]);
	}


	/** Lifts the lock after too many failed logins. */
	public function unlock(int $id): void
	{
		$this->db->prepare('UPDATE admin_users SET failed_logins = 0, last_failed_at = NULL WHERE id = ?')
			->execute([$id]);
		$this->eventLog->record('admin.unlocked', details: ['login' => $this->loginOf($id)]);
	}


	/** Deletes an account; the last one cannot be deleted (the site would be unconfigured). */
	public function delete(int $id): void
	{
		$this->db->beginTransaction();
		try {
			// Locks all accounts, so two admins deleting each other cannot delete both.
			$ids = array_map('intval', $this->db->query('SELECT id FROM admin_users FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
			if (!in_array($id, $ids, true)) {
				throw new AdminError('Účet neexistuje.');
			}
			if (count($ids) === 1) {
				throw new AdminError('Poslední účet nelze smazat. Nejdřív založte jiný.');
			}
			// Logged before deleting; the log keeps its entries (foreign key ON DELETE SET NULL).
			$this->eventLog->record('admin.deleted', details: ['login' => $this->loginOf($id)]);
			$this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
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


	private function insert(string $login, string $name, string $password): int
	{
		$this->assertValid($login, $name, $password);
		try {
			$this->db->prepare('INSERT INTO admin_users (login, name, password_hash) VALUES (?, ?, ?)')
				->execute([$login, trim($name), $this->passwords->hash($password)]);
		} catch (PDOException $e) {
			throw $this->duplicateLogin($e, $login);
		}
		return (int) $this->db->lastInsertId();
	}


	private function loginOf(int $id): string
	{
		return (string) ($this->findById($id)['login'] ?? '');
	}


	private function assertValid(string $login, string $name, ?string $password = null): void
	{
		if (!preg_match('/^' . self::LoginPattern . '$/D', $login)) {
			throw new AdminError('Přihlašovací jméno: 3–64 znaků, jen malá písmena bez diakritiky, číslice a . _ -');
		}
		if (trim($name) === '' || mb_strlen($name) > 255) {
			throw new AdminError('Vyplňte jméno.');
		}
		if ($password !== null) {
			$this->assertPassword($password);
		}
	}


	private function assertPassword(string $password): void
	{
		if (mb_strlen($password) < self::MinPasswordLength) {
			throw new AdminError(sprintf('Heslo musí mít alespoň %d znaků.', self::MinPasswordLength));
		}
	}


	private function duplicateLogin(PDOException $e, string $login): \Throwable
	{
		return ($e->errorInfo[1] ?? null) === 1062
			? new AdminError("Přihlašovací jméno „{$login}“ už používá jiný účet.")
			: $e;
	}
}
