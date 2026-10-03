<?php

declare(strict_types=1);

namespace App\Model\Admin;

use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator as NetteAuthenticator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


/**
 * Login of organizers. After MaxFailures wrong passwords the account is locked for LockSeconds.
 * Messages are shown to the user (Czech) and never reveal whether the login exists.
 */
final class Authenticator implements NetteAuthenticator
{
	public const MaxFailures = 5;
	public const LockSeconds = 15 * 60;
	public const Role = 'admin';

	private const InvalidCredentials = 'Nesprávné přihlašovací jméno nebo heslo.';


	public function __construct(
		private readonly AdminUsers $users,
		private readonly Passwords $passwords,
		private readonly AuditLog $auditLog,
	) {
	}


	public function authenticate(string $user, string $password): IIdentity
	{
		$account = $this->users->findByLogin(trim($user));
		if ($account === null) {
			// Same work as for an existing account, so timing does not reveal valid logins.
			$this->passwords->verify($password, '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea');
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
			throw new AuthenticationException(self::InvalidCredentials);
		}

		if ($this->passwords->needsRehash((string) $account['password_hash'])) {
			$this->users->changePassword($id, $password);
		}
		$this->users->recordLogin($id);
		$this->auditLog->record($id, 'admin.login');

		return new SimpleIdentity($id, [self::Role], ['login' => $account['login'], 'name' => $account['name']]);
	}
}
