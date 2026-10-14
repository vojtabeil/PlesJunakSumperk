<?php

declare(strict_types=1);

namespace App\Core\Clock;

use DateTimeImmutable;


final class SystemClock implements Clock
{
	public function now(): DateTimeImmutable
	{
		return new DateTimeImmutable;
	}
}
