<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Log\Actor;
use App\Model\Reservation\Settings;
use Nette\Application\UI\Presenter;


/**
 * Common parts of the public pages: event settings for the layout.
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
	}
}
