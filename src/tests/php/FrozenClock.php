<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\Clock\Clock;
use DateTimeImmutable;


/** Clock with a time set by the test. */
final class FrozenClock implements Clock
{
	public function __construct(
		public DateTimeImmutable $now = new DateTimeImmutable('2026-02-01 12:00:00'),
	) {
	}


	public function now(): DateTimeImmutable
	{
		return $this->now;
	}


	public function advance(int $seconds): void
	{
		$this->now = $this->now->modify("+$seconds seconds");
	}
}
