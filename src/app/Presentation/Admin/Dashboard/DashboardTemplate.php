<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Model\Site\SiteMode;
use App\Presentation\Admin\BaseTemplate;


final class DashboardTemplate extends BaseTemplate
{
	/** @var array<string, int> */
	public array $stats;

	public SiteMode $siteMode;

	/** @var array<string, int> */
	public array $seatCounts;

	/** @var list<array{key: string, value: int, percent: float, path: ?string}> */
	public array $slices;

	public int $seatTotal;

	/** @var list<array<string, mixed>> */
	public array $seatRows;

	public ?string $seatFilter;

	public int $paymentProblems;

	/** @var array<string, int> */
	public array $reservationProblems;

	/** Overdue reservations without a recent reminder. */
	public int $toRemind;

	public ?\DateTimeImmutable $lastImportAt;

	public bool $importOverdue;

	/** @var array{used: int, total: int, kinds: array<string, array{used: int, total: int}>} tickets used at the entrance */
	public array $entrance;

	/** @var array<string, array{label: string, count: int, capacity: ?int, note: ?string}> seat, standing, crew */
	public array $ticketKinds;

	/** A hall plan is uploaded (without it seats cannot be sold). */
	public bool $hasPlan;
}
