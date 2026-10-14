<?php

declare(strict_types=1);

namespace App\Model\Crew;


/**
 * Timetable of the crew printed on the Z-lístky and sent with them (setting crew_schedule):
 * one line per item, cells separated by "|", e.g. "Přípravy v pátek | 3. 2. | 16:00–18:30".
 */
final class CrewSchedule
{
	/** @return list<list<string>> rows of trimmed cells; empty lines are skipped */
	public static function parse(string $text): array
	{
		$rows = [];
		foreach (preg_split('~\R~u', $text) ?: [] as $line) {
			$cells = array_map('trim', explode('|', $line));
			if (implode('', $cells) !== '') {
				$rows[] = $cells;
			}
		}
		return $rows;
	}
}
