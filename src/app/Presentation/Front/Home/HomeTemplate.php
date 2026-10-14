<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Model\Site\SiteMode;
use App\Presentation\Front\BaseTemplate;


final class HomeTemplate extends BaseTemplate
{
	public bool $canBuy;

	/** Page of the current stage for visitors who cannot buy (HTML written by organizers). */
	public string $pageHtml;

	public ?SiteMode $preview;

	/** URL of the hall plan (SVG), null while there is none. */
	public ?string $planUrl;

	public string $pickerData;
}
