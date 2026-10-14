<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Reservation;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\PaymentRepository;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationError;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\TicketError;
use App\Model\Ticket\Tickets;
use App\Model\Ticket\UnpaidTickets;
use App\Presentation\Accessory\TemplateExtension;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;


/** @property-read ReservationTemplate $template */
final class ReservationPresenter extends BasePresenter
{
	/** How long the tickets of an unpaid reservation stay shown after the reason was given. */
	private const RevealSeconds = 30 * 60;

	#[Persistent]
	public string $status = '';

	#[Persistent]
	public string $q = '';

	/** One of ReservationAdmin::Problems ('' = none), used by the dashboard links. */
	#[Persistent]
	public string $problem = '';

	/** @var array<string, mixed> */
	private array $reservation;


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationMailer $mailer,
		private readonly PaymentRepository $payments,
		private readonly ReservationService $reservations,
		private readonly Tickets $tickets,
		private readonly TicketLinks $ticketLinks,
		private readonly UnpaidTickets $unpaidTickets,
		private readonly Settings $settings,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->reservations = $this->reservationAdmin->search($this->status ?: null, $this->q, $this->problem ?: null);
		$this->template->problemLabel = ReservationAdmin::Problems[$this->problem] ?? null;
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
		$form->addSelect('status', 'Stav', $statuses)->setDefaultValue($this->status);
		$form->addText('q', 'Hledat')
			->setHtmlAttribute('placeholder', 'e-mail, jméno nebo číslo')
			->setDefaultValue($this->q);
		$form->addSubmit('filter', 'Filtrovat');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$this->redirect('default', ['status' => (string) $data->status, 'q' => trim((string) $data->q), 'problem' => '']);
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


	public function actionTickets(int $id): void
	{
		$this->reservation = $this->reservationAdmin->get($id) ?? $this->error('Rezervace neexistuje.');
		$status = (string) $this->reservation['status'];
		$revealed = $this->revealedReason($id);
		if ($status !== 'paid' && $revealed === null) {
			$this->flashMessage(in_array($status, UnpaidTickets::UnpaidStatuses, true)
				? 'Rezervace není zaplacená: vstupenky zobrazíte jen s důvodem (formulář u vstupenek).'
				: 'Vstupenky zrušené rezervace nelze zobrazit.', 'error');
			$this->redirect('detail', $id);
		}
	}


	/** Printable tickets of a reservation (paid, or unpaid shown with a reason). */
	public function renderTickets(int $id): void
	{
		$tickets = $this->tickets->forReservation($id);
		$t = $this->template;
		$t->reservation = $this->reservation;
		$t->printTickets = $tickets;
		$t->qrCodes = array_map(fn(array $ticket): string => $this->ticketLinks->svgDataUri((string) $ticket['code']), $tickets);
		$t->revealReason = $this->reservation['status'] === 'paid' ? null : $this->revealedReason($id);
		$t->settings = $this->settings->all();
	}


	/** An administrator shows the tickets of an unpaid reservation: the reason is required and logged. */
	protected function createComponentRevealForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('reason', 'Důvod')
			->setRequired('Napište důvod, proč vstupenky zobrazujete.')
			->addRule($form::MinLength, 'Důvod napište aspoň %d znaky.', UnpaidTickets::MinReasonLength)
			->setMaxLength(UnpaidTickets::MaxReasonLength)
			->setHtmlAttribute('placeholder', 'např. zaplatí hotově u vstupu');
		$form->addSubmit('reveal', 'Zobrazit a vytisknout vstupenky');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$id = (int) $this->reservation['id'];
			try {
				$this->unpaidTickets->reveal($id, $data->reason);
			} catch (TicketError $e) {
				$form->addError($e->getMessage());
				return;
			}
			$this->getSession('unpaidTickets')->set((string) $id, ['reason' => trim($data->reason), 'until' => time() + self::RevealSeconds]);
			$this->redirect('tickets', $id);
		};
		return $form;
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


	/** The reason given for showing the unpaid tickets of a reservation, while it is still valid. */
	private function revealedReason(int $id): ?string
	{
		$grant = $this->getSession('unpaidTickets')->get((string) $id);
		return is_array($grant) && ($grant['until'] ?? 0) > time() ? (string) $grant['reason'] : null;
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
