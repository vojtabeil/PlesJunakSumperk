<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\Refunds;
use App\Model\Payment\VariableSymbol;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;


final class RefundsTest extends DatabaseTestCase
{
	public function testCancelledPaidReservationOwesEverythingUntilRefunded(): void
	{
		$id = $this->reservation([101, 102]);
		$this->pay($id, 700);
		$this->reservationAdmin()->cancel($id);

		self::assertSame(70000, $this->refunds()->due($id));
		self::assertSame([1, 70000], $this->refunds()->summary());
		self::assertSame([$id], array_column($this->reservationAdmin()->search(problem: 'refund'), 'id'));

		$this->refunds()->record($id, 30000, new DateTimeImmutable('2026-11-02'), 'cash', 'u vstupu');
		self::assertSame(40000, $this->refunds()->due($id));
		$this->refunds()->record($id, 40000, new DateTimeImmutable('2026-11-03'), 'bank');
		self::assertSame(0, $this->refunds()->due($id));
		self::assertSame([0, 0], $this->refunds()->summary());
		self::assertSame([30000, 40000], array_column($this->refunds()->forReservation($id), 'amount'));
		self::assertContains('refund.recorded', $this->loggedActions());

		$this->expectExceptionObject(new PaymentError("Vrátit lze nejvýše 0\u{A0}Kč."));
		$this->refunds()->record($id, 100, new DateTimeImmutable('2026-11-03'), 'bank');
	}


	public function testOverpaymentIsReturnedAndThePaymentStopsBeingAProblem(): void
	{
		$id = $this->reservation([101]);
		$this->pay($id, 500);
		self::assertSame(15000, $this->refunds()->due($id), '500 paid for 350');
		self::assertSame('overpaid', $this->lastStatus());

		$this->refunds()->record($id, 15000, new DateTimeImmutable('2026-11-02'), 'bank');
		self::assertSame('matched', $this->lastStatus(), 'Fully refunded = resolved');
	}


	public function testPaymentForACancelledReservationIsToBeReturned(): void
	{
		$id = $this->reservation([101]);
		$this->reservationAdmin()->cancel($id);

		$change = $this->pay($id, 350);

		self::assertNotNull($change);
		self::assertFalse($change->isNotable(), 'No e-mail to the customer');
		self::assertSame(['cancelled', 'overpaid'], [
			$this->db->query("SELECT status FROM reservations WHERE id = $id")->fetchColumn(),
			$this->lastStatus(),
		]);
		self::assertSame(35000, $this->refunds()->due($id));
		self::assertContains('payment.after_cancel', $this->loggedActions());

		// Assigned by mistake: taking it back leaves nothing to return.
		$this->matcher()->unassign((int) $this->db->query('SELECT MAX(id) FROM bank_transactions')->fetchColumn());
		self::assertSame(0, $this->refunds()->due($id));
	}


	public function testRefundWrittenByMistakeCanBeDeleted(): void
	{
		$id = $this->reservation([101]);
		$this->pay($id, 350);
		$this->reservationAdmin()->cancel($id);
		$this->refunds()->record($id, 35000, new DateTimeImmutable('2026-11-02'), 'bank');

		$refundId = $this->refunds()->forReservation($id)[0]['id'];
		self::assertSame($id, $this->refunds()->delete($refundId));
		self::assertSame(35000, $this->refunds()->due($id));
		self::assertContains('refund.deleted', $this->loggedActions());
	}


	public function testUnpaidReservationOwesNothing(): void
	{
		$id = $this->reservation([101]);
		$this->reservationAdmin()->cancel($id);
		self::assertSame(0, $this->refunds()->due($id));

		$this->expectException(PaymentError::class);
		$this->refunds()->record($id, 100, new DateTimeImmutable('2026-11-02'), 'bank');
	}


	private function refunds(): Refunds
	{
		return new Refunds($this->db, $this->eventLog(), $this->actor());
	}


	private function matcher(): PaymentMatcher
	{
		return new PaymentMatcher($this->db, new VariableSymbol($this->settings()), $this->eventLog());
	}


	/** @param list<int> $seats */
	private function reservation(array $seats): int
	{
		$service = $this->reservations();
		$owner = 'owner-' . uniqid();
		foreach ($seats as $seat) {
			$service->hold($owner, $seat);
		}
		return $service->confirm($owner, 'Platící Host', '', true, "$owner@example.com");
	}


	/** A bank payment with the reservation's VS, matched like an import does. */
	private function pay(int $reservationId, int $czk): ?\App\Model\Payment\PaymentChange
	{
		$this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, variable_symbol, counter_account, match_status)
			VALUES ('mock', ?, CURDATE(), ?, ?, '19-123/0800', 'no_vs')",
		)->execute([uniqid(), $czk, VariableSymbol::format('2026', $reservationId)]);
		return $this->matcher()->apply((int) $this->db->lastInsertId());
	}


	private function lastStatus(): string
	{
		return (string) $this->db->query('SELECT match_status FROM bank_transactions ORDER BY id DESC LIMIT 1')->fetchColumn();
	}
}
