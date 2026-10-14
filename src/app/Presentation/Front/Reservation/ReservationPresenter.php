<?php

declare(strict_types=1);

namespace App\Presentation\Front\Reservation;

use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationService;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use App\Presentation\Front\BasePresenter;


/**
 * "Moje rezervace" (/rezervace/<id>/<token>): status, details and payment of one reservation.
 * The secret link comes in every e-mail, so it works on any device and in every stage of the site.
 * A wrong token is a plain 404 (nothing about other reservations is revealed).
 * @property-read ReservationTemplate $template
 */
final class ReservationPresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly QrPayment $qrPayment,
		private readonly Tickets $tickets,
		private readonly TicketLinks $ticketLinks,
	) {
		parent::__construct();
	}


	public function renderDefault(int $id, string $token, bool $new = false): void
	{
		$reservation = $this->reservations->findByToken($id, $token) ?? $this->error();
		$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');

		$t = $this->template;
		$t->reservation = $reservation;
		$t->justCreated = $new;
		$t->account = $this->qrPayment->account();
		$t->iban = $this->qrPayment->iban();
		$open = in_array($reservation['status'], ['confirmed', 'partially_paid'], true) && $reservation['remaining'] > 0;
		$t->qrCode = $open
			? $this->qrPayment->pngDataUri($this->qrPayment->forReservation($reservation, $this->settings->get('event_name', 'Skautský ples')))
			: null;
		$t->overdue = $open && $reservation['due_on'] !== null && $reservation['due_on'] < new \DateTimeImmutable('today');
		// Tickets are given out once the reservation is paid.
		$t->tickets = $reservation['status'] === 'paid'
			? array_map(fn(array $ticket): array => [
				'code' => (string) $ticket['code'],
				'seat' => $ticket['seat_label'] !== null ? (string) $ticket['seat_label'] : null,
				'link' => $this->ticketLinks->printUrl((string) $ticket['code'], (string) $ticket['print_token']),
				'used' => $ticket['used_at'] !== null,
			], $this->tickets->forReservation($id))
			: [];
	}
}
