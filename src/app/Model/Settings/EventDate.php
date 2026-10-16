<?php

declare(strict_types=1);

namespace App\Model\Settings;

use DateTimeImmutable;


/** Date of the ball (settings event_day, YYYY-MM-DD) and its start (event_time, HH:MM, optional). */
final class EventDate
{
	public function __construct(
		public readonly DateTimeImmutable $day,
		public readonly ?string $time = null,
	) {
	}


	/** From the stored values; null while the date is not set (or not a valid date). */
	public static function fromValues(string $day, string $time = ''): ?self
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
		if ($date === false || $date->format('Y-m-d') !== $day) {
			return null;
		}
		return new self($date, preg_match('~^\d{1,2}:\d{2}$~', $time) ? $time : null);
	}


	/** "7. 2. 2026 od 19:00" (without the time when it is not set), as shown on the site, tickets and e-mails. */
	public function text(): string
	{
		return $this->day->format('j. n. Y') . ($this->time !== null ? " od {$this->time}" : '');
	}


	/** Whole days from $now to the ball: negative after it, 0 on its day. */
	public function daysFrom(DateTimeImmutable $now): int
	{
		$today = $now->setTime(0, 0);
		return (int) $today->diff($this->day)->format('%r%a');
	}


	/** For the administration: "Do plesu zbývá 12 dní", "Ples je zítra", "Ples je dnes", "Ples už proběhl (7. 2. 2026)". */
	public function countdown(DateTimeImmutable $now): string
	{
		$days = $this->daysFrom($now);
		return match (true) {
			$days > 1 => sprintf('Do plesu %s %d %s', $days <= 4 ? 'zbývají' : 'zbývá', $days, $days <= 4 ? 'dny' : 'dní'),
			$days === 1 => 'Ples je zítra',
			$days === 0 => 'Ples je dnes',
			default => 'Ples už proběhl (' . $this->day->format('j. n. Y') . ')',
		};
	}
}
