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

	/** Days after the due date of an unpaid reservation; null when not overdue. */
	public ?int $overdueDays;

	/** @var list<array{id: int, amount: int, refunded_on: string, method: string, note: ?string, created_by: ?string}> */
	public array $refunds;

	/** Hellers still to return (Refunds::due). */
	public int $refundDue;

	/** @var list<string> accounts the customer paid from (for a refund by transfer) */
	public array $refundAccounts;

	public string $eventName;

	/** @var list<array<string, mixed>> tickets (Tickets::forReservation) with print_link */
	public array $tickets;
}
