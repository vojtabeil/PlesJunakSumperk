<?php

declare(strict_types=1);

namespace App\Presentation\Front\Ticket;

use App\Presentation\Front\BaseTemplate;


final class TicketTemplate extends BaseTemplate
{
	/** @var array<string, mixed> from Tickets::findForPrint() */
	public array $ticket;

	/** False for a ticket of an unpaid or cancelled reservation: no QR code, no code. */
	public bool $visible;

	/** A logged-in administrator may show this unpaid ticket with a reason (revealForm). */
	public bool $canReveal;

	/** The administrator's reason, printed in red on an unpaid ticket shown by them; null otherwise. */
	public ?string $revealReason;

	/** QR code (SVG data URI) with the link of the entrance check; '' when not visible. */
	public string $qrCode;

	/** @var list<list<string>> crew timetable of a Z-lístek (CrewSchedule), empty otherwise */
	public array $schedule;
}
