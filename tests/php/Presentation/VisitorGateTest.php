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
	private const VipToken = 'vip-token-0123456789';


	public function testWhoCanBuyInEveryStage(): void
	{
		$tester = $this->access()->testerToken();
		$visitors = [
			'nobody' => [],
			'tester' => [VisitorGate::TesterCookie => $tester],
			'vip' => [VisitorGate::VipCookie => self::VipToken],
			'forged' => [VisitorGate::TesterCookie => str_repeat('0', 32), VisitorGate::VipCookie => 'x' . self::VipToken],
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
		$token = $this->access()->testerToken();
		$gate = $this->gate([]);
		self::assertFalse($gate->admitTester(str_repeat('a', 32)));
		self::assertTrue($gate->admitTester($token));
		self::assertFalse($gate->admitVip('wrong-token-0123456789'));
		self::assertTrue($gate->admitVip(self::VipToken));
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
		return new SiteAccess(self::VipToken, $this->settings(), $this->eventLog(), $this->db);
	}
}
