<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accessory;


/**
 * Geometry of a stacked column chart drawn as SVG by barChart.latte (no chart library, no JavaScript):
 * one column per row, its parts stacked from the bottom, an optional running total as a line on its own scale.
 * Coordinates are in a 100-high box, one column = 10 wide; with few rows the chart keeps room for $minColumns,
 * so that a single day is not one wide block.
 */
final class BarChart
{
	public const Width = 10;
	public const Height = 100;


	/**
	 * @param list<array{label: string, title: string, parts: array<string, int>}> $rows label under the column (may
	 *     be ''), title = its tooltip, parts = key => value (the key is a CSS class suffix)
	 * @return array{bars: list<array{x: float, label: string, title: string, parts: list<array{key: string, y: float, h: float}>}>,
	 *     max: int, width: int, columns: int, line: string, lineMax: int}
	 */
	public static function build(array $rows, bool $runningTotal = false, int $minColumns = 14): array
	{
		$max = 0;
		foreach ($rows as $row) {
			$max = max($max, array_sum($row['parts']));
		}
		$bars = [];
		$sum = 0;
		$points = [];
		$lineMax = array_sum(array_map(static fn(array $row): int => array_sum($row['parts']), $rows));
		foreach ($rows as $i => $row) {
			$y = (float) self::Height;
			$parts = [];
			foreach ($row['parts'] as $key => $value) {
				$h = $max > 0 ? $value / $max * (self::Height - 4) : 0.0;
				if ($h > 0) {
					$y -= $h;
					$parts[] = ['key' => $key, 'y' => round($y, 2), 'h' => round($h, 2)];
				}
			}
			$x = $i * self::Width;
			$bars[] = ['x' => $x + 1.5, 'label' => $row['label'], 'title' => $row['title'], 'parts' => $parts];
			$sum += array_sum($row['parts']);
			if ($runningTotal && $lineMax > 0) {
				$points[] = round($x + self::Width / 2, 2) . ',' . round(self::Height - $sum / $lineMax * (self::Height - 4), 2);
			}
		}
		return [
			'bars' => $bars,
			'max' => $max,
			'width' => max(count($rows), $minColumns) * self::Width,
			'columns' => max(count($rows), $minColumns),
			'line' => implode(' ', $points),
			'lineMax' => $lineMax,
		];
	}
}
