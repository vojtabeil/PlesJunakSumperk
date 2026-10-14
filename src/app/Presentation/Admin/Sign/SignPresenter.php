<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Sign;

use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Security\AuthenticationException;


/** @property-read SignTemplate $template */
final class SignPresenter extends BasePresenter
{
	#[Persistent]
	public string $backlink = '';


	public function actionIn(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Dashboard:default');
		}
	}


	public function actionOut(): void
	{
		$this->getUser()->logout(clearIdentity: true);
		$this->flashMessage('Byli jste odhlášeni.', 'info');
		$this->redirect('in');
	}


	protected function createComponentSignInForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('login', 'Přihlašovací jméno')
			->setHtmlAttribute('autocomplete', 'username')
			->setRequired('Zadejte přihlašovací jméno.');
		$form->addPassword('password', 'Heslo')
			->setHtmlAttribute('autocomplete', 'current-password')
			->setRequired('Zadejte heslo.');
		$form->addSubmit('send', 'Přihlásit');

		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			try {
				$this->getUser()->login($data->login, $data->password);
			} catch (AuthenticationException $e) {
				$form->addError($e->getMessage());
				return;
			}
			$this->restoreRequest($this->backlink);
			$this->redirect('Dashboard:default');
		};
		return $form;
	}


	protected function isPublic(): bool
	{
		return true;
	}
}
