<?php

declare(strict_types=1);

namespace App\Presentation\Front\Ticket;

use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use App\Presentation\Front\BasePresenter;


/**
 * One printable ticket (/vstupenka/<code>/<print token>), linked from the e-mail with the tickets.
 * The code alone could be enumerated, so a wrong print token is a plain 404.
 * @property-read TicketTemplate $template
 */
final class TicketPresenter extends BasePresenter
{
	public function __construct(
		private readonly Tickets $tickets,
		private readonly TicketLinks $links,
	) {
		parent::__construct();
	}


	public function renderDefault(string $code, string $token): void
	{
		$ticket = $this->tickets->findForPrint($code, $token) ?? $this->error();
		$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
		$this->template->ticket = $ticket;
		$this->template->qrCode = $this->links->svgDataUri((string) $ticket['code']);
	}
}
