<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Http\HttpClient;
use App\Model\Payment\Fio\FioApiSource;
use App\Model\Payment\Mock\MockBankSource;
use PDO;
use RuntimeException;


/** Picks the bank implementation from the `bank` parameter in config/local.neon. */
final class BankSourceFactory
{
	/** @param array{driver?: string, token?: string} $config */
	public static function create(array $config, PDO $db, HttpClient $http): BankTransactionSource
	{
		return match ($config['driver'] ?? 'mock') {
			'mock' => new MockBankSource($db),
			'fio' => new FioApiSource((string) ($config['token'] ?? ''), $http),
			default => throw new RuntimeException("Unknown bank driver '{$config['driver']}' (use mock or fio)."),
		};
	}
}
