<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Reservation;

use App\Presentation\Admin\BaseTemplate;


final class ReservationTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $reservations;

	/** @var array<string, mixed> */
	public array $reservation;

	/** @var list<array<string, mixed>> */
	public array $activity;
}
