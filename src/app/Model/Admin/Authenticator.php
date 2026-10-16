<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Model\Log\EventLog;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


/**
 * Login of organizers. After MaxFailures wrong passwords the account is locked for LockSeconds (counted atomically
 * before the password is checked, so parallel requests do not get more tries). Messages are shown to the user
 * (Czech) and never reveal whether the login exists: a locked account answers like a wrong password, and every
 * path verifies one bcrypt hash of the same cost.
 *
 * As IdentityHandler it re-checks the account against the DB on every request: a deleted
 * account or a changed password (session_version) logs the user out immediately.
 */
final class Authenticator implements NetteAuthenticator, IdentityHandler
{
	public const MaxFailures = 5;
	public const LockSeconds = 15 * 60;
	public const Role = 'admin';

	private const InvalidCredentials = 'Nesprávné přihlašovací jméno nebo heslo.';


	/** Logins of non-existent accounts are logged at most once per this many seconds. */
	private const UnknownLoginLogSeconds = 60;


	public function __construct(
		private readonly AdminUsers $users,
		private readonly Passwords $passwords,
		private readonly EventLog $eventLog,
	) {
	}


	public function authenticate(string $user, string $password): IIdentity
	{
		$account = $this->users->findByLogin(trim($user));
		if ($account === null) {
			$this->passwords->verify($password, $this->dummyHash());
			// Anybody can try unknown logins without a lockout, so they must not flood the log.
			if (!$this->eventLog->recordedWithin('admin.login_unknown', self::UnknownLoginLogSeconds)) {
				$this->eventLog->record('admin.login_unknown', details: ['login' => mb_substr(trim($user), 0, 64)]);
			}
			throw new AuthenticationException(self::InvalidCredentials);
		}

		$id = (int) $account['id'];
		$attempts = $this->users->countLoginAttempt($id, self::MaxFailures, self::LockSeconds);
		$valid = $this->passwords->verify($password, (string) $account['password_hash']);
		if ($attempts === null) {
			// Locked: the same answer (and time) as a wrong password, even for the right one.
			throw new AuthenticationException(self::InvalidCredentials);
		}
		if (!$valid) {
			// Not attributed to the account: whoever typed the password is not known.
			$this->eventLog->record('admin.login_failed', details: ['login' => $account['login']]);
			if ($attempts === self::MaxFailures) {
				$this->eventLog->record('admin.locked', details: ['login' => $account['login']]);
			}
			throw new AuthenticationException(self::InvalidCredentials);
		}

		if ($this->passwords->needsRehash((string) $account['password_hash'])) {
			$this->users->rehashPassword($id, $password);
		}
		$this->users->recordLogin($id);
		$this->eventLog->record('admin.login', adminId: $id);
		return $this->identity($account);
	}



	/** Only the id and the session version are kept in the session. */
	public function sleepIdentity(IIdentity $identity): IIdentity
	{
		return new SimpleIdentity($identity->getId(), [], ['version' => $identity->getData()['version'] ?? 0]);
	}


	/** Fresh identity from the DB, or null (= logged out) when the account changed. */
	public function wakeupIdentity(IIdentity $identity): ?IIdentity
	{
		$account = $this->users->findById((int) $identity->getId());
		if ($account === null || (int) $account['session_version'] !== (int) ($identity->getData()['version'] ?? -1)) {
			return null;
		}
		return $this->identity($account);
	}


	/** @param array<string, mixed> $account */
	public function identity(array $account): SimpleIdentity
	{
		return new SimpleIdentity((int) $account['id'], [self::Role], [
			'login' => $account['login'],
			'name' => $account['name'],
			'version' => (int) $account['session_version'],
		]);
	}


	/**
	 * Hash verified for unknown logins: an existing account's (same cost as a real login), or one made now with the
	 * current cost while there is no account yet.
	 */
	private function dummyHash(): string
	{
		return $this->users->anyPasswordHash() ?? $this->passwords->hash(bin2hex(random_bytes(16)));
	}
}
