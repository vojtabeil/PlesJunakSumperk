<?php

declare(strict_types=1);

namespace App\Model\Log;

use DateTimeImmutable;


/** Filter of the log page; null = no restriction. */
final class EventFilter
{
	public function __construct(
		public readonly ?DateTimeImmutable $from = null,
		public readonly ?DateTimeImmutable $to = null,
		public readonly ?string $category = null,
		public readonly ?string $actor = null,
		public readonly ?int $reservationId = null,
		public readonly ?int $seatId = null,
	) {
	}
}
