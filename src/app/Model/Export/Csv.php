<?php

declare(strict_types=1);

namespace App\Model\Export;


/** CSV for Excel with Czech locale: UTF-8 with BOM, semicolons, CRLF, every value quoted. */
final class Csv
{
	/**
	 * @param list<string> $header
	 * @param iterable<list<mixed>> $rows
	 */
	public static function build(array $header, iterable $rows): string
	{
		$lines = [self::line($header)];
		foreach ($rows as $row) {
			$lines[] = self::line($row);
		}
		return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
	}


	/** @param list<mixed> $values */
	private static function line(array $values): string
	{
		return implode(';', array_map(self::cell(...), $values));
	}


	/**
	 * Quotes the value and neutralizes text Excel would run as a formula (CSV injection). Numbers keep their
	 * sign and get a decimal comma (Czech Excel).
	 */
	private static function cell(mixed $value): string
	{
		if (is_int($value) || is_float($value)) {
			return '"' . str_replace('.', ',', (string) $value) . '"';
		}
		$value = is_scalar($value) ? (string) $value : '';
		if (preg_match('/^[=+\-@\t\r]/', $value)) {
			$value = "'" . $value;
		}
		return '"' . str_replace('"', '""', $value) . '"';
	}
}
