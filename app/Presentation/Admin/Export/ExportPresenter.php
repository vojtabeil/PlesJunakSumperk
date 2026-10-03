<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Export;

use App\Model\Reservation\GuestListCsv;
use App\Model\Reservation\ReservationAdmin;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Responses\TextResponse;


/** Downloads for organizers. */
final class ExportPresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
	) {
		parent::__construct();
	}


	/** Guest list for the entrance (confirmed and paid reservations). */
	public function actionGuests(): void
	{
		$response = $this->getHttpResponse();
		$response->setContentType('text/csv', 'UTF-8');
		$response->setHeader('Content-Disposition', 'attachment; filename="hoste-' . date('Y-m-d') . '.csv"');
		$response->setHeader('Cache-Control', 'no-store');
		$this->sendResponse(new TextResponse(GuestListCsv::build($this->reservationAdmin->guestList())));
	}
}
