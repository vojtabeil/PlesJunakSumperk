<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Reservation;

use App\Presentation\Admin\BaseTemplate;


final class ReservationTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $reservations;

	public ?string $problemLabel;

	/** Current sort of the list (ReservationAdmin::Sorts key, "-key" = descending). */
	public string $sort;

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
}
