<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Payment;

use App\Presentation\Admin\BaseTemplate;


final class PaymentTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $payments;

	public string $filter;

	/** @var array<string, string> */
	public array $filters;

	/** @var array<string, array{count: int, amount: float}> */
	public array $summary;

	public string $bankName;

	public int $waitSeconds;

	public bool $canRewind;

	public ?\DateTimeImmutable $lastImportAt;

	/** @var array<int, list<array<string, mixed>>> payment id => suggested reservations (PaymentCandidates::suggest) */
	public array $suggestions;
}
