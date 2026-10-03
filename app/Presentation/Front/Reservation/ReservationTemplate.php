<?php

declare(strict_types=1);

namespace App\Presentation\Front\Reservation;

use App\Presentation\Front\BaseTemplate;


final class ReservationTemplate extends BaseTemplate
{
	/** @var array<string, mixed> from ReservationService (with remaining, due_on, seat_labels, variable_symbol) */
	public array $reservation;

	/** Right after the confirmation (shows "Rezervace přijata"). */
	public bool $justCreated;

	public string $account;

	public string $iban;

	/** QR Platba image as a data URI; null when nothing is left to pay. */
	public ?string $qrCode;

	/** Not paid after the due date. */
	public bool $overdue;
}
