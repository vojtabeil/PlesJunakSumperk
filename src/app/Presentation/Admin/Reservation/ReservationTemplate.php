<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Reservation;

use App\Presentation\Admin\BaseTemplate;


final class ReservationTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $reservations;

	public ?string $problemLabel;

	/** @var array<string, mixed> */
	public array $reservation;

	/** @var list<array<string, mixed>> */
	public array $activity;

	/** @var list<array<string, mixed>> */
	public array $payments;

	/** "Moje rezervace" of the customer; null for reservations without a link. */
	public ?string $customerLink;

	public ?\DateTimeImmutable $dueOn;

	/** @var list<array<string, mixed>> tickets (Tickets::forReservation) with print_link */
	public array $tickets;

	/** @var list<array<string, mixed>> tickets on the printable page (Tickets::forReservation) */
	public array $printTickets;

	/** @var list<string> QR codes (SVG data URIs) of $printTickets, in the same order */
	public array $qrCodes;

	/** Reason the tickets of an unpaid reservation are shown; null for a paid one. */
	public ?string $revealReason;

	/** @var array<string, string> */
	public array $settings;
}
