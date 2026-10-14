<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Crew;

use App\Model\Crew\CrewError;
use App\Model\Crew\CrewMembers;
use App\Model\Crew\CrewSchedule;
use App\Model\Mail\CrewMailer;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketLinks;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\SubmitButton;


/**
 * Z-lístky: free passes of the crew (one per person, with a free-text role). List with filters,
 * adding one or many people, editing, handing over, printing and e-mailing a selection.
 * @property-read CrewTemplate $template
 */
final class CrewPresenter extends BasePresenter
{
	/** Filter by role, '' = all. */
	#[Persistent]
	public string $role = '';

	/** Part of a name or role, or a code. */
	#[Persistent]
	public string $q = '';

	/** @var list<array<string, mixed>> members shown on the list (the selection form offers their ids) */
	private array $members = [];

	/** @var array<string, mixed>|null member being edited */
	private ?array $member = null;


	public function __construct(
		private readonly CrewMembers $crew,
		private readonly CrewMailer $mailer,
		private readonly Settings $settings,
		private readonly TicketLinks $links,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->members = $this->crew->all($this->role, $this->q);
	}


	public function renderDefault(): void
	{
		$t = $this->template;
		$t->members = $this->members;
		$t->roles = $this->crew->roles();
		$t->stats = $this->crew->stats();
		$t->hasSchedule = CrewSchedule::parse($this->settings->get('crew_schedule')) !== [];
	}


	public function renderAdd(): void
	{
		$this->template->roles = $this->crew->roles();
	}


	public function actionEdit(int $id): void
	{
		$this->member = $this->crew->find($id) ?? $this->error('Člen týmu neexistuje.');
	}


	public function renderEdit(): void
	{
		$this->template->member = $this->member;
		$this->template->roles = $this->crew->roles();
		$this->template->printUrl = $this->links->printUrl((string) $this->member['code'], (string) $this->member['print_token']);
	}


	/**
	 * Printable sheet of Z-lístky (A4, several per page).
	 * @param string $ids comma-separated ids of the members
	 * @param bool $schedule print the crew timetable on every pass
	 */
	public function renderPrint(string $ids = '', bool $schedule = false): void
	{
		$members = $this->crew->byIds(array_map('intval', array_filter(explode(',', $ids), 'ctype_digit')));
		if (!$members) {
			$this->flashMessage('Vyberte aspoň jeden Z-lístek k tisku.', 'error');
			$this->redirect('default');
		}
		$t = $this->template;
		$t->members = $members;
		$t->qrCodes = array_map(fn(array $m): string => $this->links->svgDataUri((string) $m['code']), $members);
		$t->schedule = $schedule ? CrewSchedule::parse($this->settings->get('crew_schedule')) : [];
		$t->settings = $this->settings->all();
	}


	/** Checkboxes of the listed members and what to do with the selection. */
	protected function createComponentSelectionForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckboxList('ids', 'Vybrané', array_combine(
			array_map(static fn(array $m): int => (int) $m['id'], $this->members),
			array_map(static fn(array $m): string => (string) $m['name'], $this->members),
		));
		$form->addCheckbox('schedule', 'Tisknout s rozpisem služeb')
			->setDefaultValue(CrewSchedule::parse($this->settings->get('crew_schedule')) !== []);
		$form->addSubmit('print', 'Vytisknout');
		$form->addSubmit('email', 'Poslat e-mailem');
		$form->addSubmit('pickedUp', 'Označit jako vyzvednuté');
		$form->addSubmit('notPickedUp', 'Zrušit vyzvednutí');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			/** @var list<int> $ids */
			$ids = array_map('intval', $data->ids);
			if (!$ids) {
				$this->flashMessage('Nejdřív zaškrtněte Z-lístky v seznamu.', 'error');
				$this->redirect('this');
			}
			$button = $form->isSubmitted();
			$name = $button instanceof SubmitButton ? $button->getName() : '';
			match ($name) {
				'print' => $this->redirect('print', ['ids' => implode(',', $ids), 'schedule' => (bool) $data->schedule]),
				'email' => $this->emailSelection($ids),
				'pickedUp', 'notPickedUp' => $this->pickUp($ids, $name === 'pickedUp'),
				default => null,
			};
			$this->redirect('this');
		};
		return $form;
	}


	/** One member: add or edit. */
	protected function createComponentMemberForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('name', 'Jméno a příjmení')
			->setRequired('Vyplňte jméno.')
			->setMaxLength(CrewMembers::NameMaxLength);
		$form->addText('role', 'Funkce')
			->setRequired('Vyplňte funkci, např. bar nebo kuchyně.')
			->setMaxLength(CrewMembers::RoleMaxLength)
			->setHtmlAttribute('list', 'crew-roles')
			->setOption('description', 'Volný text: bar, kuchyně, šatna, vstup…');
		$form->addEmail('email', 'E-mail (nepovinný)')
			->setOption('description', 'Jen pro poslání Z-lístku e-mailem.')
			->setMaxLength(254);
		$form->addTextArea('note', 'Poznámka (nepovinná)')->setMaxLength(CrewMembers::NoteMaxLength);
		if ($this->member !== null) {
			$form->setDefaults([
				'name' => $this->member['name'],
				'role' => $this->member['role'],
				'email' => $this->member['email'] ?? '',
				'note' => $this->member['note'] ?? '',
			]);
		}
		$form->addSubmit('save', $this->member === null ? 'Přidat Z-lístek' : 'Uložit změny');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			try {
				if ($this->member === null) {
					$id = $this->crew->create($data->name, $data->role, $data->email, $data->note);
					$this->flashMessage("Z-lístek pro {$data->name} je vytvořený.", 'success');
					$this->redirect('edit', $id);
				}
				$this->crew->update((int) $this->member['id'], $data->name, $data->role, $data->email, $data->note);
			} catch (CrewError $e) {
				$form->addError($e->getMessage());
				return;
			}
			$this->flashMessage('Změny jsou uložené.', 'success');
			$this->redirect('this');
		};
		return $form;
	}


	/** Many members at once: one line "name; role" (or "name; role; e-mail"), also pasted from a spreadsheet. */
	protected function createComponentImportForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addTextArea('lines', 'Seznam lidí', rows: 10)
			->setRequired('Vložte aspoň jeden řádek.')
			->setOption('description', 'Co řádek, to člověk: „jméno; funkce“ nebo „jméno; funkce; e-mail“. Lze vložit i dva nebo tři sloupce zkopírované z Excelu.');
		$form->addSubmit('import', 'Vložit všechny');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			try {
				$count = $this->crew->import($data->lines);
			} catch (CrewError $e) {
				foreach (explode("\n", $e->getMessage()) as $message) {
					$form->addError($message);
				}
				return;
			}
			$this->flashMessage("Vytvořeno Z-lístků: $count.", 'success');
			$this->redirect('default');
		};
		return $form;
	}


	protected function createComponentPickUpForm(): Form
	{
		$pickedUp = $this->member['picked_up_at'] !== null;
		$form = $this->formFactory->create();
		$form->addSubmit('toggle', $pickedUp ? 'Zrušit vyzvednutí' : 'Označit jako vyzvednutý');
		$form->onSuccess[] = function () use ($pickedUp): void {
			$this->pickUp([(int) $this->member['id']], !$pickedUp);
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentEmailForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('send', $this->member['emailed_at'] !== null ? 'Poslat Z-lístek e-mailem znovu' : 'Poslat Z-lístek e-mailem');
		$form->onSuccess[] = function (): void {
			$this->emailSelection([(int) $this->member['id']]);
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentDeleteForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('confirm', 'Opravdu smazat tento Z-lístek')
			->setRequired('Smazání potvrďte zaškrtnutím.');
		$form->addSubmit('delete', 'Smazat Z-lístek');
		$form->onSuccess[] = function (): void {
			try {
				$this->crew->delete((int) $this->member['id']);
			} catch (CrewError $e) {
				$this->flashMessage($e->getMessage(), 'error');
				$this->redirect('this');
			}
			$this->flashMessage("Z-lístek pro {$this->member['name']} je smazaný.", 'success');
			$this->redirect('default');
		};
		return $form;
	}


	/** @param list<int> $ids */
	private function emailSelection(array $ids): void
	{
		$sent = $failed = $noEmail = 0;
		foreach ($this->crew->byIds($ids) as $member) {
			if ($member['email'] === null) {
				$noEmail++;
				continue;
			}
			$this->mailer->send((int) $member['id']) ? $sent++ : $failed++;
		}
		$parts = ["Odesláno e-mailem: $sent"];
		if ($noEmail) {
			$parts[] = "bez e-mailu: $noEmail";
		}
		if ($failed) {
			$parts[] = "nepodařilo se: $failed (viz Log)";
		}
		$this->flashMessage(implode(', ', $parts) . '.', $failed || !$sent ? 'error' : 'success');
	}


	/** @param list<int> $ids */
	private function pickUp(array $ids, bool $pickedUp): void
	{
		$changed = $this->crew->setPickedUp($ids, $pickedUp);
		$this->flashMessage(($pickedUp ? 'Označeno jako vyzvednuté' : 'Vyzvednutí zrušeno') . ": $changed.", 'success');
	}
}
