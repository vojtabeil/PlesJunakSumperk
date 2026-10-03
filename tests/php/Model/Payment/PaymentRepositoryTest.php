<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\PaymentRepository;
use App\Tests\DatabaseTestCase;


final class PaymentRepositoryTest extends DatabaseTestCase
{
	public function testFiltersAndSums(): void
	{
		$this->reservations()->setEmail('a', 'alice@example.com');
		$reservation = (int) $this->db->query('SELECT id FROM reservations')->fetchColumn();
		$rows = [
			['1', 350, $reservation, 'matched'],
			['2', 100, $reservation, 'underpaid'],
			['3', 200, null, 'no_vs'],
			['4', 5000, null, 'foreign'],
			['5', -300, null, 'outgoing'],
			['6', 50, null, 'ignored'],
		];
		$stmt = $this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, reservation_id, match_status) VALUES ('mock', ?, CURDATE(), ?, ?, ?)",
		);
		foreach ($rows as $row) {
			$stmt->execute($row);
		}
		$payments = new PaymentRepository($this->db);

		$summary = $payments->summary();
		self::assertSame(['count' => 6, 'amount' => 5400.0], $summary['']);
		self::assertSame(['count' => 2, 'amount' => 300.0], $summary['problems']);
		self::assertSame(['count' => 2, 'amount' => 450.0], $summary['matched']);
		self::assertSame(['count' => 1, 'amount' => 5000.0], $summary['foreign']);
		self::assertSame(['count' => 2, 'amount' => -250.0], $summary['other']);

		self::assertSame(['3', '2'], array_column($payments->search('problems'), 'external_id'));
		self::assertCount(6, $payments->search('unknown'), 'Unknown filter shows everything');
		self::assertSame(2, $payments->problemCount());
	}
}
