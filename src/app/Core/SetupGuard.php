<?php

declare(strict_types=1);

namespace App\Core;

use App\Model\Admin\AdminUsers;
use Nette\Application\Application;
use Nette\Application\IPresenter;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;


/**
 * While no administrator exists the site is "not configured": every presenter except the
 * first-run wizard is disabled. Pages redirect to the wizard, the JSON API answers 503.
 * Registered on Application::$onPresenter in config/services.neon.
 */
final class SetupGuard
{
	/** Presenters that work without any administrator. */
	private const Allowed = ['Admin:Setup', 'Error:Error4xx'];

	/** Presenters for machines (JSON API, cron) answer 503 instead of a redirect. */
	private const Json = ['Front:Api', 'Front:Cron'];


	public function __construct(
		private readonly AdminUsers $users,
	) {
	}


	public function onPresenter(Application $application, IPresenter $presenter): void
	{
		if ($presenter instanceof Presenter) {
			$presenter->onStartup[] = $this->check(...);
		}
	}


	private function check(Presenter $presenter): void
	{
		$name = (string) $presenter->getName();
		if (in_array($name, self::Allowed, true) || $this->users->exists()) {
			return;
		}
		if (in_array($name, self::Json, true)) {
			$presenter->getHttpResponse()->setCode(IResponse::S503_ServiceUnavailable);
			$presenter->sendJson(['ok' => false, 'error' => 'Web zatím není nastavený.']);
		}
		$presenter->redirect(':Admin:Setup:default');
	}
}
