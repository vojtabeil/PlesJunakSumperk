<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Model\Reservation\ReservationService;
use App\Model\Reservation\ReservationSession;
use App\Presentation\Front\BasePresenter;
use Nette\Utils\Json;


/**
 * Reservation page; the interactive seat picker is a Preact island (assets/ts/front.tsx).
 * @property-read HomeTemplate $template
 */
final class HomePresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationSession $reservationSession,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->reservations->releaseExpiredHolds();
		$layout = $this->reservations->hallLayout();

		$this->template->saleOpen = $this->settings->isSaleOpen();
		$this->template->layout = $layout;
		$this->template->pickerData = Json::encode([
			'api' => $this->link('Api:default', ['op' => '__op__']),
			'csrf' => $this->reservationSession->csrfToken(),
			'layout' => $layout,
			'prices' => [
				'seat' => $this->settings->int('price_seat'),
				'standing' => $this->settings->int('price_standing'),
			],
			'maxTickets' => $this->settings->int('max_ticket', 10),
		], htmlSafe: true); // printed raw into <script type="application/json">, so "<" etc. must be escaped
	}
}
