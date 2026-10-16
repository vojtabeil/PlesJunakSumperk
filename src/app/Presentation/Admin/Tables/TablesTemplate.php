<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Tables;

use App\Presentation\Admin\BaseTemplate;


final class TablesTemplate extends BaseTemplate
{
	/** @var list<array{id: int, label: string, name: ?string, svg_id: string, available: bool, seats: int, reserved: int, held: int}> */
	public array $tables;

	/** The hall plan (sanitized when uploaded, HallPlanParser); null while there is none. */
	public ?string $planSvg;
}
