<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Account;

use App\Model\Admin\AdminUsers;
use App\Model\Admin\Authenticator;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Security\Passwords;


/**
 * The organizer's own account (password change, verified with the current password).
 * @property-read AccountTemplate $template
 */
final class AccountPresenter extends BasePresenter
{
	public function __construct(
		private readonly AdminUsers $users,
		private readonly Passwords $passwords,
		private readonly Authenticator $authenticator,
	) {
		parent::__construct();
	}


	protected function createComponentPasswordForm(): Form
	{
		$form = $this->formFactory->create();
		$current = $form->addPassword('current', 'Současné heslo')
			->setHtmlAttribute('autocomplete', 'current-password')
			->setRequired('Zadejte současné heslo.');
		$password = $form->addPassword('password', 'Nové heslo')
			->setHtmlAttribute('autocomplete', 'new-password')
			->setRequired('Zadejte nové heslo.')
			->addRule($form::MinLength, 'Heslo musí mít alespoň %d znaků.', AdminUsers::MinPasswordLength);
		$form->addPassword('passwordAgain', 'Nové heslo znovu')
			->setHtmlAttribute('autocomplete', 'new-password')
			->setRequired('Zadejte nové heslo ještě jednou.')
			->addRule($form::Equal, 'Hesla se neshodují.', $password);
		$form->addSubmit('save', 'Změnit heslo');

		$form->onSuccess[] = function (Form $form, \stdClass $data) use ($current): void {
			$account = $this->users->findById($this->adminId());
			if ($account === null || !$this->passwords->verify($data->current, (string) $account['password_hash'])) {
				$current->addError('Současné heslo nesouhlasí.');
				return;
			}
			$this->users->changePassword($this->adminId(), $data->password);
			$this->auditLog->record($this->adminId(), 'admin.password');
			// The password change ends all sessions; keep this one logged in.
			$fresh = $this->users->findById($this->adminId());
			if ($fresh !== null) {
				$this->getUser()->login($this->authenticator->identity($fresh));
			}
			$this->flashMessage('Heslo je změněné. Na ostatních zařízeních jste byli odhlášeni.', 'success');
			$this->redirect('this');
		};
		return $form;
	}
}
