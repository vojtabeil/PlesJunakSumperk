<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Site;

use App\Model\Reservation\SiteMode;
use App\Presentation\Admin\BaseTemplate;


final class SiteTemplate extends BaseTemplate
{
	public SiteMode $mode;

	/** @var list<SiteMode> */
	public array $modes;

	public string $testerLink;

	public string $vipLink;

	public int $testCount;

	/** @var array{test: int, vip: int, public: int} */
	public array $tickets;
}
