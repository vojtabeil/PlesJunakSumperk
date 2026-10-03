<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\ReservationError;
use App\Model\Reservation\SiteAccess;
use App\Model\Reservation\SiteMode;
use App\Tests\DatabaseTestCase;


final class SiteAccessTest extends DatabaseTestCase
{
	private const VipToken = 'vip-token-0123456789';


	public function testTesterTokenIsCreatedOnceAndCheckedExactly(): void
	{
		$access = $this->access();
		self::assertFalse($access->isTester(''), 'No token yet = nobody is a tester');

		$token = $access->testerToken();
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
		self::assertSame($token, $this->access()->testerToken());
		self::assertTrue($access->isTester($token));
		self::assertFalse($access->isTester(strtoupper($token)));
		self::assertFalse($access->isTester(null));
		self::assertSame([], $this->loggedActions(), 'The first token is not a change worth logging');
	}


	public function testRegenerateInvalidatesTheOldTesterLink(): void
	{
		$access = $this->access();
		$old = $access->testerToken();

		$new = $access->regenerateTesterToken();

		self::assertNotSame($old, $new);
		self::assertFalse($access->isTester($old));
		self::assertTrue($access->isTester($new));
		self::assertSame(['tester.link_regenerated'], $this->loggedActions());
		self::assertStringNotContainsString($new, (string) $this->db->query('SELECT details FROM event_log')->fetchColumn());
	}


	public function testVipTokenComesFromConfigurationAndMustBeLongEnough(): void
	{
		self::assertTrue($this->access()->isVip(self::VipToken));
		self::assertFalse($this->access()->isVip('vip-token'));
		self::assertSame(self::VipToken, $this->access()->vipToken());

		$weak = $this->access('short');
		self::assertNull($weak->vipToken());
		self::assertFalse($weak->isVip('short'));
		self::assertFalse($this->access('')->isVip(''));
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


	private function access(string $vipToken = self::VipToken): SiteAccess
	{
		return new SiteAccess($vipToken, $this->settings(), $this->eventLog(), $this->db);
	}
}
