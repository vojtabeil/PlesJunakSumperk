<?php

declare(strict_types=1);

namespace App\Tests\Model\Stats;

use App\Model\Export\Csv;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\VariableSymbol;
use App\Model\Stats\SalesStats;
use App\Presentation\Accessory\TemplateExtension;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;


/**
 * Alice (2 seats) two days ago, paid by transfer yesterday; Bob (seat + standing) yesterday, paid in cash today;
 * Carol two days ago, cancelled; a tester today (never counted).
 */
final class SalesStatsTest extends DatabaseTestCase
{
	private string $twoDaysAgo;
	private string $yesterday;
	private string $today;


	protected function setUp(): void
	{
		parent::setUp();
		$this->twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
		$this->yesterday = date('Y-m-d', strtotime('-1 day'));
		$this->today = date('Y-m-d');

		$alice = $this->reservation('alice', [101, 102], 0, $this->twoDaysAgo);
		$this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, variable_symbol, match_status)
			VALUES ('mock', 'a1', ?, 700, ?, 'no_vs')",
		)->execute([$this->yesterday, VariableSymbol::format('2026', $alice)]);
		(new PaymentMatcher($this->db, new VariableSymbol($this->settings()), $this->eventLog()))->apply((int) $this->db->lastInsertId());

		$bob = $this->reservation('bob', [201], 1, $this->yesterday);
		$this->reservationAdmin()->markPaid($bob);

		$carol = $this->reservation('carol', [202], 0, $this->twoDaysAgo);
		$this->reservationAdmin()->cancel($carol);

		$tester = $this->reservation('tester', [203], 0, $this->today);
		$this->db->exec("UPDATE reservations SET channel = 'test' WHERE id = $tester");
	}


	public function testSalesByDayOfTheReservation(): void
	{
		$days = array_column($this->stats()->daily(), null, 'date');

		self::assertSame([$this->twoDaysAgo, $this->yesterday, $this->today], array_keys($days), 'Every day up to today');
		self::assertSame(
			['reservations' => 1, 'seats' => 2, 'standing' => 0, 'value' => 70000, 'cancelled' => 1, 'cancelled_tickets' => 1,
				'paid_bank' => 0, 'paid_cash' => 0],
			array_diff_key($days[$this->twoDaysAgo], ['date' => 0]),
		);
		self::assertSame([1, 1, 1, 60000, 70000], [
			$days[$this->yesterday]['reservations'], $days[$this->yesterday]['seats'], $days[$this->yesterday]['standing'],
			$days[$this->yesterday]['value'], $days[$this->yesterday]['paid_bank'],
		]);
		self::assertSame([0, 60000], [$days[$this->today]['reservations'], $days[$this->today]['paid_cash']], 'The tester is not counted');

		$from = $this->stats()->daily(new DateTimeImmutable($this->yesterday));
		self::assertSame([$this->yesterday, $this->today], array_column($from, 'date'));
	}


	public function testRevenue(): void
	{
		$money = $this->stats()->revenue();

		self::assertSame([2, 3, 130000, 130000, 70000, 60000, 0], [
			$money['reservations'], $money['tickets'] - 1, $money['ordered'], $money['paid'], $money['paid_bank'], $money['paid_cash'], $money['unpaid'],
		]);
		self::assertSame([130000, 0, 130000, 32500], [$money['received'], $money['refunded'], $money['net'], $money['per_ticket']]);
		self::assertSame(['reservations' => 2, 'tickets' => 4, 'ordered' => 130000, 'paid' => 130000], $this->stats()->byChannel()['public']);
	}


	public function testTablesAndCsv(): void
	{
		$this->db->exec("UPDATE hall_tables SET available = 0 WHERE label = 'A2'");
		$tables = array_column($this->stats()->tables(), null, 'table');

		self::assertSame(['seats' => 4, 'sold' => 2, 'paid' => 2, 'free' => 2, 'unavailable' => 0], array_intersect_key($tables['A1'], array_flip(['seats', 'sold', 'paid', 'free', 'unavailable'])));
		self::assertSame([2, 0, 2], [$tables['A2']['sold'], $tables['A2']['free'], $tables['A2']['unavailable']], 'Bob and the tester sit at A2, Carol left');
		self::assertSame('Hlavní sál', $tables['A1']['area']);

		$csv = Csv::build(['Stůl', 'Míst', 'Poznámka'], [['A1', 4, '=SUM(A1)'], ['A2', 1.5, 'a;b']]);
		self::assertSame("\u{FEFF}\"Stůl\";\"Míst\";\"Poznámka\"\r\n\"A1\";\"4\";\"'=SUM(A1)\"\r\n\"A2\";\"1,5\";\"a;b\"\r\n", $csv);
		self::assertSame("950\u{A0}Kč", TemplateExtension::formatHellers(95000));
		self::assertSame("950,50\u{A0}Kč", TemplateExtension::formatHellers(95050));
	}


	private function stats(): SalesStats
	{
		return new SalesStats($this->db, $this->settings(), new FrozenClock(new DateTimeImmutable('today 12:00')));
	}


	/** @param list<int> $seats */
	private function reservation(string $owner, array $seats, int $standing, string $day): int
	{
		$service = $this->reservations();
		foreach ($seats as $seat) {
			$service->hold($owner, $seat);
		}
		if ($standing) {
			$service->setStanding($owner, $standing);
		}
		$id = $service->confirm($owner, ucfirst($owner), '', true, "$owner@example.com");
		$this->db->prepare('UPDATE reservations SET confirmed_at = ? WHERE id = ?')->execute(["$day 10:00:00", $id]);
		return $id;
	}
}
