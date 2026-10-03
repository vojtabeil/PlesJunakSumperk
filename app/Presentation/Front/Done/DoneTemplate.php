<?php

declare(strict_types=1);

namespace App\Presentation\Front\Done;

use App\Presentation\Front\BaseTemplate;


final class DoneTemplate extends BaseTemplate
{
	/** @var array<string, mixed> */
	public array $reservation;

	public string $account;

	/** Amount still to pay in CZK. */
	public int $remaining;

	/** QR Platba image as a data URI; null when nothing is left to pay. */
	public ?string $qrCode;
}
