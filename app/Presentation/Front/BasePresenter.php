<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Log\Actor;
use App\Model\Reservation\Settings;
use Nette\Application\UI\Presenter;


/**
 * Common parts of the public pages: tester gate and event settings for the layout.
 * @property-read BaseTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	public Settings $settings;

	private TesterGate $testerGate;


	public function injectSettings(Settings $settings, Actor $actor, TesterGate $testerGate): void
	{
		$this->settings = $settings;
		$this->testerGate = $testerGate;
		$actor->asCustomer();
	}


	/** Before the site is public, visitors without the tester link only see "Připravujeme". */
	protected function startup(): void
	{
		parent::startup();
		if (!$this->testerGate->allows()) {
			$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
			$this->template->settings = $this->settings->all();
			$this->template->setFile(__DIR__ . '/preparing.latte');
			$this->sendTemplate();
		}
	}


	protected function beforeRender(): void
	{
		$this->template->settings = $this->settings->all();
	}
}
