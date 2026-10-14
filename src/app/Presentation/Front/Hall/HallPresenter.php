<?php

declare(strict_types=1);

namespace App\Presentation\Front\Hall;

use App\Model\Hall\HallSync;
use App\Presentation\Accessory\SvgResponse;
use App\Presentation\Front\BasePresenter;


/**
 * The hall plan as a file (/plan-salu.svg?v=<hash>), loaded by the seat picker. With the hash of the
 * current plan it may be cached for long (a new plan has a new hash); 404 while no plan is uploaded.
 */
final class HallPresenter extends BasePresenter
{
	private const CacheSeconds = 31536000;


	public function __construct(
		private readonly HallSync $sync,
	) {
		parent::__construct();
	}


	public function actionSvg(?string $v = null): void
	{
		$plan = $this->sync->plan() ?? $this->error('Plánek sálu zatím není nahraný.');
		$this->sendResponse(new SvgResponse($plan['svg'], 'plan-salu.svg', maxAge: $v === $plan['hash'] ? self::CacheSeconds : 0));
	}
}