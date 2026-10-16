<?php

declare(strict_types=1);

namespace App\Tests\Model\Hall;

use App\Model\Hall\TableAvailability;
use App\Model\Reservation\ReservationError;
use App\Model\Reservation\SeatOverview;
use App\Tests\Support\DatabaseTestCase;


/** The fixture hall: table A1 with seats 101-104, table A2 with seats 201-204. */
final class TableAvailabilityTest extends DatabaseTestCase
{
	public function testTableOutOfSaleDisappearsFromTheSale(): void
	{
		$service = $this->reservations();
		$service->hold('bob', 201);

		$changes = $this->availability()->save(['A2']);

		self::assertSame(['unavailable' => ['A2'], 'available' => [], 'released' => 1], $changes, "Bob's held seat is released");
		self::assertContains('hall.availability', $this->loggedActions());
		self::assertSame([false, false], [
			$this->availability()->tables()[1]['available'],
			$service->hallLayout()['tables'][1]['available'],
		]);
		self::assertSame(['A1/1', 'A1/2', 'A1/3', 'A1/4'], array_column($service->hallLayout()['seats'], 'label'), 'Only seats for sale');

		$stats = $this->reservationAdmin()->stats();
		self::assertSame([4, 4], [$stats['total'], $stats['free']]);
		$counts = (new SeatOverview($this->db))->counts();
		self::assertSame([4, 4], [$counts['free'], $counts['unavailable']]);

		$this->expectExceptionObject(new ReservationError('Toto místo není v prodeji.'));
		$service->hold('alice', 202);
	}


	public function testReservationsAtATableOutOfSaleStay(): void
	{
		$service = $this->reservations();
		$service->hold('alice', 101);
		$alice = $service->confirm('alice', 'Alice', '', true, 'alice@example.com');

		$this->availability()->save(['A1']);

		self::assertSame('confirmed', $this->db->query("SELECT status FROM reservations WHERE id = $alice")->fetchColumn());
		$seat = (new SeatOverview($this->db))->seats(null, 'A1/1')[0];
		self::assertSame(['unpaid', false], [$seat['status'], $seat['table_available']], 'A sold seat keeps its status');
		self::assertSame(1, $this->reservationAdmin()->stats()['reserved']);
		self::assertSame([], $service->state('bob')['taken'], 'The seat picker counts only seats for sale');
		self::assertSame(1, $this->availability()->tables()[0]['reserved']);
	}


	public function testTablesComeBackAndNothingChangedIsNotLogged(): void
	{
		$this->availability()->save(['A1', 'A2', 'X9']);
		$changes = $this->availability()->save(['A2']);

		self::assertSame(['unavailable' => [], 'available' => ['A1'], 'released' => 0], $changes);
		self::assertSame(['unavailable' => [], 'available' => [], 'released' => 0], $this->availability()->save(['A2']));
		self::assertCount(2, array_keys($this->loggedActions(), 'hall.availability', true));
	}


	private function availability(): TableAvailability
	{
		return new TableAvailability($this->db, $this->eventLog());
	}
}
