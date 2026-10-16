<?php

declare(strict_types=1);

namespace App\Tests\Model\Settings;

use App\Model\Settings\EventDate;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;


final class EventDateTest extends DatabaseTestCase
{
	public function testTextOfTheDate(): void
	{
		self::assertSame('7. 2. 2026 od 19:00', EventDate::fromValues('2026-02-07', '19:00')?->text());
		self::assertSame('7. 2. 2026', EventDate::fromValues('2026-02-07')?->text());
		self::assertNull(EventDate::fromValues(''));
		self::assertNull(EventDate::fromValues('2026-02-30'), 'Not a date');
		self::assertNull(EventDate::fromValues('7. 2. 2026'));
	}


	public function testCountdown(): void
	{
		$date = EventDate::fromValues('2026-02-07', '19:00');
		self::assertNotNull($date);
		$at = static fn(string $now): string => $date->countdown(new DateTimeImmutable($now));

		self::assertSame('Do plesu zbývá 12 dní', $at('2026-01-26 23:59'));
		self::assertSame('Do plesu zbývají 2 dny', $at('2026-02-05 08:00'));
		self::assertSame('Ples je zítra', $at('2026-02-06 00:00'));
		self::assertSame('Ples je dnes', $at('2026-02-07 23:30'));
		self::assertSame('Ples už proběhl (7. 2. 2026)', $at('2026-02-08 01:00'));
	}


	public function testSettingsGiveTheTextToTheTemplates(): void
	{
		self::assertSame('', $this->settings()->all()['event_date'], 'Not set yet');

		$this->setSettings(['event_day' => '2026-02-07', 'event_time' => '19:00']);
		self::assertSame('7. 2. 2026 od 19:00', $this->settings()->all()['event_date']);
		self::assertSame('2026-02-07', $this->settings()->eventDate()?->day->format('Y-m-d'));
	}
}
