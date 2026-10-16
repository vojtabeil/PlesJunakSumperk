<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Statistics;

use App\Presentation\Admin\BaseTemplate;


final class StatisticsTemplate extends BaseTemplate
{
	/** @var array<string, array<string, mixed>> the three kinds of tickets (TicketBars) */
	public array $bars;

	/** @var array{used: int, total: int, kinds: array<string, array{used: int, total: int}>} */
	public array $entrance;

	public int $seatsUnavailable;

	/** @var list<array<string, mixed>> SalesStats::daily */
	public array $days;

	/** @var array<string, mixed> BarChart::build of the days */
	public array $dailyChart;

	/** @var array<string, int> SalesStats::revenue (hellers) */
	public array $revenue;

	/** @var array<string, array{reservations: int, tickets: int, ordered: int, paid: int}> */
	public array $channels;

	/** @var list<array<string, mixed>> SalesStats::tables */
	public array $tables;

	/** @var array<string, array<string, int>> part of the hall => sums of its tables */
	public array $areas;

	/** @var array<string, int> standing tickets: unpaid, partial, paid, capacity */
	public array $standing;
}
