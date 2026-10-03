<?php

declare(strict_types=1);

namespace App\Presentation\Front\Done;

use App\Model\Reservation\ReservationService;
use App\Model\Reservation\ReservationSession;
use App\Presentation\Front\BasePresenter;


/**
 * Confirmation after a successful reservation, shown only to the browser that made it.
 * @property-read DoneTemplate $template
 */
final class DonePresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationSession $reservationSession,
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
	}
}
