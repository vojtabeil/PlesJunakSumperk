<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Admin;

use App\Presentation\Admin\Accessory\StackedBar;
use PHPUnit\Framework\TestCase;


final class StackedBarTest extends TestCase
{
	public function testPartsOfACapacity(): void
	{
		$bar = StackedBar::build(['paid' => 25, 'unpaid' => 25], 200);
		self::assertSame(200, $bar['total']);
		self::assertSame(150, $bar['free']);
		self::assertSame([['key' => 'paid', 'value' => 25, 'percent' => 12.5], ['key' => 'unpaid', 'value' => 25, 'percent' => 12.5]], $bar['parts']);
	}


	public function testWithoutCapacityThePartsFillTheBar(): void
	{
		$bar = StackedBar::build(['picked_up' => 1, 'waiting' => 3]);
		self::assertSame([25.0, 75.0], array_column($bar['parts'], 'percent'));
		self::assertSame(0, $bar['free']);
	}


	public function testOversoldOrEmpty(): void
	{
		self::assertSame(12, StackedBar::build(['paid' => 12], 10)['total'], 'More sold than the capacity (it was lowered): the bar still fits');
		self::assertSame(0.0, StackedBar::build(['paid' => 0], 0)['parts'][0]['percent']);
	}
}
