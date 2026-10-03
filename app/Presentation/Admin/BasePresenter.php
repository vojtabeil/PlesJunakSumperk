<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\Admin\AuditLog;
use App\Model\Admin\Authenticator;
use App\Presentation\Accessory\FormFactory;
use Nette\Application\UI\Presenter;


/**
 * Every admin page requires a logged-in organizer (except Sign).
 * @property-read BaseTemplate $template
 */
abstract class BasePresenter extends Presenter
{
	protected FormFactory $formFactory;
	protected AuditLog $auditLog;


	public function injectBase(FormFactory $formFactory, AuditLog $auditLog): void
	{
		$this->formFactory = $formFactory;
		$this->auditLog = $auditLog;
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
	}
}
