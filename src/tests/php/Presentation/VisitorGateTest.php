<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Model\Reservation\SiteAccess;
use App\Presentation\Front\VisitorGate;
use App\Tests\DatabaseTestCase;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;
use Nette\Security\UserStorage;


/** Who may buy in which stage: visitor without a link, tester, VIP, administrator. */
final class VisitorGateTest extends DatabaseTestCase
{
	public function testWhoCanBuyInEveryStage(): void
	{
		$tester = $this->access()->token(SiteAccess::Tester);
		$vip = $this->access()->token(SiteAccess::Vip);
		$visitors = [
			'nobody' => [],
			'tester' => [VisitorGate::TesterCookie => $tester],
			'vip' => [VisitorGate::VipCookie => $vip],
			// each token in the other cookie, plus a made-up one
			'forged' => [VisitorGate::TesterCookie => $vip, VisitorGate::VipCookie => str_repeat('0', 32)],
		];
		$expected = [
			'testing' => ['nobody' => false, 'tester' => true, 'vip' => false, 'forged' => false, 'admin' => true],
			'vip' => ['nobody' => false, 'tester' => false, 'vip' => true, 'forged' => false, 'admin' => true],
			'public' => ['nobody' => true, 'tester' => true, 'vip' => true, 'forged' => true, 'admin' => true],
			'closed' => ['nobody' => false, 'tester' => false, 'vip' => false, 'forged' => false, 'admin' => false],
			'after' => ['nobody' => false, 'tester' => false, 'vip' => false, 'forged' => false, 'admin' => false],
		];

		foreach ($expected as $mode => $byVisitor) {
			$this->setSettings(['site_mode' => $mode]);
			$actual = [];
			foreach ($visitors as $name => $cookies) {
				$actual[$name] = $this->gate($cookies)->canBuy();
			}
			$actual['admin'] = $this->gate([], loggedIn: true)->canBuy();
			self::assertSame($byVisitor, $actual, "Stage $mode");
		}
	}


	public function testLinksAdmitOnlyWithTheRightToken(): void
	{
		$tester = $this->access()->token(SiteAccess::Tester);
		$vip = $this->access()->token(SiteAccess::Vip);
		$gate = $this->gate([]);
		self::assertFalse($gate->admitTester(str_repeat('a', 32)));
		self::assertFalse($gate->admitTester($vip));
		self::assertTrue($gate->admitTester($tester));
		self::assertFalse($gate->admitVip($tester));
		self::assertTrue($gate->admitVip($vip));
	}


	/** @param array<string, string> $cookies */
	private function gate(array $cookies, bool $loggedIn = false): VisitorGate
	{
		$storage = $this->createStub(UserStorage::class);
		$storage->method('getState')->willReturn([$loggedIn, $loggedIn ? new SimpleIdentity(1) : null, null]);
		$user = new User($storage);
		return new VisitorGate(
			$this->access(),
			new Request(new UrlScript('https://ples.test/'), cookies: $cookies),
			new Response,
			$user,
		);
	}


	private function access(): SiteAccess
	{
		return new SiteAccess($this->settings(), $this->eventLog(), $this->db);
	}
}
