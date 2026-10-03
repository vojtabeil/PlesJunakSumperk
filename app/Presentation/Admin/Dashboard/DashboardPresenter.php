<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

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
		$t->lastImportAt = $this->importer->lastImportAt();
		// Without a cron job someone has to press the button; remind when payments are waiting.
		$t->importOverdue = $this->importer->isStale() && ($stats['confirmed'] + $stats['partially_paid']) > 0;
	}


	protected function paymentImporter(): PaymentImporter
	{
		return $this->importer;
	}
}
