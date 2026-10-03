<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Account;

use App\Model\Admin\AdminUsers;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Security\Passwords;


/**
 * The organizer's own account (password change).
 * @property-read AccountTemplate $template
 */
final class AccountPresenter extends BasePresenter
{
	public const MinPasswordLength = 10;


	public function __construct(
		private readonly AdminUsers $users,
		private readonly Passwords $passwords,
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
			->addRule($form::MinLength, 'Heslo musí mít alespoň %d znaků.', self::MinPasswordLength);
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
			$this->flashMessage('Heslo je změněné.', 'success');
			$this->redirect('this');
		};
		return $form;
	}
}
