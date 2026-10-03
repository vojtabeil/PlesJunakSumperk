<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Site;

use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\Settings;
use App\Model\Reservation\SiteAccess;
use App\Model\Reservation\SiteMode;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;


/**
 * Stage of the site (testing, VIP, public, closed, after the ball), the secret links,
 * test reservations and the pages shown to visitors who cannot buy.
 * @property-read SiteTemplate $template
 */
final class SitePresenter extends BasePresenter
{
	/** Settings key => label of the page editor. */
	private const Pages = [
		'page_testing' => 'Stránka ve stavu Testování (pro lidi bez testerského odkazu)',
		'page_vip' => 'Stránka ve stavu VIP prodej (pro lidi bez VIP odkazu)',
		'page_closed' => 'Stránka ve stavu Prodej ukončen',
		'page_after' => 'Stránka ve stavu Po plese',
	];


	public function __construct(
		private readonly SiteAccess $access,
		private readonly Settings $settings,
		private readonly ReservationAdmin $reservationAdmin,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$t = $this->template;
		$t->mode = $this->access->mode();
		$t->modes = SiteMode::cases();
		$t->testerLink = $this->link('//:Front:Access:tester', ['token' => $this->access->token(SiteAccess::Tester)]);
		$t->vipLink = $this->link('//:Front:Access:vip', ['token' => $this->access->token(SiteAccess::Vip)]);
		$t->testCount = $this->reservationAdmin->testCount();
		$t->tickets = $this->reservationAdmin->ticketsByChannel();
	}


	/**
	 * One button per stage.
	 * @return Multiplier<Form>
	 */
	protected function createComponentModeForm(): Multiplier
	{
		return new Multiplier(function (string $value): Form {
			$mode = SiteMode::from($value);
			$form = $this->formFactory->create();
			$form->addSubmit('switch', 'Přepnout na „' . $mode->label() . '“');
			$form->onSuccess[] = function () use ($mode): void {
				$this->access->setMode($mode);
				$this->flashMessage('Web je ve stavu „' . $mode->label() . '“.', 'success');
				$this->redirect('this');
			};
			return $form;
		});
	}


	/**
	 * "New link" button of the tester and the VIP link.
	 * @return Multiplier<Form>
	 */
	protected function createComponentRegenerateLinkForm(): Multiplier
	{
		return new Multiplier(function (string $link): Form {
			if ($link !== SiteAccess::Tester && $link !== SiteAccess::Vip) {
				$this->error();
			}
			$name =$link === SiteAccess::Vip ? 'VIP' : 'testerský';
			$form = $this->formFactory->create();
			$form->addCheckbox('confirm', 'Opravdu – starý odkaz přestane fungovat i těm, kdo ho už otevřeli')
				->setRequired('Akci potvrďte zaškrtnutím.');
			$form->addSubmit('regenerate', "Vytvořit nový $name odkaz");
			$form->onSuccess[] = function () use ($link, $name): void {
				$this->access->regenerate($link);
				$this->flashMessage("Nový $name odkaz je vytvořený; rozešlete ho znovu.", 'success');
				$this->redirect('this');
			};
			return $form;
		});
	}


	protected function createComponentDeleteTestsForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('confirm', 'Opravdu smazat všechny testovací rezervace (místa se uvolní)')
			->setRequired('Akci potvrďte zaškrtnutím.');
		$form->addSubmit('delete', 'Smazat testovací rezervace');
		$form->onSuccess[] = function (): void {
			$count = $this->reservationAdmin->deleteTestReservations();
			$this->flashMessage("Smazáno testovacích rezervací: $count.", 'success');
			$this->redirect('this');
		};
		return $form;
	}


	/** Pages are HTML written by the organizers (trusted, shown as is). */
	protected function createComponentPagesForm(): Form
	{
		$form = $this->formFactory->create();
		foreach (self::Pages as $key => $label) {
			$form->addTextArea($key, $label)
				->setMaxLength(20000)
				->setHtmlAttribute('rows', 8)
				->setHtmlAttribute('class', 'code-input')
				->setDefaultValue($this->settings->get($key));
		}
		$form->addSubmit('save', 'Uložit stránky');
		$form->onSuccess[] = function (Form $form, array $data): void {
			$changed = $this->settings->save(array_map(static fn($v): string => trim((string) $v), $data));
			$this->flashMessage($changed ? 'Stránky jsou uložené.' : 'Nic se nezměnilo.', $changed ? 'success' : 'info');
			$this->redirect('this');
		};
		return $form;
	}
}
