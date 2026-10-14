<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Log\EventLog;
use App\Model\Payment\PaymentChange;
use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
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


	/** "Payment received" or "partial payment, please pay the rest". */
	public function sendPaymentUpdate(PaymentChange $change): bool
	{
		try {
			$reservation = $this->load($change->reservationId);
			$params = $this->params($reservation) + [
				'paid' => intdiv($change->paidHalers, 100),
				'remaining' => intdiv(max(0, $change->totalHalers - $change->paidHalers), 100),
				'complete' => $change->newStatus === 'paid',
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
			));
			$this->eventLog->record('email.payment_sent', $change->reservationId, ['to' => $reservation['email']], automatic: true);
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
