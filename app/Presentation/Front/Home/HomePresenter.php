<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Model\Reservation\ReservationService;
use App\Model\Reservation\ReservationSession;
use App\Model\Reservation\SiteMode;
use App\Presentation\Front\BasePresenter;
use App\Presentation\Front\VisitorGate;
use Nette\Utils\Json;


/**
 * Reservation page; the interactive seat picker is a Preact island (assets/ts/front.tsx).
 * Visitors who cannot buy in the current stage see the page (HTML) edited in the administration.
 * @property-read HomeTemplate $template
 */
final class HomePresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationSession $reservationSession,
		private readonly VisitorGate $gate,
	) {
		parent::__construct();
	}


	/** @param string $preview stage whose page a logged-in organizer wants to see */
	public function renderDefault(string $preview = ''): void
	{
		$mode = $this->gate->mode();
		$previewMode = $this->getUser()->isLoggedIn() ? SiteMode::tryFrom($preview) : null;
		$t = $this->template;
		if ($mode === SiteMode::Testing || $previewMode !== null) {
			$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
		}

		$pageKey = $previewMode !== null ? $previewMode->pageKey() : ($this->gate->canBuy() ? null : $mode->pageKey());
		$t->canBuy = $pageKey === null;
		$t->pageHtml = $pageKey !== null ? $this->settings->get($pageKey) : '';
		$t->preview = $previewMode;
		if (!$t->canBuy) {
			return;
		}

		$this->reservations->releaseExpiredHolds();
		$layout = $this->reservations->hallLayout();
		$t->layout = $layout;
		$t->pickerData = Json::encode([
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
