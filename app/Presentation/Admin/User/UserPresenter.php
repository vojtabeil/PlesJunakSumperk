<?php

declare(strict_types=1);

namespace App\Presentation\Admin\User;

use App\Model\Admin\AdminError;
use App\Model\Admin\AdminUsers;
use App\Model\Admin\Authenticator;
use App\Presentation\Admin\AccountFields;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * Administrator accounts. All administrators are equal: any of them may create,
 * edit (including the password) and delete any account, except the last one.
 * @property-read UserTemplate $template
 */
final class UserPresenter extends BasePresenter
{
	/** @var array<string, mixed>|null account being edited */
	private ?array $account = null;


	public function __construct(
		private readonly AdminUsers $users,
		private readonly Authenticator $authenticator,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->accounts = $this->users->all();
		$this->template->currentId = $this->adminId();
	}


	public function actionEdit(int $id): void
	{
		$this->account = $this->users->findById($id) ?? $this->error('Účet neexistuje.');
	}


	public function renderEdit(): void
	{
		$this->template->account = $this->account;
		$this->template->currentId = $this->adminId();
		$this->template->isLocked = (int) ($this->account['failed_logins'] ?? 0) >= Authenticator::MaxFailures;
	}


	protected function createComponentAccountForm(): Form
	{
		$form = $this->formFactory->create();
		AccountFields::add($form, passwordRequired: $this->account === null);
		if ($this->account !== null) {
			if ((int) $this->account['failed_logins'] >= Authenticator::MaxFailures) {
				$form->addCheckbox('unlock', 'Odblokovat přihlašování (zablokováno po špatných heslech)');
			}
			$form->setDefaults(['login' => $this->account['login'], 'name' => $this->account['name']]);
		}
		$form->addSubmit('save', $this->account === null ? 'Vytvořit účet' : 'Uložit změny');
		$form->onSuccess[] = $this->saveAccount(...);
		return $form;
	}


	protected function createComponentDeleteForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('confirm', 'Opravdu smazat tento účet')
			->setRequired('Smazání potvrďte zaškrtnutím.');
		$form->addSubmit('delete', 'Smazat účet');
		$form->onSuccess[] = function (): void {
			$id = (int) $this->account['id'];
			try {
				$this->users->delete($id);
			} catch (AdminError $e) {
				$this->flashMessage($e->getMessage(), 'error');
				$this->redirect('this');
			}
			$this->auditLog->record($this->adminId() === $id ? null : $this->adminId(), 'admin.deleted', null, [
				'login' => $this->account['login'],
			]);
			if ($id === $this->adminId()) {
				$this->getUser()->logout(clearIdentity: true);
				$this->flashMessage('Váš účet byl smazán.', 'info');
				$this->redirect('Sign:in');
			}
			$this->flashMessage("Účet {$this->account['login']} je smazaný.", 'success');
			$this->redirect('default');
		};
		return $form;
	}


	private function saveAccount(Form $form, \stdClass $data): void
	{
		try {
			if ($this->account === null) {
				$id = $this->users->create($data->login, $data->name, $data->password);
				$this->auditLog->record($this->adminId(), 'admin.created', null, ['login' => $data->login]);
				$this->flashMessage("Účet {$data->login} je vytvořený.", 'success');
				$this->redirect('edit', $id);
			}

			$id = (int) $this->account['id'];
			$this->users->update($id, $data->login, $data->name);
			$changes = ['login' => $data->login];
			if ($data->password !== '') {
				$this->users->changePassword($id, $data->password); // ends the account's other sessions
				$changes['password'] = 'changed';
			}
			if (!empty($data->unlock)) {
				$this->users->unlock($id);
				$changes['unlocked'] = true;
			}
		} catch (AdminError $e) {
			$form->addError($e->getMessage());
			return;
		}

		$this->auditLog->record($this->adminId(), 'admin.updated', null, $changes);
		if ($id === $this->adminId()) {
			$this->refreshOwnIdentity();
		}
		$this->flashMessage('Změny jsou uložené.' . (isset($changes['password']) && $id !== $this->adminId()
			? ' Uživatel byl odhlášen na všech zařízeních.' : ''), 'success');
		$this->redirect('this');
	}


	/** After changing one's own account, keep this session logged in with the new data. */
	private function refreshOwnIdentity(): void
	{
		$account = $this->users->findById($this->adminId());
		if ($account !== null) {
			$this->getUser()->login($this->authenticator->identity($account));
		}
	}
}
