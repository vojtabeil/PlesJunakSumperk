<?php

declare(strict_types=1);

namespace App\Presentation\Front\Access;

use App\Presentation\Front\VisitorGate;
use Nette\Application\UI\Presenter;


/**
 * Secret links /tester/<token> and /vip/<token>: set the cookie and open the site.
 * A wrong token is a plain 404.
 */
final class AccessPresenter extends Presenter
{
	public function __construct(
		private readonly VisitorGate $gate,
	) {
		parent::__construct();
	}


	public function actionTester(string $token): void
	{
		$this->admit($this->gate->admitTester($token));
	}


	public function actionVip(string $token): void
	{
		$this->admit($this->gate->admitVip($token));
	}


	private function admit(bool $admitted): void
	{
		$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
		if (!$admitted) {
			$this->error();
		}
		$this->redirect(':Front:Home:default');
	}
}
