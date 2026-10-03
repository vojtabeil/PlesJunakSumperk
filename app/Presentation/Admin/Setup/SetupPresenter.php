<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Setup;

use App\Model\Admin\AdminError;
use App\Model\Admin\AdminUsers;
use App\Model\Admin\Authenticator;
use App\Model\Admin\SetupConfig;
use App\Presentation\Admin\AccountFields;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * First-run wizard: creates the first administrator. Available only while no account exists.
 * @property-read SetupTemplate $template
 */
final class SetupPresenter extends BasePresenter
{
	public function __construct(
		private readonly AdminUsers $users,
		private readonly SetupConfig $setupConfig,
		private readonly Authenticator $authenticator,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		parent::startup();
		if ($this->users->exists()) {
			$this->error();
		}
	}


	protected function createComponentSetupForm(): Form
	{
		$form = $this->formFactory->create();
		$setupPassword = $form->addPassword('setupPassword', 'Instalační heslo')
			->setOption('description', 'Je v konfiguraci webu (config/local.neon, parametr setup.password).')
			->setRequired('Zadejte instalační heslo.');
		AccountFields::add($form, passwordRequired: true);
		$form->addSubmit('create', 'Vytvořit účet a přihlásit se');

		$form->onSuccess[] = function (Form $form, \stdClass $data) use ($setupPassword): void {
			if (!$this->setupConfig->isValidPassword($data->setupPassword)) {
				sleep(1); // slows down guessing
				$setupPassword->addError('Instalační heslo nesouhlasí.');
				return;
			}
			try {
				$id = $this->users->createFirst($data->login, $data->name, $data->password);
			} catch (AdminError $e) {
				$form->addError($e->getMessage());
				return;
			}
			$this->users->recordLogin($id);
			$account = $this->users->findById($id) ?? throw new \LogicException('Account just created is missing.');
			$this->getUser()->login($this->authenticator->identity($account));
			$this->flashMessage('Web je nastavený. Další organizátory přidáte v sekci Administrátoři.', 'success');
			$this->redirect('Dashboard:default');
		};
		return $form;
	}


	protected function isPublic(): bool
	{
		return true;
	}
}
