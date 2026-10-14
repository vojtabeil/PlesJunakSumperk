<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Log\Actor;
use App\Model\Reservation\Settings;
use App\Model\Reservation\SiteMode;
use Nette\Application\UI\Presenter;


/**
 * Common parts of the public pages: event settings and the stage of the site for the layout.
 * @property-read BaseTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	public Settings $settings;


	public function injectSettings(Settings $settings, Actor $actor): void
	{
		$this->settings = $settings;
		$actor->asCustomer();
	}


	protected function beforeRender(): void
	{
		$this->template->settings = $this->settings->all();
		$this->template->siteMode = $this->settings->mode();
		$preview = $this->getParameter('preview');
		if (is_string($preview) && $this->getUser()->isLoggedIn()) {
			$this->template->siteMode = SiteMode::tryFrom($preview) ?? $this->template->siteMode;
		}
	}
}
