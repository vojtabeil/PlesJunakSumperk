<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentRepository;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
use App\Presentation\Admin\BasePresenter;
use App\Presentation\Admin\ImportPaymentsForm;


/** @property-read DashboardTemplate $template */
final class DashboardPresenter extends BasePresenter
{
	use ImportPaymentsForm;


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationService $reservations,
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
		$this->template->stats = $stats;
		$this->template->saleOpen = $this->settings->isSaleOpen();
		$this->template->recentReservations = array_slice($this->reservationAdmin->search(), 0, 8);
		$this->template->activity = $this->events->recent(10);
		$this->template->paymentProblems = $this->payments->problemCount();
		$this->template->lastImportAt = $this->importer->lastImportAt();
		// Without a cron job someone has to press the button; remind when payments are waiting.
		$this->template->importOverdue = $this->importer->isStale()
			&& ($stats['confirmed'] + $stats['partially_paid']) > 0;
	}


	protected function paymentImporter(): PaymentImporter
	{
		return $this->importer;
	}
}
