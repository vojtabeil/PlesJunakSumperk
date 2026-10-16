<?php

declare(strict_types=1);

namespace App\Tests\Model\Admin;

use App\Model\Admin\AdminUsers;
use App\Model\Admin\Authenticator;
use App\Tests\Support\DatabaseTestCase;
use Nette\Security\AuthenticationException;
use Nette\Security\Passwords;


final class AuthenticatorTest extends DatabaseTestCase
{
	private Authenticator $authenticator;
	private AdminUsers $users;


	protected function setUp(): void
	{
		parent::setUp();
		$passwords = new Passwords(PASSWORD_BCRYPT, ['cost' => 4]); // fast hashing in tests
		$this->users = new AdminUsers($this->db, $passwords, $this->eventLog());
		$this->authenticator = new Authenticator($this->users, $passwords, $this->eventLog());
		$this->users->create('jana', 'Jana Organizátorka', 'spravne-heslo');
	}


	public function testValidLoginReturnsIdentityAndIsAudited(): void
	{
		$identity = $this->authenticator->authenticate(' jana ', 'spravne-heslo');

		self::assertSame([Authenticator::Role], $identity->getRoles());
		self::assertSame('Jana Organizátorka', $identity->getData()['name']);
		self::assertContains('admin.login', $this->loggedActions());
		self::assertNotNull($this->users->findByLogin('jana')['last_login_at'] ?? null);
	}


	public function testWrongPasswordAndUnknownLoginGiveTheSameMessage(): void
	{
		$messages = [];
		foreach ([['jana', 'spatne'], ['neexistuje', 'spatne']] as [$login, $password]) {
			try {
				$this->authenticator->authenticate($login, $password);
				self::fail('Authentication should fail');
			} catch (AuthenticationException $e) {
				$messages[] = $e->getMessage();
			}
		}
		self::assertSame($messages[0], $messages[1]);
	}


	public function testFailedLoginsAreNotAttributedToTheAccountAndUnknownOnesAreRateLimited(): void
	{
		foreach (['jana', 'neexistuje', 'jina', 'dalsi'] as $login) {
			try {
				$this->authenticator->authenticate($login, 'spatne');
			} catch (AuthenticationException) {
			}
		}

		$rows = $this->db->query(
			"SELECT action, actor_type, admin_user_id FROM event_log WHERE action LIKE 'admin.login%' ORDER BY id",
		)->fetchAll();
		self::assertSame(['admin.login_failed', 'admin.login_unknown'], array_column($rows, 'action'), 'Unknown logins: one row per minute');
		self::assertSame(['system', 'system'], array_column($rows, 'actor_type'));
		self::assertSame([null, null], array_column($rows, 'admin_user_id'));
	}


	public function testAccountIsLockedAfterTooManyFailuresWithoutSayingSo(): void
	{
		for ($i = 0; $i < Authenticator::MaxFailures; $i++) {
			try {
				$this->authenticator->authenticate('jana', 'spatne');
			} catch (AuthenticationException) {
			}
		}
		self::assertContains('admin.locked', $this->loggedActions());

		// The right password is refused too, with the same message as a wrong password or an unknown login.
		$this->expectExceptionMessage('Nesprávné přihlašovací jméno nebo heslo.');
		$this->authenticator->authenticate('jana', 'spravne-heslo');
	}


	public function testAttemptsAreCountedBeforeThePasswordIsChecked(): void
	{
		$id = (int) $this->users->findByLogin('jana')['id'];
		$counts = [];
		for ($i = 0; $i <= Authenticator::MaxFailures; $i++) {
			$counts[] = $this->users->countLoginAttempt($id, Authenticator::MaxFailures, Authenticator::LockSeconds);
		}
		self::assertSame([1, 2, 3, 4, 5, null], $counts, 'Each request takes a try in one statement; the sixth is locked');

		$this->db->exec('UPDATE admin_users SET last_failed_at = NOW() - INTERVAL 16 MINUTE');
		self::assertSame(1, $this->users->countLoginAttempt($id, Authenticator::MaxFailures, Authenticator::LockSeconds), 'An expired lock starts again');
	}


	public function testLockExpires(): void
	{
		$this->db->exec('UPDATE admin_users SET failed_logins = 5, last_failed_at = NOW() - INTERVAL 16 MINUTE');

		$this->authenticator->authenticate('jana', 'spravne-heslo');

		self::assertSame(0, (int) $this->users->findByLogin('jana')['failed_logins']);
	}
}
