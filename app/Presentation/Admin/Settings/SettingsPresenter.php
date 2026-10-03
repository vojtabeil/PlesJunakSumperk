<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Settings;

use App\Model\Reservation\Settings;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * Event info, prices, limits and the sale switch (table settings).
 * @property-read SettingsTemplate $template
 */
final class SettingsPresenter extends BasePresenter
{
	public function __construct(
		private readonly Settings $settings,
	) {
		parent::__construct();
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
				$this->auditLog->record($this->adminId(), 'settings.changed', null, $changed);
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
