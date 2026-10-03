<?php

declare(strict_types=1);

namespace App\Model\Payment;


/**
 * Where bank movements come from: the Fio API in production, the mock bank locally.
 * Chosen by the `bank.driver` parameter (see BankSourceFactory).
 */
interface BankTransactionSource
{
	/** Value of bank_transactions.source. */
	public function name(): string;

	/** Minimal number of seconds between two fetches (Fio allows one request per 30 s). */
	public function minIntervalSeconds(): int;

	/**
	 * Movements not delivered by any previous call. They are delivered only once,
	 * so the caller must store them before doing anything else.
	 * @return list<BankTransaction>
	 */
	public function fetchNew(): array;
}
