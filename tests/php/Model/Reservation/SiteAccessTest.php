<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\ReservationError;
use App\Model\Reservation\SiteAccess;
use App\Model\Reservation\SiteMode;
use App\Tests\DatabaseTestCase;


final class SiteAccessTest extends DatabaseTestCase
{
	public function testTokensAreCreatedOnceAndCheckedExactly(): void
	{
		$access = $this->access();
		self::assertFalse($access->isValid(SiteAccess::Tester, ''), 'No token yet = nobody is a tester');

		$tester = $access->token(SiteAccess::Tester);
		$vip = $access->token(SiteAccess::Vip);
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tester);
		self::assertNotSame($tester, $vip);
		self::assertSame($tester, $this->access()->token(SiteAccess::Tester));
		self::assertTrue($access->isValid(SiteAccess::Tester, $tester));
		self::assertTrue($access->isValid(SiteAccess::Vip, $vip));
		self::assertFalse($access->isValid(SiteAccess::Vip, $tester), 'The tester link is not a VIP link');
		self::assertFalse($access->isValid(SiteAccess::Tester, strtoupper($tester)));
		self::assertFalse($access->isValid(SiteAccess::Tester, null));
		self::assertSame([], $this->loggedActions(), 'The first tokens are not a change worth logging');
	}


	public function testRegenerateInvalidatesTheOldLink(): void
	{
		$access = $this->access();
		$oldTester = $access->token(SiteAccess::Tester);
		$oldVip = $access->token(SiteAccess::Vip);

		$newVip = $access->regenerate(SiteAccess::Vip);

		self::assertNotSame($oldVip, $newVip);
		self::assertFalse($access->isValid(SiteAccess::Vip, $oldVip));
		self::assertTrue($access->isValid(SiteAccess::Vip, $newVip));
		self::assertTrue($access->isValid(SiteAccess::Tester, $oldTester), 'The other link is kept');

		$access->regenerate(SiteAccess::Tester);
		self::assertFalse($access->isValid(SiteAccess::Tester, $oldTester));
		self::assertSame(['vip.link_regenerated', 'tester.link_regenerated'], $this->loggedActions());
		self::assertStringNotContainsString($newVip, (string) $this->db->query("SELECT GROUP_CONCAT(COALESCE(details, '')) FROM event_log")->fetchColumn());
	}


	public function testModeSwitchIsLoggedAndLeavingTheSaleFreesHeldSeats(): void
	{
		$this->reservations()->start('owner', 'alice@example.com');
		$this->reservations()->hold('owner', 101);

		$access = $this->access();
		$access->setMode(SiteMode::Public); // no change, nothing logged
		$access->setMode(SiteMode::Closed);

		self::assertSame(SiteMode::Closed, $this->access()->mode());
		self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM seats WHERE state <> 'free'")->fetchColumn());
		self::assertSame(['site.mode_changed'], $this->loggedActions());
		self::assertStringContainsString('Prodej ukončen', (string) $this->db->query('SELECT details FROM event_log')->fetchColumn());

		$this->expectException(ReservationError::class);
		$this->reservations()->start('other', 'bob@example.com');
	}


	public function testReservationsGetTheChannelOfTheStage(): void
	{
		foreach (['testing' => 'test', 'vip' => 'vip', 'public' => 'public'] as $mode => $channel) {
			$this->setSettings(['site_mode' => $mode]);
			$this->reservations()->start("owner-$mode", "$mode@example.com");
			self::assertSame($channel, $this->db->query("SELECT channel FROM reservations WHERE email = '$mode@example.com'")->fetchColumn());
		}
	}


	public function testUnknownStageIsTreatedAsTesting(): void
	{
		$this->setSettings(['site_mode' => 'nonsense']);
		self::assertSame(SiteMode::Testing, $this->access()->mode());
	}


	public function testStages(): void
	{
		self::assertSame(['test', 'vip', 'public', null, null], array_map(static fn(SiteMode $m): ?string => $m->channel(), SiteMode::cases()));
		self::assertSame(['page_testing', 'page_vip', null, 'page_closed', 'page_after'], array_map(static fn(SiteMode $m): ?string => $m->pageKey(), SiteMode::cases()));
	}


	private function access(): SiteAccess
	{
		return new SiteAccess($this->settings(), $this->eventLog(), $this->db);
	}
}
