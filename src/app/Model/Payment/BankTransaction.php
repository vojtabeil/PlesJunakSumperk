<?php

declare(strict_types=1);

namespace App\Model\Payment;


/** One movement on the bank account, as delivered by a BankTransactionSource. */
final class BankTransaction
{
	/** @param array<string, mixed> $raw original data from the source (stored for audits) */
	public function __construct(
		public readonly string $externalId,
		public readonly string $bookedOn,
		/** Amount in hellers (1 CZK = 100); negative for outgoing payments. */
		public readonly int $amountHalers,
		public readonly string $currency = 'CZK',
		public readonly ?string $variableSymbol = null,
		public readonly ?string $counterAccount = null,
		public readonly ?string $counterName = null,
		public readonly ?string $message = null,
		public readonly array $raw = [],
	) {
	}
}
