<?php

declare(strict_types=1);

namespace App\Presentation\Front\Ticket;

use App\Model\Admin\Authenticator;
use App\Model\Crew\CrewSchedule;
use App\Model\Log\Actor;
use App\Model\Ticket\TicketError;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use App\Model\Ticket\UnpaidTickets;
use App\Presentation\Accessory\FormFactory;
use App\Presentation\Front\BasePresenter;
use Nette\Application\UI\Form;


/**
 * One printable ticket (/vstupenka/<code>/<print token>), linked from the e-mail with the tickets
 * (also a Z-lístek of the crew, with the crew timetable). The code alone could be enumerated, so a wrong
 * print token is a plain 404. A ticket of a reservation that is not paid (or was cancelled) shows no QR code
 * and no code, only the reason why. A logged-in administrator may show and print an unpaid one after
 * writing a reason: it is printed in red on the ticket and logged.
 * @property-read TicketTemplate $template
 */
final class TicketPresenter extends BasePresenter
{
	/** How long an unpaid ticket stays shown to the administrator after the reason was given. */
	private const RevealSeconds = 30 * 60;

	/** @var array<string, mixed> */
	private array $ticket;


	public function __construct(
		private readonly Tickets $tickets,
		private readonly TicketLinks $links,
		private readonly UnpaidTickets $unpaidTickets,
		private readonly FormFactory $formFactory,
		private readonly Actor $actor,
	) {
		parent::__construct();
	}


	public function actionDefault(string $code, string $token): void
	{
		$this->ticket = $this->tickets->findForPrint($code, $token) ?? $this->error();
		$this->getHttpResponse()->setHeader('X-Robots-Tag', 'noindex');
	}


	public function renderDefault(): void
	{
		$ticket = $this->ticket;
		$revealReason = $this->isAdmin() ? $this->revealedReason((string) $ticket['code']) : null;
		$visible = UnpaidTickets::isVisible($ticket) || $revealReason !== null;
		$t = $this->template;
		$t->ticket = $ticket;
		$t->visible = $visible;
		$t->revealReason = $revealReason;
		$t->canReveal = !$visible && $this->isAdmin() && in_array($ticket['reservation_status'] ?? null, UnpaidTickets::UnpaidStatuses, true);
		$t->qrCode = $visible ? $this->links->svgDataUri((string) $ticket['code']) : '';
		$t->schedule = $ticket['crew_member_id'] !== null ? CrewSchedule::parse($this->settings->get('crew_schedule')) : [];
	}


	/** Only for a logged-in administrator: the reason to show and print an unpaid ticket. */
	protected function createComponentRevealForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('reason', 'Důvod tisku (vytiskne se červeně na vstupenku)')
			->setRequired('Napište důvod, proč vstupenku tisknete.')
			->addRule($form::MinLength, 'Důvod napište aspoň %d znaky.', UnpaidTickets::MinReasonLength)
			->setMaxLength(UnpaidTickets::MaxReasonLength)
			->setHtmlAttribute('placeholder', 'např. zaplatí hotově u vstupu');
		$form->addSubmit('reveal', 'Zobrazit a vytisknout');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			if (!$this->isAdmin()) {
				$this->error('Jen pro přihlášené organizátory.', 403);
			}
			$this->actor->asAdmin((int) $this->getUser()->getId());
			try {
				$reason = $this->unpaidTickets->reveal((string) $this->ticket['code'], $data->reason);
			} catch (TicketError $e) {
				$form->addError($e->getMessage());
				return;
			}
			$this->getSession('unpaidTickets')->set((string) $this->ticket['code'], ['reason' => $reason, 'until' => time() + self::RevealSeconds]);
			$this->redirect('this');
		};
		return $form;
	}


	private function isAdmin(): bool
	{
		$user = $this->getUser();
		return $user->isLoggedIn() && $user->isInRole(Authenticator::Role);
	}


	/** The reason given for this ticket, while it is still valid. */
	private function revealedReason(string $code): ?string
	{
		$grant = $this->getSession('unpaidTickets')->get($code);
		return is_array($grant) && ($grant['until'] ?? 0) > time() ? (string) $grant['reason'] : null;
	}
}
