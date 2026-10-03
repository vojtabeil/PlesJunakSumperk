<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\SeatOverview;
use App\Model\Payment\VariableSymbol;
use App\Tests\DatabaseTestCase;


final class SeatOverviewTest extends DatabaseTestCase
{
	public function testStatusCombinesSeatAndReservation(): void
	{
		self::assertSame('free', SeatOverview::status('free', null));
		self::assertSame('held', SeatOverview::status('book', 'draft'));
		self::assertSame('unpaid', SeatOverview::status('reserved', 'confirmed'));
		self::assertSame('partial', SeatOverview::status('reserved', 'partially_paid'));
		self::assertSame('paid', SeatOverview::status('reserved', 'paid'));
	}


	public function testCountsAndFilters(): void
	{
		$service = $this->reservations();
		$service->start('a', 'alice@example.com');
		$service->hold('a', 101);
		$service->hold('a', 102);
		$paid = $service->confirm('a', 'Alice', '', true);
		(new ReservationAdmin($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog()))->markPaid($paid);

		$service->start('b', 'bob@example.com');
		$service->hold('b', 201);
		$service->confirm('b', 'Bob', '', true);

		$service->start('c', 'carol@example.com');
		$service->hold('c', 202);

		$overview = new SeatOverview($this->db);
		self::assertSame(['free' => 4, 'held' => 1, 'unpaid' => 1, 'partial' => 0, 'paid' => 2], $overview->counts());

		$held = $overview->seats('held');
		self::assertSame(['2/2'], array_column($held, 'label'));
		self::assertNull($held[0]['reservation_id'], 'A draft is not shown as a reservation');

		self::assertSame(['1/1', '1/2'], array_column($overview->seats(null, 'alice'), 'label'));
		self::assertSame(['2/1'], array_column($overview->seats(null, '2/1'), 'label'));
		self::assertSame($paid, $overview->seats('paid')[0]['reservation_id']);
	}
}
