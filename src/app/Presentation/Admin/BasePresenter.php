<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\Admin\Authenticator;
use App\Model\Log\Actor;
use App\Model\Log\EventLogRepository;
use App\Presentation\Accessory\FormFactory;
use App\Presentation\Admin\Accessory\AdminApps;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;


/**
 * Every admin page requires a logged-in organizer (except Sign).
 * @property-read BaseTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	protected FormFactory $formFactory;
	protected EventLogRepository $events;
	protected Actor $actor;
	private AdminApps $apps;


	public function injectBase(FormFactory $formFactory, EventLogRepository $events, Actor $actor, AdminApps $apps): void
	{
		$this->apps = $apps;
		$this->formFactory = $formFactory;
		$this->events = $events;
		$this->actor = $actor;
	}


	protected function startup(): void
	{
		parent::startup();
		$user = $this->getUser();
		if (!$this->isPublic() && !($user->isLoggedIn() && $user->isInRole(Authenticator::Role))) {
			if ($user->getLogoutReason() === $user::LogoutInactivity) {
				$this->flashMessage('Byli jste odhlášeni kvůli nečinnosti. Přihlaste se prosím znovu.', 'info');
			}
			$this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
		}
		if ($user->isLoggedIn()) {
			// Everything the model logs in this request is attributed to this administrator.
			$this->actor->asAdmin($this->adminId());
		}
	}


	/** Presenters that do not require login override this. */
	protected function isPublic(): bool
	{
		return false;
	}


	protected function adminId(): int
	{
		return (int) $this->getUser()->getId();
	}


	protected function beforeRender(): void
	{
		$this->template->adminName = (string) ($this->getUser()->getIdentity()?->getData()['name'] ?? '');
		$this->template->apps = $this->getUser()->isLoggedIn() ? $this->apps->links() : [];
	}


	/** "Odhlásit" in the menus: a POST form (a GET link could log the organizer out from another site). */
	protected function createComponentSignOutForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('send', 'Odhlásit');
		$form->onSuccess[] = function (): void {
			$this->getUser()->logout(clearIdentity: true);
			$this->flashMessage('Byli jste odhlášeni.', 'info');
			$this->redirect('Sign:in');
		};
		return $form;
	}
}