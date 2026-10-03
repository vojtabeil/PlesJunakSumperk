<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\TesterAccess;
use App\Tests\DatabaseTestCase;


final class TesterAccessTest extends DatabaseTestCase
{
	public function testTokenIsCreatedOnceAndCheckedExactly(): void
	{
		$access = new TesterAccess($this->settings(), $this->eventLog());
		self::assertFalse($access->isValid(''), 'No token yet = nobody is a tester');

		$token = $access->token();
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
		self::assertSame($token, (new TesterAccess($this->settings(), $this->eventLog()))->token());
		self::assertTrue($access->isValid($token));
		self::assertFalse($access->isValid(strtoupper($token)));
		self::assertFalse($access->isValid(null));
		self::assertSame([], $this->loggedActions(), 'The first token is not a change worth logging');
	}


	public function testRegenerateInvalidatesTheOldLink(): void
	{
		$access = new TesterAccess($this->settings(), $this->eventLog());
		$old = $access->token();

		$new = $access->regenerate();

		self::assertNotSame($old, $new);
		self::assertFalse($access->isValid($old));
		self::assertTrue($access->isValid($new));
		self::assertSame(['tester.link_regenerated'], $this->loggedActions());
		self::assertStringNotContainsString($new, (string) $this->db->query('SELECT details FROM event_log')->fetchColumn());
	}


	public function testPublicModeAndTestReservations(): void
	{
		self::assertTrue((new TesterAccess($this->settings(), $this->eventLog()))->isPublic());

		$this->setSettings(['public_access' => 'testers']);
		self::assertFalse((new TesterAccess($this->settings(), $this->eventLog()))->isPublic());
		$this->reservations()->start('owner', 'tester@example.com');
		self::assertSame(1, (int) $this->db->query('SELECT is_test FROM reservations')->fetchColumn());
	}
}
