<?php

declare(strict_types=1);

namespace App\Model\Payment;


/** Result of matching a payment to a reservation. Amounts in hellers. */
final class PaymentChange
{
	public function __construct(
		public readonly int $reservationId,
		public readonly string $oldStatus,
		public readonly string $newStatus,
		public readonly int $paidHalers,
		public readonly int $totalHalers,
	) {
	}


	/** The customer should hear about it (first full payment, or another partial payment). */
	public function isNotable(): bool
	{
		return $this->newStatus === 'partially_paid'
			|| ($this->newStatus === 'paid' && $this->oldStatus !== 'paid');
	}
}
