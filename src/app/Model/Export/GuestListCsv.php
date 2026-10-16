<?php

declare(strict_types=1);

namespace App\Model\Export;


/** CSV export of the guest list, ready for Excel with Czech locale (Csv). */
final class GuestListCsv
{
	private const StatusLabels = ['confirmed' => 'nezaplaceno', 'partially_paid' => 'částečně zaplaceno', 'paid' => 'zaplaceno'];


	/** @param list<array<string, mixed>> $rows from ReservationAdmin::guestList() */
	public static function build(array $rows): string
	{
		$header = ['Číslo', 'Jméno', 'E-mail', 'Telefon', 'Místa', 'Počet míst', 'Bez místenky', 'Cena', 'Zaplaceno Kč', 'Stav',
			'Zaplaceno dne', 'Vstup (použité vstupenky)', 'Poznámka'];
		return Csv::build($header, array_map(static fn(array $row): array => [
			$row['id'],
			$row['name'],
			$row['email'],
			$row['phone'] ?? '',
			$row['seat_labels'] ?? '',
			$row['seat_count'],
			$row['standing_tickets'],
			$row['total_price'],
			(int) $row['paid_amount'],
			self::StatusLabels[$row['status']] ?? $row['status'],
			$row['paid_at'] ?? '',
			$row['used_tickets'],
			$row['note'] ?? '',
		], $rows));
	}
}
