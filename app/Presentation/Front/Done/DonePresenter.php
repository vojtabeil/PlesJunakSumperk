<?php

declare(strict_types=1);

namespace App\Presentation\Front\Done;

use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\ReservationSession;
use App\Presentation\Front\BasePresenter;


/**
 * Confirmation after a successful reservation with payment instructions,
 * shown only to the browser that made it.
 * @property-read DoneTemplate $template
 */
final class DonePresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationSession $reservationSession,
		private readonly QrPayment $qrPayment,
	) {
		parent::__construct();
	}


	public function renderDefault(int $id): void
	{
		$reservation = $this->reservationSession->isFinished($id)
			? $this->reservations->findFinished($id)
			: null;
		if ($reservation === null) {
			$this->redirect('Home:default');
		}
		$this->template->reservation = $reservation;
		$this->template->account = $this->qrPayment->account();
		$this->template->remaining = max(0, (int) $reservation['total_price'] - (int) $reservation['paid_amount']);
		$this->template->qrCode = $this->template->remaining > 0
			? $this->qrPayment->pngDataUri($this->qrPayment->spayd(
				$this->template->remaining,
				(string) $reservation['variable_symbol'],
				$this->settings->get('event_name', 'Skautský ples'),
			))
			: null;
	}
}
