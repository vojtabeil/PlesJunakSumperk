<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Model\Reservation\SiteMode;
use App\Presentation\Front\BaseTemplate;


final class HomeTemplate extends BaseTemplate
{
	public bool $canBuy;

	/** Page of the current stage for visitors who cannot buy (HTML written by organizers). */
	public string $pageHtml;

	public ?SiteMode $preview;

	/** @var array{width: int, height: int, areas: list<array<string, int|string>>, tables: list<array<string, int|string>>, seats: list<array<string, int|string>>} */
	public array $layout;

	public string $pickerData;
}
