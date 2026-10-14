<?php

declare(strict_types=1);

namespace App\Model\Mail;

use App\Model\Crew\CrewMembers;
use App\Model\Crew\CrewSchedule;
use App\Model\Log\EventLog;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketLinks;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use RuntimeException;
use Throwable;
use Tracy\ILogger;


/**
 * The Z-lístek (free pass of a crew member) by e-mail: QR code, print link and the crew timetable.
 * Sending never throws; the outcome is logged and a success is remembered (emailed_at).
 */
final class CrewMailer
{
	public function __construct(
		private readonly CrewMembers $crew,
		private readonly Settings $settings,
		private readonly MailSender $sender,
		private readonly LatteFactory $latteFactory,
		private readonly TicketLinks $ticketLinks,
		private readonly ILogger $logger,
		private readonly EventLog $eventLog,
	) {
	}


	public function send(int $crewMemberId): bool
	{
		$member = $this->crew->find($crewMemberId);
		try {
			if ($member === null) {
				throw new RuntimeException("Crew member $crewMemberId not found.");
			}
			if ($member['email'] === null) {
				throw new RuntimeException('Člen týmu nemá vyplněný e-mail.');
			}
			$code = (string) $member['code'];
			$params = [
				'member' => $member,
				'settings' => $this->settings->all(),
				'schedule' => CrewSchedule::parse($this->settings->get('crew_schedule')),
				'printLink' => $this->ticketLinks->printUrl($code, (string) $member['print_token']),
				'cid' => "ticket-$code",
				'link' => null,
			];
			$this->sender->send(new MailMessage(
				to: (string) $member['email'],
				subject: sprintf('Z-lístek – %s', $this->settings->get('event_name', 'Skautský ples')),
				html: $this->render('crewPass.html.latte', $params),
				text: $this->render('crewPass.txt.latte', $params),
				inlineImages: [$params['cid'] => $this->ticketLinks->png($code)],
			));
			$this->crew->markEmailed($crewMemberId);
			$this->eventLog->record('email.crew_sent', details: ['name' => $member['name'], 'to' => $member['email']]);
			return true;
		} catch (Throwable $e) {
			$this->logger->log("Z-lístek e-mail for crew member $crewMemberId failed: {$e->getMessage()}", ILogger::WARNING);
			try {
				$this->eventLog->record('email.crew_failed', details: [
					'name' => $member['name'] ?? null,
					'to' => $member['email'] ?? null,
					'error' => $e->getMessage(),
				]);
			} catch (Throwable $logError) {
				$this->logger->log($logError, ILogger::EXCEPTION);
			}
			return false;
		}
	}


	/** @param array<string, mixed> $params */
	private function render(string $template, array $params): string
	{
		return $this->latteFactory->create()->renderToString(__DIR__ . '/templates/' . $template, $params);
	}
}
