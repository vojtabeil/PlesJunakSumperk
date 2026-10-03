<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use Nette\Application\Attributes\Requires;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\ILogger;


/** Handles server errors: logs them and shows a static page (no layout, no database). */
#[Requires(forward: true)]
final class Error5xxPresenter implements IPresenter
{
	public function __construct(
		private readonly ILogger $logger,
	) {
	}


	public function run(Request $request): Response
	{
		$exception = $request->getParameter('exception');
		$this->logger->log($exception, ILogger::EXCEPTION);

		return new CallbackResponse(function (IRequest $httpRequest, IResponse $httpResponse): void {
			if (preg_match('#^text/html(?:;|$)#', (string) $httpResponse->getHeader('Content-Type'))) {
				require __DIR__ . '/500.phtml';
			}
		});
	}
}
