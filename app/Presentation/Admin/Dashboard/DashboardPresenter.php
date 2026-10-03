<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Model\Payment\PaymentRepository;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
use App\Presentation\Admin\BasePresenter;


/** @property-read DashboardTemplate $template */
final class DashboardPresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly ReservationService $reservations,
		private readonly Settings $settings,
		private readonly PaymentRepository $payments,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->reservations->releaseExpiredHolds();
		$this->template->stats = $this->reservationAdmin->stats();
		$this->template->saleOpen = $this->settings->isSaleOpen();
		$this->template->recentReservations = array_slice($this->reservationAdmin->search(), 0, 8);
		$this->template->activity = $this->auditLog->recent(10);
		$this->template->paymentProblems = $this->payments->problemCount();
	}
}
