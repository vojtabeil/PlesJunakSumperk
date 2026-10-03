<?php

declare(strict_types=1);

namespace App\Tests\Model\Admin;

use App\Model\Admin\AdminUsers;
use App\Model\Admin\AuditLog;
use App\Model\Admin\Authenticator;
use App\Tests\DatabaseTestCase;
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
		$this->users = new AdminUsers($this->db, $passwords);
		$this->authenticator = new Authenticator($this->users, $passwords, new AuditLog($this->db));
		$this->users->create('jana', 'Jana Organizátorka', 'spravne-heslo');
	}


	public function testValidLoginReturnsIdentityAndIsAudited(): void
	{
		$identity = $this->authenticator->authenticate(' jana ', 'spravne-heslo');

		self::assertSame([Authenticator::Role], $identity->getRoles());
		self::assertSame('Jana Organizátorka', $identity->getData()['name']);
		self::assertSame('admin.login', $this->db->query('SELECT action FROM audit_log')->fetchColumn());
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


	public function testAccountIsLockedAfterTooManyFailures(): void
	{
		for ($i = 0; $i < Authenticator::MaxFailures; $i++) {
			try {
				$this->authenticator->authenticate('jana', 'spatne');
			} catch (AuthenticationException) {
			}
		}

		$this->expectExceptionMessage('Příliš mnoho neúspěšných pokusů');
		$this->authenticator->authenticate('jana', 'spravne-heslo');
	}


	public function testLockExpires(): void
	{
		$this->db->exec('UPDATE admin_users SET failed_logins = 5, last_failed_at = NOW() - INTERVAL 16 MINUTE');

		$this->authenticator->authenticate('jana', 'spravne-heslo');

		self::assertSame(0, (int) $this->users->findByLogin('jana')['failed_logins']);
	}
}
