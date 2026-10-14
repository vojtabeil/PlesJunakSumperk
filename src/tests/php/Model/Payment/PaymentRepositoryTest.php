<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\PaymentRepository;
use App\Tests\Support\DatabaseTestCase;


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
			['5', 50, null, 'ignored'],
		];
		$stmt = $this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, reservation_id, match_status) VALUES ('mock', ?, CURDATE(), ?, ?, ?)",
		);
		foreach ($rows as $row) {
			$stmt->execute($row);
		}
		$payments = new PaymentRepository($this->db);

		$summary = $payments->summary();
		self::assertSame(['count' => 5, 'amount' => 5700.0], $summary['all']);
		self::assertSame(['count' => 2, 'amount' => 300.0], $summary['problems']);
		self::assertSame(['count' => 1, 'amount' => 350.0], $summary['matched'], 'Underpaid is still to resolve');
		self::assertSame(['count' => 2, 'amount' => 5050.0], $summary['foreign'], 'Other VS and marked by hand');

		self::assertSame(['3', '2'], array_column($payments->search(), 'external_id'), 'The page opens with the payments to resolve');
		self::assertSame(['3', '2'], array_column($payments->search('unknown'), 'external_id'), 'Unknown filter = to resolve');
		self::assertSame(2, $payments->problemCount());
	}
}
