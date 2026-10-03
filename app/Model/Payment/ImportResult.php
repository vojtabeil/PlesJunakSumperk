<?php

declare(strict_types=1);

namespace App\Model\Payment;


final class ImportResult
{
	public function __construct(
		/** Movements delivered by the bank. */
		public int $fetched = 0,
		/** Newly stored (not seen before). */
		public int $stored = 0,
		/** Delivered again, already stored. */
		public int $duplicates = 0,
		/** Assigned to a reservation. */
		public int $matched = 0,
		/** Incoming payments that need the organizer's attention. */
		public int $unmatched = 0,
	) {
	}


	/** Czech summary for the admin flash message. */
	public function summary(): string
	{
		if ($this->fetched === 0) {
			return 'Žádné nové pohyby na účtu.';
		}
		$text = "Staženo pohybů: {$this->fetched}, spárováno s rezervacemi: {$this->matched}.";
		if ($this->unmatched > 0) {
			$text .= " K ručnímu přiřazení: {$this->unmatched}.";
		}
		return $text;
	}
}
