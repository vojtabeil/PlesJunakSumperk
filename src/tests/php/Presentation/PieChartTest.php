<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\Accessory\PieChart;
use PHPUnit\Framework\TestCase;


final class PieChartTest extends TestCase
{
	public function testSlicesSkipZerosAndUseLargeArcOverHalf(): void
	{
		$slices = PieChart::slices(['free' => 3, 'held' => 0, 'paid' => 1]);

		self::assertSame(['free', 'paid'], array_column($slices, 'key'));
		self::assertSame([75.0, 25.0], array_column($slices, 'percent'));
		self::assertSame('M 0 0 L 0.0000 -1.0000 A 1 1 0 1 1 -1.0000 0.0000 Z', $slices[0]['path']);
		self::assertStringContainsString(' 0 0 1 ', (string) $slices[1]['path'], 'Small slice uses the short arc');
	}


	public function testSingleValueIsAFullCircleAndEmptyGivesNothing(): void
	{
		self::assertSame([['key' => 'free', 'value' => 8, 'percent' => 100.0, 'path' => null]], PieChart::slices(['free' => 8, 'paid' => 0]));
		self::assertSame([], PieChart::slices(['free' => 0]));
	}
}
