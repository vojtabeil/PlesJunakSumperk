<?php

declare(strict_types=1);

namespace App\Model\Payment\Fio;

use App\Model\Payment\BankTransaction;
use DateTimeImmutable;
use DateTimeZone;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use UnexpectedValueException;


/**
 * Parses the JSON statement of the Fio API ("API Bankovnictví" manual, chapter TransactionList).
 * Columns: 22 = movement id, 0 = date, 1 = amount, 14 = currency, 5 = VS, 2/3 = counter account/bank,
 * 10 = counter account name, 7 = user identification, 16 = message for the recipient.
 */
final class FioResponseParser
{
	/** @return list<BankTransaction> */
	public static function parse(string $json): array
	{
		try {
			$data = Json::decode($json, forceArrays: true);
		} catch (JsonException $e) {
			throw new UnexpectedValueException('Fio response is not valid JSON.', previous: $e);
		}
		if (!is_array($data) || !isset($data['accountStatement']) || !is_array($data['accountStatement'])) {
			throw new UnexpectedValueException('Fio response has no accountStatement.');
		}

		$transactions = $data['accountStatement']['transactionList']['transaction'] ?? [];
		if (!is_array($transactions)) {
			throw new UnexpectedValueException('Fio response has an invalid transactionList.');
		}
		return array_values(array_map(self::transaction(...), $transactions));
	}


	/** @param array<string, mixed> $t */
	private static function transaction(array $t): BankTransaction
	{
		$id = self::value($t, 22) ?? throw new UnexpectedValueException('Fio transaction without id (column22).');
		$amount = self::value($t, 1) ?? throw new UnexpectedValueException("Fio transaction $id without amount (column1).");
		$account = self::text($t, 2);
		$bank = self::text($t, 3);

		return new BankTransaction(
			externalId: (string) $id,
			bookedOn: self::date(self::value($t, 0)),
			amountHalers: (int) round((float) $amount * 100),
			currency: self::text($t, 14) ?? 'CZK',
			variableSymbol: self::text($t, 5),
			counterAccount: $account !== null ? $account . ($bank !== null ? "/$bank" : '') : null,
			counterName: self::text($t, 10) ?? self::text($t, 7),
			message: self::text($t, 16) ?? self::text($t, 7),
			raw: $t,
		);
	}


	/** @param array<string, mixed> $t */
	private static function value(array $t, int $column): mixed
	{
		$cell = $t["column$column"] ?? null;
		return is_array($cell) ? ($cell['value'] ?? null) : null;
	}


	/** @param array<string, mixed> $t */
	private static function text(array $t, int $column): ?string
	{
		$value = self::value($t, $column);
		$value = $value === null ? '' : trim((string) $value);
		return $value === '' ? null : $value;
	}


	/** JSON gives milliseconds since the epoch; XML-like "2026-02-01+0100" is accepted too. */
	private static function date(mixed $value): string
	{
		if (is_int($value) || is_float($value)) {
			return (new DateTimeImmutable('@' . intdiv((int) $value, 1000)))
				->setTimezone(new DateTimeZone('Europe/Prague'))
				->format('Y-m-d');
		}
		if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
			return substr($value, 0, 10);
		}
		throw new UnexpectedValueException('Fio transaction with an invalid date (column0).');
	}
}
