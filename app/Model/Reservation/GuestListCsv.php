<?php

declare(strict_types=1);

namespace App\Model\Reservation;


/** CSV export of the guest list, ready for Excel with Czech locale (UTF-8 BOM, semicolons). */
final class GuestListCsv
{
	private const StatusLabels = ['confirmed' => 'nezaplaceno', 'paid' => 'zaplaceno'];


	/** @param list<array<string, mixed>> $rows from ReservationAdmin::guestList() */
	public static function build(array $rows): string
	{
		$lines = [self::line(['Číslo', 'Jméno', 'E-mail', 'Telefon', 'Místa', 'Počet míst', 'Bez místenky', 'Cena', 'Stav', 'Zaplaceno', 'Poznámka'])];
		foreach ($rows as $row) {
			$lines[] = self::line([
				$row['id'],
				$row['name'],
				$row['email'],
				$row['phone'] ?? '',
				$row['seat_labels'] ?? '',
				$row['seat_count'],
				$row['standing_tickets'],
				$row['total_price'],
				self::StatusLabels[$row['status']] ?? $row['status'],
				$row['paid_at'] ?? '',
				$row['note'] ?? '',
			]);
		}
		return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
	}


	/** @param list<mixed> $values */
	private static function line(array $values): string
	{
		return implode(';', array_map(self::cell(...), $values));
	}


	/** Quotes the value and neutralizes text Excel would run as a formula (CSV injection). */
	private static function cell(mixed $value): string
	{
		$value = (string) $value;
		if (preg_match('/^[=+\-@\t\r]/', $value)) {
			$value = "'" . $value;
		}
		return '"' . str_replace('"', '""', $value) . '"';
	}
}
