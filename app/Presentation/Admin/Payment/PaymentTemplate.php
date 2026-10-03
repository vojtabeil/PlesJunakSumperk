<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Payment;

use App\Presentation\Admin\BaseTemplate;


final class PaymentTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $payments;

	public int $problemCount;

	/** Only payments to resolve are listed. */
	public bool $problems;

	public string $bankName;

	public int $waitSeconds;

	public bool $canRewind;

	public ?\DateTimeImmutable $lastImportAt;
}
