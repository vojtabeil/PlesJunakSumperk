<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Presentation\Admin\BaseTemplate;


final class DashboardTemplate extends BaseTemplate
{
	/** @var array<string, int> */
	public array $stats;

	public bool $saleOpen;

	public bool $testersOnly;

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

	public ?\DateTimeImmutable $lastImportAt;

	public bool $importOverdue;
}
