<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Core\Clock\Clock;
use App\Model\Hall\HallSync;
use App\Model\Mail\ReservationMailer;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentRepository;
use App\Model\Payment\Refunds;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\SeatOverview;
use App\Model\Settings\Settings;
use App\Model\Ticket\Tickets;
use App\Presentation\Admin\Accessory\ImportPaymentsForm;
use App\Presentation\Admin\Accessory\TicketBars;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;


/**
 * Start page of the administration: what needs attention, chart of all seats, the latest reservations,
 * table of all seats, links to the other sections.
 * @property-read DashboardTemplate $template
 */
final class DashboardPresenter extends BasePresenter
{
	use ImportPaymentsForm;

	/** Reservations shown on the dashboard, newest first; the rest is in the reservation list. */
	private const LatestReservations = 8;

	/** Seat table filter: one of SeatOverview::Statuses, '' = all. */
	#[Persistent]
	public string $seats = '';

	#[Persistent]
	public string $seatSearch = '';


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationService $reservations,
		private readonly SeatOverview $seatOverview,
		private readonly TicketBars $ticketBars,
		private readonly Settings $settings,
		private readonly PaymentRepository $payments,
		private readonly PaymentImporter $importer,
		private readonly ReservationMailer $mailer,
		private readonly HallSync $hallSync,
		private readonly Tickets $tickets,
		private readonly Clock $clock,
		private readonly Refunds $refunds,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->reservations->releaseExpiredHolds();
		$summary = $this->ticketBars->build();
		$stats = $summary['stats'];
		$filter = isset(SeatOverview::Statuses[$this->seats]) ? $this->seats : null;

		$t = $this->template;
		$t->stats = $stats;
		$t->entrance = $summary['entrance'];
		$t->bars = $summary['bars'];
		$t->latestReservations = $this->reservationAdmin->search(limit: self::LatestReservations);
		$t->hasPlan = $this->hallSync->plan() !== null;
		$t->eventCountdown = $this->settings->eventDate()?->countdown($this->clock->now());
		$t->ticketLists = [
			'seat' => $this->tickets->listing('seat'),
			'standing' => $this->tickets->listing('standing'),
			'crew' => $this->tickets->listing('crew'),
		];
		$t->seatTotal = $summary['forSale'];
		$t->seatsUnavailable = $summary['unavailable'];
		$t->seatRows = $this->seatOverview->seats($filter, $this->seatSearch);
		$t->seatFilter = $filter;
		$t->paymentProblems = $this->payments->problemCount();
		$t->reservationProblems = $this->reservationAdmin->problemCounts();
		$t->refundTotal = $this->refunds->summary()[1];
		$t->toRemind = count($this->reservationAdmin->toRemind());
		$t->lastImportAt = $this->importer->lastImportAt();
		// Without a cron job someone has to press the button; remind when payments are waiting.
		$t->importOverdue = $this->importer->isStale() && ($stats['confirmed'] + $stats['partially_paid']) > 0;
	}


	/** Reminder e-mail to everybody who has not paid after the due date (and was not reminded lately). */
	protected function createComponentRemindForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('send', 'Poslat připomínku platby');
		$form->onSuccess[] = function (): void {
			$sent = $failed = 0;
			foreach ($this->reservationAdmin->toRemind() as $id) {
				$this->mailer->sendReminder($id) ? $sent++ : $failed++;
			}
			$this->flashMessage("Připomínka odeslána: $sent" . ($failed ? ", nepodařilo se: $failed (viz Log)" : '') . '.', $failed ? 'error' : 'success');
			$this->redirect('this');
		};
		return $form;
	}


	protected function paymentImporter(): PaymentImporter
	{
		return $this->importer;
	}
}
