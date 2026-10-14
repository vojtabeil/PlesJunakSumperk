<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentRepository;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\SeatOverview;
use App\Model\Reservation\Settings;
use App\Presentation\Accessory\PieChart;
use App\Presentation\Admin\BasePresenter;
use App\Presentation\Admin\ImportPaymentsForm;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;


/**
 * Start page of the administration: what needs attention, chart of all seats,
 * table of all seats, links to the other sections.
 * @property-read DashboardTemplate $template
 */
final class DashboardPresenter extends BasePresenter
{
	use ImportPaymentsForm;

	/** Seat table filter: one of SeatOverview::Statuses, '' = all. */
	#[Persistent]
	public string $seats = '';

	#[Persistent]
	public string $seatSearch = '';


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationService $reservations,
		private readonly SeatOverview $seatOverview,
		private readonly Settings $settings,
		private readonly PaymentRepository $payments,
		private readonly PaymentImporter $importer,
		private readonly ReservationMailer $mailer,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->reservations->releaseExpiredHolds();
		$stats = $this->reservationAdmin->stats();
		$counts = $this->seatOverview->counts();
		$filter = isset(SeatOverview::Statuses[$this->seats]) ? $this->seats : null;

		$t = $this->template;
		$t->stats = $stats;
		$t->siteMode = $this->settings->mode();
		$t->seatCounts = $counts;
		$t->slices = PieChart::slices($counts);
		$t->seatTotal = array_sum($counts);
		$t->seatRows = $this->seatOverview->seats($filter, $this->seatSearch);
		$t->seatFilter = $filter;
		$t->paymentProblems = $this->payments->problemCount();
		$t->reservationProblems = $this->reservationAdmin->problemCounts();
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
