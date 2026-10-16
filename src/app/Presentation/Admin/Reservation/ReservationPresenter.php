<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Reservation;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\PaymentRepository;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationError;
use App\Model\Reservation\ReservationService;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use App\Presentation\Accessory\TemplateExtension;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;


/** @property-read ReservationTemplate $template */
final class ReservationPresenter extends BasePresenter
{

	#[Persistent]
	public string $status = '';

	#[Persistent]
	public string $q = '';

	/** One of ReservationAdmin::Problems ('' = none), used by the dashboard links. */
	#[Persistent]
	public string $problem = '';

	/** One of ReservationAdmin::Channels ('' = all). */
	#[Persistent]
	public string $channel = '';

	/** Key of ReservationAdmin::Sorts, "-key" = descending. */
	#[Persistent]
	public string $sort = ReservationAdmin::DefaultSort;

	/** @var array<string, mixed> */
	private array $reservation;


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationMailer $mailer,
		private readonly PaymentRepository $payments,
		private readonly ReservationService $reservations,
		private readonly Tickets $tickets,
		private readonly TicketLinks $ticketLinks,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->sort = ReservationAdmin::normalizeSort($this->sort);
		$this->template->reservations = $this->reservationAdmin->search(
			$this->status ?: null,
			$this->q,
			$this->problem ?: null,
			$this->sort,
			$this->channel ?: null,
		);
		$this->template->problemLabel = ReservationAdmin::Problems[$this->problem] ?? null;
		$this->template->sort = $this->sort;
	}


	public function actionDetail(int $id): void
	{
		$this->reservation = $this->reservationAdmin->get($id) ?? $this->error('Rezervace neexistuje.');
	}


	public function renderDetail(): void
	{
		$this->template->reservation = $this->reservation;
		$this->template->activity = $this->events->recent(100, (int) $this->reservation['id']);
		$this->template->payments = $this->payments->forReservation((int) $this->reservation['id']);
		$token = $this->reservation['access_token'] ?? null;
		$this->template->customerLink = $token !== null ? $this->mailer->reservationLink((int) $this->reservation['id'], (string) $token) : null;
		$this->template->dueOn = $this->reservation['confirmed_at'] !== null
			? $this->reservations->dueDate(new \DateTimeImmutable((string) $this->reservation['confirmed_at']))
			: null;
		$this->template->tickets = array_map(fn(array $ticket): array => $ticket + [
			'print_link' => $this->ticketLinks->printUrl((string) $ticket['code'], (string) $ticket['print_token']),
		], $this->tickets->forReservation((int) $this->reservation['id']));
	}


	protected function createComponentFilterForm(): Form
	{
		$form = new Form;
		$form->setMethod('GET');
		$statuses = ['' => 'vše kromě rozpracovaných'];
		foreach (ReservationAdmin::Statuses as $status) {
			$statuses[$status] = TemplateExtension::formatStatus($status);
		}
		$form->addSelect('status', 'Stav', $statuses)->setDefaultValue(isset($statuses[$this->status]) ? $this->status : '');
		$channels = ['' => 'všechny', 'public' => 'veřejný prodej', 'vip' => 'VIP', 'test' => 'testovací'];
		$form->addSelect('channel', 'Prodej', $channels)->setDefaultValue(isset($channels[$this->channel]) ? $this->channel : '');
		$sorts = [];
		foreach (ReservationAdmin::Sorts as $key => [$label]) {
			$sorts["-$key"] = "$label – sestupně";
			$sorts[$key] = "$label – vzestupně";
		}
		$form->addSelect('sort', 'Řadit', $sorts)->setDefaultValue(ReservationAdmin::normalizeSort($this->sort));
		$form->addText('q', 'Hledat')
			->setHtmlAttribute('placeholder', 'e-mail, jméno nebo číslo')
			->setDefaultValue($this->q);
		$form->addSubmit('filter', 'Filtrovat');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$this->redirect('default', [
				'status' => (string) $data->status,
				'channel' => (string) $data->channel,
				'sort' => (string) $data->sort,
				'q' => trim((string) $data->q),
				'problem' => '',
			]);
		};
		return $form;
	}


	/** One POST form per action, so every change is CSRF-protected. */
	protected function createComponentMarkPaidForm(): Form
	{
		return $this->createSimpleForm('Označit jako zaplacené (hotově)', function (int $id): string {
			$this->reservationAdmin->markPaid($id);
			return $this->mailer->sendTickets($id)
				? 'Rezervace je označená jako zaplacená a hostovi odešly vstupenky.'
				: 'Rezervace je označená jako zaplacená, ale vstupenky se nepodařilo odeslat (viz Historie).';
		});
	}


	protected function createComponentCancelForm(): Form
	{
		$r = $this->reservation;
		$seats = count($r['seats'] ?? []);
		$tickets = $seats + (int) $r['standing_tickets'];
		return $this->createSimpleForm('Zrušit rezervaci', function (int $id): string {
			$this->reservationAdmin->cancel($id);
			return 'Rezervace je zrušená a místa jsou opět volná.';
		}, confirmLabel: sprintf(
			'Opravdu zrušit rezervaci č. %d – %s, lístků: %d%s. Zrušení nejde vrátit, zákazníkovi nepřijde e-mail.',
			$r['id'],
			$r['name'] ?? $r['email'] ?? '',
			$tickets,
			$seats ? " (uvolní se $seats " . ($seats === 1 ? 'místo' : ($seats < 5 ? 'místa' : 'míst')) . ')' : '',
		));
	}


	protected function createComponentResendForm(): Form
	{
		return $this->createSimpleForm('Znovu poslat potvrzovací e-mail', function (int $id): string {
			$sent = $this->mailer->sendConfirmation($id);
			if (!$sent) {
				throw new ReservationError('E-mail se nepodařilo odeslat, chyba je uložená u rezervace.');
			}
			return 'Potvrzovací e-mail byl odeslán.';
		});
	}


	protected function createComponentSendTicketsForm(): Form
	{
		return $this->createSimpleForm('Poslat vstupenky znovu', function (int $id): string {
			if (!$this->mailer->sendTickets($id)) {
				throw new ReservationError('Vstupenky se nepodařilo odeslat (viz Historie).');
			}
			return 'Vstupenky byly odeslány.';
		});
	}


	protected function createComponentRemindForm(): Form
	{
		return $this->createSimpleForm('Poslat připomínku platby', function (int $id): string {
			if (!$this->mailer->sendReminder($id)) {
				throw new ReservationError('Připomínku se nepodařilo odeslat (viz Log).');
			}
			return 'Připomínka platby byla odeslána.';
		});
	}


	protected function createComponentNoteForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addTextArea('note', 'Poznámka (vidí jen organizátoři)')
			->setMaxLength(1000)
			->setDefaultValue($this->reservation['note'] ?? '');
		$form->addSubmit('save', 'Uložit poznámku');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$id = (int) $this->reservation['id'];
			$this->reservationAdmin->saveNote($id, (string) $data->note);
			$this->flashMessage('Poznámka je uložená.', 'success');
			$this->redirect('this');
		};
		return $form;
	}


	/**
	 * A form with a single button (and an optional confirmation checkbox above it).
	 * @param callable(int): string $action returns the success message
	 */
	private function createSimpleForm(string $label, callable $action, ?string $confirmLabel = null): Form
	{
		$form = $this->formFactory->create();
		if ($confirmLabel !== null) {
			$form->addCheckbox('confirm', $confirmLabel)
				->setRequired('Akci potvrďte zaškrtnutím.');
		}
		$form->addSubmit('send', $label);
		$form->onSuccess[] = function () use ($action): void {
			try {
				$this->flashMessage($action((int) $this->reservation['id']), 'success');
			} catch (ReservationError $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
			$this->redirect('this');
		};
		return $form;
	}
}
