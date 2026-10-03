<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Settings;

use App\Model\Reservation\Settings;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * Event info, prices, limits and payment details (table settings). The stage of the site is on the Site page.
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

		$form->setDefaults($this->settings->all());

		$form->onSuccess[] = function (Form $form, array $data): void {
			$values = array_map(static fn($value): string => trim((string) $value), $data);
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
