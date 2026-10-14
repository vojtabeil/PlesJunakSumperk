<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accessory;


/**
 * One horizontal stacked bar of the dashboard: parts of a whole (e.g. paid / unpaid / free seats)
 * as percentages of the bar. The whole is the capacity, or the sum of the parts when there is none.
 */
final class StackedBar
{
	/**
	 * @param array<string, int> $parts key => count, in the order they are drawn (left to right)
	 * @param int|null $capacity the full bar; the rest after the parts is the free track
	 * @return array{total: int, parts: list<array{key: string, value: int, percent: float}>, free: int}
	 */
	public static function build(array $parts, ?int $capacity = null): array
	{
		$used = array_sum($parts);
		$total = max($capacity ?? $used, $used);
		$segments = [];
		foreach ($parts as $key => $value) {
			$segments[] = ['key' => $key, 'value' => $value, 'percent' => $total > 0 ? round($value / $total * 100, 2) : 0.0];
		}
		return ['total' => $total, 'parts' => $segments, 'free' => $total - $used];
	}
}
