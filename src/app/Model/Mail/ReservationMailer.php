<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Log\EventLog;
use App\Model\Payment\PaymentChange;
use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use Nette\Application\LinkGenerator;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use RuntimeException;
use Throwable;
use Tracy\ILogger;


/**
 * E-mails about reservations; templates are in ./templates (Czech texts). Every e-mail links
 * to the page "Moje rezervace" (status, payment details, QR code).
 * Sending never throws: a failed e-mail must not undo a stored reservation or payment.
 */
final class ReservationMailer
{
	private const QrCid = 'qr-platba';


	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationAdmin $reservationAdmin,
		private readonly Settings $settings,
		private readonly MailSender $sender,
		private readonly LatteFactory $latteFactory,
		private readonly QrPayment $qrPayment,
		private readonly LinkGenerator $linkGenerator,
		private readonly ILogger $logger,
		private readonly EventLog $eventLog,
		private readonly Tickets $tickets,
		private readonly TicketLinks $ticketLinks,
	) {
	}


	/** Confirmation with payment instructions and a QR code; the outcome is stored with the reservation. */
	public function sendConfirmation(int $reservationId): bool
	{
		$error = null;
		$to = null;
		try {
			$reservation = $this->load($reservationId);
			$to = (string) $reservation['email'];
			$params = $this->params($reservation) + ['qrCid' => self::QrCid];
			$this->sender->send(new MailMessage(
				to: $to,
				subject: sprintf('Potvrzení rezervace č. %d – %s', $reservationId, $this->eventName()),
				html: $this->render('reservationConfirmed.html.latte', $params),
				text: $this->render('reservationConfirmed.txt.latte', $params),
				inlineImages: [self::QrCid => $this->qrPayment->png($this->spayd($reservation))],
			));
		} catch (Throwable $e) {
			$error = $e->getMessage();
			$this->logger->log("Confirmation e-mail for reservation $reservationId failed: $error", ILogger::WARNING);
		}

		try {
			$this->reservations->recordEmailResult($reservationId, $error);
			$this->eventLog->record(
				$error === null ? 'email.confirmation_sent' : 'email.confirmation_failed',
				$reservationId,
				array_filter(['to' => $to, 'error' => $error]),
				automatic: true,
			);
		} catch (Throwable $e) {
			$this->logger->log($e, ILogger::EXCEPTION);
		}
		return $error === null;
	}


	/** "Payment received" with the tickets, or "partial payment, please pay the rest". */
	public function sendPaymentUpdate(PaymentChange $change): bool
	{
		try {
			$reservation = $this->load($change->reservationId);
			$complete = $change->newStatus === 'paid';
			[$tickets, $images] = $complete ? $this->ticketViews($change->reservationId) : [[], []];
			$params = $this->params($reservation) + [
				'paid' => intdiv($change->paidHalers, 100),
				'remaining' => intdiv(max(0, $change->totalHalers - $change->paidHalers), 100),
				'complete' => $complete,
				'tickets' => $tickets,
			];
			$this->sender->send(new MailMessage(
				to: $reservation['email'],
				subject: sprintf(
					'%s – rezervace č. %d – %s',
					$params['complete'] ? 'Platba přijata' : 'Přijata částečná platba',
					$change->reservationId,
					$this->eventName(),
				),
				html: $this->render('paymentUpdate.html.latte', $params),
				text: $this->render('paymentUpdate.txt.latte', $params),
				inlineImages: $images,
			));
			$this->eventLog->record('email.payment_sent', $change->reservationId, ['to' => $reservation['email']], automatic: true);
			if ($tickets) {
				$this->eventLog->record('email.tickets_sent', $change->reservationId, ['to' => $reservation['email']], automatic: true);
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->log("Payment e-mail for reservation $change->reservationId failed: {$e->getMessage()}", ILogger::WARNING);
			$this->logFailure('email.payment_failed', $change->reservationId, $e);
			return false;
		}
	}


	/** Reminder of an unpaid reservation after its due date (sent by hand from the administration). */
	public function sendReminder(int $reservationId): bool
	{
		try {
			$reservation = $this->load($reservationId);
			if ($reservation['remaining'] <= 0) {
				return false;
			}
			$params = $this->params($reservation) + ['qrCid' => self::QrCid];
			$this->sender->send(new MailMessage(
				to: $reservation['email'],
				subject: sprintf('Připomínka platby – rezervace č. %d – %s', $reservationId, $this->eventName()),
				html: $this->render('paymentReminder.html.latte', $params),
				text: $this->render('paymentReminder.txt.latte', $params),
				inlineImages: [self::QrCid => $this->qrPayment->png($this->spayd($reservation))],
			));
			$this->reservationAdmin->markReminded($reservationId);
			$this->eventLog->record('email.reminder_sent', $reservationId, [
				'to' => $reservation['email'],
				'amount' => $reservation['remaining'],
			], automatic: true);
			return true;
		} catch (Throwable $e) {
			$this->logger->log("Reminder for reservation $reservationId failed: {$e->getMessage()}", ILogger::WARNING);
			$this->logFailure('email.reminder_failed', $reservationId, $e);
			return false;
		}
	}


	/**
	 * The tickets of a paid reservation (QR codes and print links). Sent by hand from the administration:
	 * after marking a reservation paid in cash, or again on request.
	 */
	public function sendTickets(int $reservationId): bool
	{
		try {
			$reservation = $this->load($reservationId);
			if ($reservation['status'] !== 'paid') {
				throw new RuntimeException('Vstupenky se posílají až po zaplacení rezervace.');
			}
			[$tickets, $images] = $this->ticketViews($reservationId);
			$params = $this->params($reservation) + ['tickets' => $tickets];
			$this->sender->send(new MailMessage(
				to: $reservation['email'],
				subject: sprintf('Vstupenky – rezervace č. %d – %s', $reservationId, $this->eventName()),
				html: $this->render('tickets.html.latte', $params),
				text: $this->render('tickets.txt.latte', $params),
				inlineImages: $images,
			));
			$this->eventLog->record('email.tickets_sent', $reservationId, ['to' => $reservation['email']], automatic: true);
			return true;
		} catch (Throwable $e) {
			$this->logger->log("Tickets e-mail for reservation $reservationId failed: {$e->getMessage()}", ILogger::WARNING);
			$this->logFailure('email.tickets_failed', $reservationId, $e);
			return false;
		}
	}


	/** URL of the page "Moje rezervace". */
	public function reservationLink(int $id, string $token): string
	{
		return $this->linkGenerator->link('Front:Reservation:default', ['id' => $id, 'token' => $token]);
	}


	private function logFailure(string $action, int $reservationId, Throwable $error): void
	{
		try {
			$this->eventLog->record($action, $reservationId, ['error' => $error->getMessage()], automatic: true);
		} catch (Throwable $logError) {
			$this->logger->log($logError, ILogger::EXCEPTION);
		}
	}


	/** @return array<string, mixed> */
	private function load(int $reservationId): array
	{
		return $this->reservations->findFinished($reservationId)
			?? throw new RuntimeException("Reservation $reservationId not found.");
	}


	/**
	 * Tickets for the e-mail templates and their QR codes as inline images (content id => PNG).
	 * @return array{list<array{code: string, seat: ?string, link: string, cid: string}>, array<string, string>}
	 */
	private function ticketViews(int $reservationId): array
	{
		$views = $images = [];
		foreach ($this->tickets->forReservation($reservationId) as $ticket) {
			$code = (string) $ticket['code'];
			$cid = "ticket-$code";
			$views[] = [
				'code' => $code,
				'seat' => $ticket['seat_label'] !== null ? (string) $ticket['seat_label'] : null,
				'link' => $this->ticketLinks->printUrl($code, (string) $ticket['print_token']),
				'cid' => $cid,
			];
			$images[$cid] = $this->ticketLinks->png($code);
		}
		return [$views, $images];
	}


	/** @param array<string, mixed> $reservation */
	private function spayd(array $reservation): string
	{
		return $this->qrPayment->forReservation($reservation, $this->eventName());
	}


	/**
	 * @param array<string, mixed> $reservation
	 * @return array<string, mixed>
	 */
	private function params(array $reservation): array
	{
		return [
			'reservation' => $reservation,
			'settings' => $this->settings->all(),
			'account' => $this->qrPayment->account(),
			'iban' => $this->qrPayment->iban(),
			'link' => $this->reservationLink((int) $reservation['id'], (string) $reservation['access_token']),
		];
	}


	private function eventName(): string
	{
		return $this->settings->get('event_name', 'Skautský ples');
	}


	/** @param array<string, mixed> $params */
	private function render(string $template, array $params): string
	{
		return $this->latteFactory->create()->renderToString(__DIR__ . '/templates/' . $template, $params);
	}
}
