<?php

declare(strict_types=1);

namespace App\Tests\Model\Hall;

use App\Model\Hall\HallError;
use App\Model\Hall\HallPlan;
use App\Model\Hall\HallPlanParser;
use App\Model\Hall\HallSync;
use App\Tests\Support\DatabaseTestCase;


/** The fixture hall: table A1 with seats A1/1-A1/4 (ids 101-104), table A2 with A2/1-A2/4 (ids 201-204). */
final class HallSyncTest extends DatabaseTestCase
{
	public function testPreviewChangesNothing(): void
	{
		$changes = $this->sync()->preview($this->newPlan());

		self::assertSame(['A2/5', 'A3/1'], $changes->newSeats);
		self::assertSame(['A2/4'], $changes->removedSeats);
		self::assertSame([204], $changes->removedSeatIds);
		self::assertSame(['A3'], $changes->newTables);
		self::assertSame([], $changes->removedTables);
		self::assertSame(['A1' => ['from' => null, 'to' => 'Foglar']], $changes->renamedTables);
		self::assertSame([], $changes->affected);
		self::assertSame(8, (int) $this->db->query('SELECT COUNT(*) FROM seats WHERE active = 1')->fetchColumn());
		self::assertNull($this->sync()->plan());
	}


	public function testApplyStoresThePlanAndSynchronizesTheLayout(): void
	{
		$plan = $this->newPlan();
		$this->sync()->apply($plan, 'sal.svg', []);

		self::assertSame(
			['A1/1', 'A1/2', 'A1/3', 'A1/4', 'A2/1', 'A2/2', 'A2/3', 'A2/5', 'A3/1'],
			$this->db->query('SELECT label FROM seats WHERE active = 1 ORDER BY sort_order')->fetchAll(\PDO::FETCH_COLUMN),
		);
		self::assertSame(101, (int) $this->db->query("SELECT id FROM seats WHERE label = 'A1/1'")->fetchColumn(), 'Seats keep their ids');
		self::assertSame('0', (string) $this->db->query('SELECT active FROM seats WHERE id = 204')->fetchColumn(), 'Kept, inactive');
		self::assertSame('Foglar', $this->db->query("SELECT name FROM hall_tables WHERE label = 'A1'")->fetchColumn());
		self::assertSame('seat-A3-1', $this->db->query(
			"SELECT s.svg_id FROM seats s JOIN hall_tables t ON t.id = s.table_id WHERE s.label = 'A3/1' AND t.label = 'A3'",
		)->fetchColumn());

		$stored = $this->sync()->plan();
		self::assertNotNull($stored);
		self::assertSame($plan->svg, $stored['svg']);
		self::assertSame('sal.svg', $stored['file_name']);
		self::assertContains('hall.replaced', $this->loggedActions());

		self::assertSame(
			['A1/1', 'A1/2', 'A1/3', 'A1/4', 'A2/1', 'A2/2', 'A2/3', 'A2/5', 'A3/1'],
			array_column($this->reservations()->hallLayout()['seats'], 'label'),
			'The seat picker shows only active seats, in hall order',
		);
	}


	public function testAffectedReservationsAreListedAndCancelledOnlyWhenConfirmed(): void
	{
		$service = $this->reservations();
		$service->hold('alice', 204);
		$service->hold('alice', 201);
		$alice = $service->confirm('alice', 'Alice Nováková', '777 123 456', true, 'alice@example.com');
		$this->reservationAdmin()->markPaid($alice);
		$service->hold('bob', 202);
		$plan = $this->newPlan(removeAlso: 'A2-2');

		$changes = $this->sync()->preview($plan);
		self::assertSame([$alice], $changes->affectedIds());
		self::assertSame(['A2/4'], $changes->affected[0]['seats'], 'Labels of the lost seats');
		self::assertSame(700, $changes->affectedPaid());
		self::assertSame(1, $changes->heldSeats, "Bob's draft only loses the held seat");

		try {
			$this->sync()->apply($plan, 'sal.svg', []);
			self::fail('The affected reservations must be confirmed');
		} catch (HallError) {
		}
		self::assertSame('paid', $this->db->query("SELECT status FROM reservations WHERE id = $alice")->fetchColumn(), 'Nothing changed');

		$this->sync()->apply($plan, 'sal.svg', [$alice]);
		self::assertSame('cancelled', $this->db->query("SELECT status FROM reservations WHERE id = $alice")->fetchColumn());
		self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM seat_bookings')->fetchColumn());
		$reason = $this->db->query("SELECT details FROM event_log WHERE action = 'reservation.cancelled'")->fetchColumn();
		self::assertStringContainsString(HallSync::CancelReason, (string) $reason);
	}


	public function testRemovedSeatCannotBeHeld(): void
	{
		$this->sync()->apply($this->newPlan(), 'sal.svg', []);
		$this->expectExceptionMessage('Toto místo už je obsazené.');
		$this->reservations()->hold('alice', 204);
	}

	public function testInvalidPlanIsRefused(): void
	{
		$this->expectException(HallError::class);
		$this->sync()->apply(HallPlanParser::parse('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'x.svg', []);
	}


	/** Table A1 named, seat A2/4 removed, seat A2/5 and table A3 (seat A3/1) new. */
	private function newPlan(?string $removeAlso = null): HallPlan
	{
		$seats = static fn(string $table, array $numbers): string => implode('', array_map(
			static fn(int $n): string => "$table-$n" === $removeAlso ? '' : "<circle id=\"seat-$table-$n\" r=\"5\"/>",
			$numbers,
		));
		return HallPlanParser::parse(
			'<svg xmlns="http://www.w3.org/2000/svg">'
			. '<g id="table-A1"><text id="table-A1-name">Foglar</text>' . $seats('A1', [1, 2, 3, 4]) . '</g>'
			. '<g id="table-A2">' . $seats('A2', [1, 2, 3, 5]) . '</g>'
			. '<g id="table-A3">' . $seats('A3', [1]) . '</g>'
			. '</svg>',
		);
	}


	private function sync(): HallSync
	{
		return new HallSync($this->db, $this->reservationAdmin(), $this->eventLog(), $this->actor());
	}
}
