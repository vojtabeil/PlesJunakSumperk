<?php

declare(strict_types=1);

namespace App\Model\Payment;

use DateTimeImmutable;


/**
 * A source that can deliver movements again from a given day (Fio "set-last-date").
 * Used when an import was interrupted; already stored movements are skipped as duplicates.
 */
interface RewindableSource extends BankTransactionSource
{
	/** The oldest day that can be requested again (Fio: 90 days without extra authorization). */
	public const MaxRewindDays = 90;

	public function rewind(DateTimeImmutable $since): void;
}
