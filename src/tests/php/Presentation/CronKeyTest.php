<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\Cron\CronPresenter;
use PHPUnit\Framework\TestCase;


final class CronKeyTest extends TestCase
{
	public function testOnlyALongKeyOpensTheEndpoint(): void
	{
		$long = str_repeat('a1B2', 8);
		self::assertTrue(CronPresenter::keyMatches($long, $long));
		self::assertFalse(CronPresenter::keyMatches($long, $long . 'x'));
		self::assertFalse(CronPresenter::keyMatches($long, ''));
		self::assertFalse(CronPresenter::keyMatches('', ''), 'No key = no endpoint');
		self::assertFalse(CronPresenter::keyMatches('kratky-klic', 'kratky-klic'), 'A short key counts as not set');
	}
}