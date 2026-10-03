<?php

declare(strict_types=1);

namespace App\Model\Clock;

use DateTimeImmutable;


/** Current time; replaced by a fixed clock in tests. */
interface Clock
{
	public function now(): DateTimeImmutable;
}
