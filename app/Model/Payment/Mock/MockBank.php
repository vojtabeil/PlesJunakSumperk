<?php

declare(strict_types=1);

namespace App\Model\Payment\Mock;

use PDO;


/** Creating fake incoming payments for the dev page /dev/bank. */
final class MockBank
{
	public function __construct(
		private readonly PDO $db,
	) {
	}


	public function addPayment(
		int $amountHalers,
		?string $variableSymbol,
		string $counterName = 'Testovací Plátce',
		string $counterAccount = '123456789/0800',
		string $message = '',
	): int {
		$this->db->prepare(
			'INSERT INTO mock_bank_transactions (booked_on, amount, variable_symbol, counter_account, counter_name, message)
			VALUES (CURDATE(), ?, ?, ?, ?, ?)',
		)->execute([
			number_format($amountHalers / 100, 2, '.', ''),
			$variableSymbol === '' ? null : $variableSymbol,
			$counterAccount,
			$counterName,
			$message === '' ? null : $message,
		]);
		return (int) $this->db->lastInsertId();
	}


	/** @return list<array<string, mixed>> newest first */
	public function all(): array
	{
		return $this->db->query('SELECT * FROM mock_bank_transactions ORDER BY id DESC')->fetchAll();
	}


	/** Removes not yet fetched payments (already imported ones stay in bank_transactions). */
	public function clearPending(): int
	{
		return (int) $this->db->exec('DELETE FROM mock_bank_transactions WHERE fetched = 0');
	}
}
