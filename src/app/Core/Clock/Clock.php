<?php

declare(strict_types=1);

namespace App\Core\Clock;

use DateTimeImmutable;


/** Current time; replaced by a fixed clock in tests. */
interface Clock
{
	public function now(): DateTimeImmutable;
}
