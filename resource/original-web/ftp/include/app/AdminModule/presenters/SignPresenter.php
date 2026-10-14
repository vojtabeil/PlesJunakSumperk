<?php

namespace AdminModule;

use Nette\Application\UI;


/**
 * Sign in/out presenters.
 */
class SignPresenter extends \BasePresenter
{


	/**
	 * Sign-in form factory.
	 * @return Nette\Application\UI\Form
	 */
	protected function createComponentSignInForm()
	{
		$form = new UI\Form;
		$form->addText('username', 'Uživatelské jméno:')
			->setRequired('Prosím zadejte uživatelské jméno.');

		$form->addPassword('password', 'Heslo:')
			->setRequired('Prosím zadejte heslo.');

		$form->addCheckbox('remember', 'Zapamatovat si přihlášení.');

		$form->addSubmit('send', 'Přihlásit se');

		// call method signInFormSucceeded() on success
		$form->onSuccess[] = $this->signInFormSucceeded;
		return $form;
	}



	public function signInFormSucceeded($form)
	{
		$values = $form->getValues();

		if ($values->remember) {
			$this->getUser()->setExpiration('14 days', FALSE);
		} else {
			$this->getUser()->setExpiration('20 minutes', TRUE);
		}

		try {
			$this->getUser()->login($values->username, $values->password);
			$this->redirect(':Admin:Tickets:');
			
		} catch (\Nette\Security\AuthenticationException $e) {
			$this->flashMessage("Bylo zadáno špatné uživatelské jméno nebo heslo.");
			$this->redirect('this');
		}
	}



	public function actionOut()
	{
		$this->getUser()->logout();
		$this->flashMessage('Odhlášení proběhlo v pořádku.');
		$this->redirect('in');
	}

}
