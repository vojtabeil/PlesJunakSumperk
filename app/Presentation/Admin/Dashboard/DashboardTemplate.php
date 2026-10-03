<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Presentation\Admin\BaseTemplate;


final class DashboardTemplate extends BaseTemplate
{
	/** @var array<string, int> */
	public array $stats;

	public bool $saleOpen;

	/** @var list<array<string, mixed>> */
	public array $recentReservations;

	/** @var list<array<string, mixed>> */
	public array $activity;
}
