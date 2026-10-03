<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use Nette\Application\UI\Form;
use Nette\Forms\Rendering\DefaultFormRenderer;


/** Forms with CSRF protection and a div-based layout (styled by assets/scss/admin.scss). */
final class FormFactory
{
	public function create(): Form
	{
		$form = new Form;
		$form->addProtection('Platnost formuláře vypršela, odešlete ho prosím znovu.');

		$renderer = $form->getRenderer();
		assert($renderer instanceof DefaultFormRenderer);
		$renderer->wrappers['controls']['container'] = 'div class=form-fields';
		$renderer->wrappers['pair']['container'] = 'div class=form-field';
		$renderer->wrappers['pair']['.required'] = 'form-field--required';
		$renderer->wrappers['pair']['.error'] = 'form-field--error';
		$renderer->wrappers['label']['container'] = null;
		$renderer->wrappers['control']['container'] = null;
		$renderer->wrappers['control']['description'] = 'small class=form-description';
		$renderer->wrappers['control']['errorcontainer'] = 'span class=form-error';
		$renderer->wrappers['error']['container'] = 'ul class=form-errors';
		$renderer->wrappers['control']['.submit'] = 'button button--primary';
		return $form;
	}
}
