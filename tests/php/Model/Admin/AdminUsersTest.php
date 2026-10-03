<?php

declare(strict_types=1);

namespace App\Tests\Model\Admin;

use App\Model\Admin\AdminError;
use App\Model\Admin\AdminUsers;
use App\Model\Admin\AuditLog;
use App\Model\Admin\Authenticator;
use App\Tests\DatabaseTestCase;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


final class AdminUsersTest extends DatabaseTestCase
{
	private AdminUsers $users;
	private Authenticator $authenticator;


	protected function setUp(): void
	{
		parent::setUp();
		$passwords = new Passwords(PASSWORD_BCRYPT, ['cost' => 4]);
		$this->users = new AdminUsers($this->db, $passwords);
		$this->authenticator = new Authenticator($this->users, $passwords, new AuditLog($this->db));
	}


	public function testFirstAccountCanBeCreatedOnlyOnce(): void
	{
		self::assertFalse($this->users->exists());
		$this->users->createFirst('prvni', 'První Admin', 'dlouhe-heslo-1');
		self::assertTrue($this->users->exists());

		$this->expectExceptionMessage('Web už je nastavený');
		$this->users->createFirst('druhy', 'Druhý Admin', 'dlouhe-heslo-2');
	}


	public function testValidatesLoginPasswordAndUniqueness(): void
	{
		$this->users->create('jana', 'Jana', 'dlouhe-heslo-1');

		foreach ([['Jana!', 'Jana', 'dlouhe-heslo-1'], ['petr', 'Petr', 'kratke'], ['jana', 'Jiná Jana', 'dlouhe-heslo-2']] as [$login, $name, $password]) {
			try {
				$this->users->create($login, $name, $password);
				self::fail("Account $login must be rejected");
			} catch (AdminError) {
			}
		}
		self::assertCount(1, $this->users->all());
	}


	public function testLastAccountCannotBeDeleted(): void
	{
		$first = $this->users->create('jana', 'Jana', 'dlouhe-heslo-1');
		$second = $this->users->create('petr', 'Petr', 'dlouhe-heslo-2');

		$this->users->delete($first);
		self::assertSame([$second], array_map('intval', array_column($this->users->all(), 'id')));

		$this->expectExceptionMessage('Poslední účet nelze smazat');
		$this->users->delete($second);
	}


	public function testDeletedAccountIsLoggedOutOnNextRequest(): void
	{
		$this->users->create('jana', 'Jana', 'dlouhe-heslo-1');
		$petr = $this->users->create('petr', 'Petr', 'dlouhe-heslo-2');
		$stored = $this->authenticator->sleepIdentity($this->authenticator->authenticate('petr', 'dlouhe-heslo-2'));

		self::assertNotNull($this->authenticator->wakeupIdentity($stored));
		$this->users->delete($petr);
		self::assertNull($this->authenticator->wakeupIdentity($stored));
	}


	public function testPasswordChangeEndsSessionsButNameChangeDoesNot(): void
	{
		$id = $this->users->create('jana', 'Jana', 'dlouhe-heslo-1');
		$stored = $this->authenticator->sleepIdentity($this->authenticator->authenticate('jana', 'dlouhe-heslo-1'));

		$this->users->update($id, 'jana', 'Jana Nová');
		$fresh = $this->authenticator->wakeupIdentity($stored);
		self::assertSame('Jana Nová', $fresh?->getData()['name'], 'Session sees the current name');

		$this->users->changePassword($id, 'jine-dlouhe-heslo');
		self::assertNull($this->authenticator->wakeupIdentity($stored), 'Old session is logged out');
		$this->authenticator->authenticate('jana', 'jine-dlouhe-heslo');
	}


	public function testForgedSessionWithoutVersionIsRejected(): void
	{
		$id = $this->users->create('jana', 'Jana', 'dlouhe-heslo-1');
		self::assertNull($this->authenticator->wakeupIdentity(new SimpleIdentity($id)));
	}


	public function testUnlockAfterFailedLogins(): void
	{
		$id = $this->users->create('jana', 'Jana', 'dlouhe-heslo-1');
		$this->db->exec('UPDATE admin_users SET failed_logins = 5, last_failed_at = NOW()');

		$this->users->unlock($id);

		$this->authenticator->authenticate('jana', 'dlouhe-heslo-1');
		self::assertSame(0, (int) $this->users->findById($id)['failed_logins']);
	}
}
