<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\Front\BasePresenter;
use Nette\Application\Attributes\Requires;
use Nette\Application\BadRequestException;


/**
 * Renders 4xx errors (e.g. 404) with the normal page layout.
 * @property-read Error4xxTemplate $template
 */
#[Requires(methods: '*', forward: true)]
final class Error4xxPresenter extends BasePresenter
{
	public function renderDefault(BadRequestException $exception): void
	{
		$this->template->httpCode = $exception->getHttpCode();
	}
}
