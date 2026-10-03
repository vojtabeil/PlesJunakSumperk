<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Settings;

use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\Settings;
use App\Model\Reservation\TesterAccess;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * Who sees the site (tester mode), event info, prices, limits and the sale switch (table settings).
 * @property-read SettingsTemplate $template
 */
final class SettingsPresenter extends BasePresenter
{
	public function __construct(
		private readonly Settings $settings,
		private readonly TesterAccess $testerAccess,
		private readonly ReservationAdmin $reservationAdmin,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->isPublic = $this->testerAccess->isPublic();
		$this->template->testerLink = $this->link('//:Front:Tester:default', ['token' => $this->testerAccess->token()]);
		$this->template->testCount = $this->reservationAdmin->testCount();
	}


	protected function createComponentAccessForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addRadioList('public_access', 'Kdo vidí web s rezervacemi', [
			TesterAccess::Testers => 'Jen testeři (s odkazem níže) a přihlášení organizátoři – ostatní vidí „Připravujeme“',
			TesterAccess::Public => 'Všichni – web je spuštěný',
		])->setDefaultValue($this->testerAccess->isPublic() ? TesterAccess::Public : TesterAccess::Testers);
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			if ($this->settings->save(['public_access' => (string) $data->public_access])) {
				$this->flashMessage($data->public_access === TesterAccess::Public ? 'Web je spuštěný pro všechny.' : 'Web teď vidí jen testeři.', 'success');
			}
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentRegenerateLinkForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('regenerate', 'Vytvořit nový odkaz (starý přestane fungovat)')
			->setHtmlAttribute('class', 'link-button');
		$form->onSuccess[] = function (): void {
			$this->testerAccess->regenerate();
			$this->flashMessage('Odkaz pro testery je nový; testeři ho musí otevřít znovu.', 'success');
			$this->redirect('this');
		};
		return $form;
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


	protected function createComponentSettingsForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addGroup('Prodej');
		$form->addCheckbox('sale_open', 'Prodej lístků je otevřený');
		$form->addTextArea('closed_message', 'Text při uzavřeném prodeji')->setMaxLength(1000);
		$this->addNumber($form, 'max_ticket', 'Nejvýše lístků na rezervaci', 1, 100);
		$this->addNumber($form, 'hold_seconds', 'Jak dlouho držet vybraná místa (sekundy)', 30, 3600);
		$this->addNumber($form, 'price_seat', 'Cena lístku s místenkou (Kč)', 0, 100000);
		$this->addNumber($form, 'price_standing', 'Cena lístku bez místenky (Kč)', 0, 100000);
		$this->addNumber($form, 'standing_capacity', 'Počet lístků bez místenky', 0, 10000);

		$form->addGroup('Platby');
		$form->addText('bank_account', 'Číslo účtu pro platby')
			->setRequired('Zadejte číslo účtu.')
			->addRule($form::Pattern, 'Zadejte číslo účtu ve tvaru 123456789/2010 (případně s předčíslím 19-123456789/0800).', '(\d{1,6}-)?\d{2,10}/\d{4}');
		$form->addText('payment_vs_prefix', 'Předčíslí variabilního symbolu')
			->setOption('description', 'VS = předčíslí + číslo rezervace na 4 místa (např. 2026 → 20260003). Platby s jiným VS se považují za nesouvisející s plesem. Neměňte během prodeje.')
			->setRequired('Zadejte předčíslí.')
			->addRule($form::Pattern, 'Předčíslí: 1–6 číslic, nesmí začínat nulou.', '[1-9]\d{0,5}');

		$form->addGroup('Akce');
		$form->addText('event_name', 'Název akce')->setRequired('Vyplňte název akce.')->setMaxLength(255);
		$form->addTextArea('event_intro', 'Úvodní text')->setMaxLength(1000);
		$form->addText('event_date', 'Kdy (např. „7. 2. 2026 od 19:00“)')->setMaxLength(255);
		$form->addText('venue', 'Kde')->setMaxLength(255);
		$this->addUrl($form, 'venue_url', 'Odkaz na mapu');
		$form->addText('band', 'Kapela')->setMaxLength(255);
		$this->addUrl($form, 'band_url', 'Odkaz na kapelu');
		$form->addText('organizer', 'Organizátor')->setMaxLength(255);

		$form->setCurrentGroup(null);
		$form->addSubmit('save', 'Uložit nastavení');

		$defaults = $this->settings->all();
		$defaults['sale_open'] = ($defaults['sale_open'] ?? '') === '1';
		$form->setDefaults($defaults);

		$form->onSuccess[] = function (Form $form, array $data): void {
			$values = array_map(
				static fn($value): string => is_bool($value) ? ($value ? '1' : '0') : trim((string) $value),
				$data,
			);
			$changed = $this->settings->save($values);
			if ($changed) {
				$this->flashMessage('Nastavení je uložené.', 'success');
			} else {
				$this->flashMessage('Nic se nezměnilo.', 'info');
			}
			$this->redirect('this');
		};
		return $form;
	}


	private function addNumber(Form $form, string $name, string $label, int $min, int $max): void
	{
		$form->addInteger($name, $label)
			->setRequired()
			->addRule($form::Range, 'Zadejte číslo od %d do %d.', [$min, $max]);
	}


	private function addUrl(Form $form, string $name, string $label): void
	{
		$form->addText($name, $label)
			->setMaxLength(1000)
			->addCondition($form::Filled)
			// Form::URL alone accepts "foo" (it prepends http://), so require an explicit scheme too.
			->addRule($form::Pattern, 'Zadejte celou adresu začínající https://', 'https?://\S+')
			->addRule($form::URL, 'Zadejte platnou adresu (https://…).');
	}
}
