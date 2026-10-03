<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Payment\Mock\MockBankSource;
use PDO;
use RuntimeException;


/** Picks the bank implementation from the `bank` parameter in config/local.neon. */
final class BankSourceFactory
{
	/** @param array{driver?: string} $config */
	public static function create(array $config, PDO $db): BankTransactionSource
	{
		return match ($config['driver'] ?? 'mock') {
			'mock' => new MockBankSource($db),
			'fio' => throw new RuntimeException('The Fio bank driver is implemented in phase 5 (see docs/plan.md).'),
			default => throw new RuntimeException("Unknown bank driver '{$config['driver']}' (use mock or fio)."),
		};
	}
}
