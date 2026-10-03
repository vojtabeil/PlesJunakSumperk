<?php

declare(strict_types=1);

namespace App\Model\Payment\Mock;

use App\Model\Payment\BankTransaction;
use App\Model\Payment\BankTransactionSource;
use PDO;
use Throwable;


/** Reads the fake bank (mock_bank_transactions) the same way FioApiSource reads the Fio API. */
final class MockBankSource implements BankTransactionSource
{
	public function __construct(
		private readonly PDO $db,
	) {
	}


	public function name(): string
	{
		return 'mock';
	}


	public function minIntervalSeconds(): int
	{
		return 0;
	}


	public function fetchNew(): array
	{
		$this->db->beginTransaction();
		try {
			$rows = $this->db->query('SELECT * FROM mock_bank_transactions WHERE fetched = 0 ORDER BY id FOR UPDATE')->fetchAll();
			$this->db->exec('UPDATE mock_bank_transactions SET fetched = 1 WHERE fetched = 0');
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return array_map(static fn(array $row): BankTransaction => new BankTransaction(
			externalId: 'mock-' . $row['id'],
			bookedOn: (string) $row['booked_on'],
			amountHalers: (int) round((float) $row['amount'] * 100),
			currency: (string) $row['currency'],
			variableSymbol: $row['variable_symbol'],
			counterAccount: $row['counter_account'],
			counterName: $row['counter_name'],
			message: $row['message'],
			raw: $row,
		), $rows);
	}
}
