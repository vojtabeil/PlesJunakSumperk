<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;


/** Slices of an SVG pie chart (viewBox -1 -1 2 2), rendered by the template without JavaScript. */
final class PieChart
{
	/**
	 * @param array<string, int> $values key => value (zero values are skipped)
	 * @return list<array{key: string, value: int, percent: float, path: ?string}> path null = full circle
	 */
	public static function slices(array $values): array
	{
		$total = array_sum($values);
		if ($total <= 0) {
			return [];
		}

		$slices = [];
		$angle = -M_PI / 2; // start at 12 o'clock
		foreach ($values as $key => $value) {
			if ($value <= 0) {
				continue;
			}
			$fraction = $value / $total;
			$path = null;
			if ($fraction < 1) {
				$end = $angle + 2 * M_PI * $fraction;
				$path = sprintf(
					'M 0 0 L %.4F %.4F A 1 1 0 %d 1 %.4F %.4F Z',
					cos($angle),
					sin($angle),
					$fraction > 0.5 ? 1 : 0,
					cos($end),
					sin($end),
				);
				$angle = $end;
			}
			$slices[] = ['key' => (string) $key, 'value' => $value, 'percent' => round($fraction * 100, 1), 'path' => $path];
		}
		return $slices;
	}
}
