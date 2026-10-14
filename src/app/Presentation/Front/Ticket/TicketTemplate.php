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

	/** QR code (SVG data URI) with the link of the entrance check; '' when not visible. */
	public string $qrCode;

	/** @var list<list<string>> crew timetable of a Z-lístek (CrewSchedule), empty otherwise */
	public array $schedule;
}
