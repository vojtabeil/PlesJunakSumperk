<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Presentation\Front\BaseTemplate;


final class HomeTemplate extends BaseTemplate
{
	public bool $saleOpen;

	/** @var array{width: int, height: int, areas: list<array<string, int|string>>, tables: list<array<string, int|string>>, seats: list<array<string, int|string>>} */
	public array $layout;

	public string $pickerData;
}
