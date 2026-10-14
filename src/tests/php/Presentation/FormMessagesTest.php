<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\Accessory\FormFactory;
use App\Tests\Support\DatabaseTestCase;
use Nette\Forms\Validator;


/** The site is in Czech: no English default of Nette Forms may reach a visitor or an organizer. */
final class FormMessagesTest extends DatabaseTestCase
{
	public function testEveryDefaultValidationMessageIsCzech(): void
	{
		$this->service(FormFactory::class); // the container sets the messages (config/common.neon, forms)
		foreach (Validator::$messages as $key => $message) {
			self::assertDoesNotMatchRegularExpression(
				'~\b(Please|This|field|value|enter|uploaded|session|error|must)\b~i',
				$message,
				"Message '$key' is still English",
			);
		}
	}


	public function testBrowserValidationIsOff(): void
	{
		$form = $this->service(FormFactory::class)->create();
		self::assertTrue($form->getElementPrototype()->getAttribute('novalidate'), 'The browser would answer in its own language');
	}
}
