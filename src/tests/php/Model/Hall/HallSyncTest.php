<?php

declare(strict_types=1);

namespace App\Tests\Model\Hall;

use App\Model\Hall\HallError;
use App\Model\Hall\HallPlan;
use App\Model\Hall\HallPlanParser;
use App\Model\Hall\HallSync;
use App\Tests\Support\DatabaseTestCase;


/** The fixture hall: table 1 with seats 101-104, table 2 with seats 201-204. */
final class HallSyncTest extends DatabaseTestCase
{
	public function testPreviewChangesNothing(): void
	{
		$changes = $this->sync()->preview($this->newPlan());

		self::assertSame([205, 301], $changes->newSeats);
		self::assertSame([204], $changes->removedSeats);
		self::assertSame([104 => ['from' => 1, 'to' => 2]], $changes->movedSeats);
		self::assertSame([3], $changes->newTables);
		self::assertSame([], $changes->removedTables);
		self::assertSame([1 => ['from' => null, 'to' => 'Foglar']], $changes->renamedTables);
		self::assertSame([], $changes->affected);
		self::assertSame(8, (int) $this->db->query('SELECT COUNT(*) FROM seats WHERE active = 1')->fetchColumn());
		self::assertNull($this->sync()->plan());
	}


	public function testApplyStoresThePlanAndSynchronizesTheLayout(): void
	{
		$plan = $this->newPlan();
		$this->sync()->apply($plan, 'sal.svg', []);

		self::assertSame(
			[101, 102, 103, 104, 201, 202, 203, 205, 301],
			array_map('intval', $this->db->query('SELECT id FROM seats WHERE active = 1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)),
		);
		self::assertSame('0', (string) $this->db->query('SELECT active FROM seats WHERE id = 204')->fetchColumn(), 'Kept, inactive');
		self::assertSame(['2/104', '2', 'seat-104'], array_values(array_map('strval', (array) $this->db->query('SELECT label, table_id, svg_id FROM seats WHERE id = 104')->fetch())));
		self::assertSame('Foglar', $this->db->query('SELECT name FROM hall_tables WHERE id = 1')->fetchColumn());

		$stored = $this->sync()->plan();
		self::assertNotNull($stored);
		self::assertSame($plan->svg, $stored['svg']);
		self::assertSame('sal.svg', $stored['file_name']);
		self::assertContains('hall.replaced', $this->loggedActions());

		self::assertSame([205, 301], array_map('intval', array_column(
			array_filter($this->reservations()->hallLayout()['seats'], static fn(array $s): bool => $s['id'] > 204),
			'id',
		)), 'The seat picker shows only active seats');
	}


	public function testAffectedReservationsAreListedAndCancelledOnlyWhenConfirmed(): void
	{
		$service = $this->reservations();
		$service->hold('alice', 204);
		$service->hold('alice', 201);
		$alice = $service->confirm('alice', 'Alice Nováková', '777 123 456', true, 'alice@example.com');
		$this->reservationAdmin()->markPaid($alice);
		$service->hold('bob', 202);
		$plan = $this->newPlan(removeAlso: 202);

		$changes = $this->sync()->preview($plan);
		self::assertSame([$alice], $changes->affectedIds());
		self::assertSame(['2/4'], $changes->affected[0]['seats'], 'Labels of the lost seats');
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


	/** Table 1 named, seat 104 moved to table 2, seat 204 removed, seat 205 and table 3 (seat 301) new. */
	private function newPlan(?int $removeAlso = null): HallPlan
	{
		$seats = static fn(array $numbers): string => implode('', array_map(
			static fn(int $n): string => $n === $removeAlso ? '' : "<circle id=\"seat-$n\" r=\"5\"/>",
			$numbers,
		));
		return HallPlanParser::parse(
			'<svg xmlns="http://www.w3.org/2000/svg">'
			. '<g id="table-1"><text id="table-1-name">Foglar</text>' . $seats([101, 102, 103]) . '</g>'
			. '<g id="table-2">' . $seats([104, 201, 202, 203, 205]) . '</g>'
			. '<g id="table-3">' . $seats([301]) . '</g>'
			. '</svg>',
		);
	}


	private function sync(): HallSync
	{
		return new HallSync($this->db, $this->reservationAdmin(), $this->eventLog(), $this->actor());
	}
}
