<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use RuntimeException;
use Throwable;
use Tracy\ILogger;


/** E-mails about reservations; templates are in ./templates (Czech texts). */
final class ReservationMailer
{
	public function __construct(
		private readonly ReservationService $reservations,
		private readonly Settings $settings,
		private readonly MailSender $sender,
		private readonly LatteFactory $latteFactory,
		private readonly ILogger $logger,
	) {
	}


	/**
	 * Sends the confirmation and records the outcome. Never throws: a failed e-mail
	 * must not undo a reservation that is already stored.
	 */
	public function sendConfirmation(int $reservationId): bool
	{
		$error = null;
		try {
			$reservation = $this->reservations->findFinished($reservationId)
				?? throw new RuntimeException("Reservation $reservationId not found.");
			$params = ['reservation' => $reservation, 'settings' => $this->settings->all()];
			$this->sender->send(new MailMessage(
				to: $reservation['email'],
				subject: sprintf('Potvrzení rezervace č. %d – %s', $reservationId, $this->settings->get('event_name', 'Skautský ples')),
				html: $this->render('reservationConfirmed.html.latte', $params),
				text: $this->render('reservationConfirmed.txt.latte', $params),
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


	/** @param array<string, mixed> $params */
	private function render(string $template, array $params): string
	{
		return $this->latteFactory->create()->renderToString(__DIR__ . '/templates/' . $template, $params);
	}
}
