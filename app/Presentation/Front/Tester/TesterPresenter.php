<?php

declare(strict_types=1);

namespace App\Presentation\Front\Tester;

use App\Presentation\Front\TesterGate;
use Nette\Application\UI\Presenter;


/** The tester link /tester/<token>: sets the tester cookie and opens the site. A wrong token is a plain 404. */
final class TesterPresenter extends Presenter
{
	public function __construct(
		private readonly TesterGate $gate,
	) {
		parent::__construct();
	}


	public function actionDefault(string $token): void
	{
		$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
		if (!$this->gate->admit($token)) {
			$this->error();
		}
		$this->redirect(':Front:Home:default');
	}
}
