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
 * Login of organizers. After MaxFailures wrong passwords the account is locked for LockSeconds.
 * Messages are shown to the user (Czech) and never reveal whether the login exists.
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

	/** Valid bcrypt hash of a random string, verified for unknown logins to keep timing equal. */
	private const DummyHash = '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea';


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
			$this->passwords->verify($password, self::DummyHash);
			// Anybody can try unknown logins without a lockout, so they must not flood the log.
			if (!$this->eventLog->recordedWithin('admin.login_unknown', self::UnknownLoginLogSeconds)) {
				$this->eventLog->record('admin.login_unknown', details: ['login' => mb_substr(trim($user), 0, 64)]);
			}
			throw new AuthenticationException(self::InvalidCredentials);
		}

		$id = (int) $account['id'];
		$locked = (int) $account['failed_logins'] >= self::MaxFailures
			&& $account['seconds_since_failure'] !== null
			&& (int) $account['seconds_since_failure'] < self::LockSeconds;
		if ($locked) {
			throw new AuthenticationException('Příliš mnoho neúspěšných pokusů. Zkuste to znovu za 15 minut.');
		}

		if (!$this->passwords->verify($password, (string) $account['password_hash'])) {
			$this->users->recordFailedLogin($id);
			// Not attributed to the account: whoever typed the password is not known.
			$this->eventLog->record('admin.login_failed', details: ['login' => $account['login']]);
			if ((int) $account['failed_logins'] + 1 === self::MaxFailures) {
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
}
