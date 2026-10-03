<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Payment\PaymentChange;
use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use RuntimeException;
use Throwable;
use Tracy\ILogger;


/**
 * E-mails about reservations; templates are in ./templates (Czech texts).
 * Sending never throws: a failed e-mail must not undo a stored reservation or payment.
 */
final class ReservationMailer
{
	private const QrCid = 'qr-platba';


	public function __construct(
		private readonly ReservationService $reservations,
		private readonly Settings $settings,
		private readonly MailSender $sender,
		private readonly LatteFactory $latteFactory,
		private readonly QrPayment $qrPayment,
		private readonly ILogger $logger,
	) {
	}


	/** Confirmation with payment instructions and a QR code; the outcome is stored with the reservation. */
	public function sendConfirmation(int $reservationId): bool
	{
		$error = null;
		try {
			$reservation = $this->load($reservationId);
			$spayd = $this->spayd($reservation);
			$params = $this->params($reservation) + ['qrCid' => self::QrCid];
			$this->sender->send(new MailMessage(
				to: $reservation['email'],
				subject: sprintf('Potvrzení rezervace č. %d – %s', $reservationId, $this->eventName()),
				html: $this->render('reservationConfirmed.html.latte', $params),
				text: $this->render('reservationConfirmed.txt.latte', $params),
				inlineImages: [self::QrCid => $this->qrPayment->png($spayd)],
			));
		} catch (Throwable $e) {
			$error = $e->getMessage();
			$this->logger->log("Confirmation e-mail for reservation $reservationId failed: $error", ILogger::WARNING);
		}

		try {
			$this->reservations->recordEmailResult($reservationId, $error);
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
			return true;
		} catch (Throwable $e) {
			$this->logger->log("Payment e-mail for reservation $change->reservationId failed: {$e->getMessage()}", ILogger::WARNING);
			return false;
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
		return $this->qrPayment->spayd((int) $reservation['total_price'], (string) $reservation['variable_symbol'], $this->eventName());
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
