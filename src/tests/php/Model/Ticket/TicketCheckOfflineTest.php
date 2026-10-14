<?php

declare(strict_types=1);

namespace App\Tests\Model\Ticket;

use App\Tests\Support\DatabaseTestCase;


final class TicketCheckOfflineTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->db->exec("INSERT INTO admin_users (id, login, name, password_hash) VALUES (7, 'pepa', 'Pepa Vrátný', 'x'), (8, 'jana', 'Jana Vrátná', 'x')");
		$this->actor()->asAdmin(7);
	}


	public function testSnapshotHasAllTicketsWithReasonsAndIsLogged(): void
	{
		$paid = $this->confirmed('alice', [101], standing: 1);
		$this->reservationAdmin()->markPaid($paid);
		$cancelled = $this->confirmed('bob', [201]);
		$this->db->exec("UPDATE reservations SET status = 'cancelled' WHERE id = $cancelled");
		$this->crew()->create('Jana', 'Bar');
		$this->reservations()->hold('draft', 102); // a draft has no tickets

		$snapshot = $this->ticketCheck()->snapshot();

		self::assertSame('2026', $snapshot['vs_prefix']);
		self::assertFalse($snapshot['testing']);
		self::assertSame(['seat', 'standing', 'seat', 'crew'], array_column($snapshot['tickets'], 'kind'));
		self::assertSame(['name' => 'Jana', 'role' => 'Bar'], $snapshot['tickets'][3]['crew']);
		$reservations = array_column($snapshot['reservations'], null, 'id');
		self::assertNull($reservations[$paid]['invalid']);
		self::assertSame(0, $reservations[$paid]['remaining']);
		self::assertSame('Rezervace byla zrušena, vstupenka neplatí.', $reservations[$cancelled]['invalid']);
		self::assertContains('ticket.snapshot', $this->loggedActions());
	}


	public function testSyncAppliesEntriesOnceAndReportsConflicts(): void
	{
		$id = $this->confirmed('alice', [101, 102, 103]);
		[$a, $b, $c] = array_column($this->tickets()->forReservation($id), 'code');
		$this->actor()->asAdmin(8);
		$this->ticketCheck()->use($c); // used online meanwhile by another organizer
		$this->actor()->asAdmin(7);
		$entries = [
			['code' => $a, 'action' => 'use', 'at' => '2026-02-07 19:05:00'],
			['code' => strtolower($b), 'action' => 'use', 'at' => '2026-02-07 19:06:00'],
			['code' => $b, 'action' => 'undo', 'at' => '2026-02-07 19:07:00'],
			['code' => $c, 'action' => 'use', 'at' => '2026-02-07 19:08:00'],
			['code' => 'ZZZZZZ', 'action' => 'use', 'at' => '2026-02-07 19:09:00'],
		];

		$result = $this->ticketCheck()->sync($entries);

		self::assertSame(1, $result['applied']);
		self::assertSame(1, $result['skipped'], 'Used and taken back offline: nothing to do');
		self::assertSame([$c, 'ZZZZZZ'], array_column($result['conflicts'], 'code'));
		self::assertStringContainsString('Jana Vrátná', $result['conflicts'][0]['message']);
		$used = array_column($this->tickets()->forReservation($id), 'used_at', 'code');
		self::assertSame('2026-02-07 19:05:00', $used[$a], 'The time from the phone');
		self::assertNull($used[$b], 'Taken back offline');

		$again = $this->ticketCheck()->sync($entries);
		self::assertSame(0, $again['applied'], 'Uploading the same list again changes nothing');
		self::assertSame([$c, 'ZZZZZZ'], array_column($again['conflicts'], 'code'));
	}


	public function testSyncNeverSetsAFutureTime(): void
	{
		$id = $this->confirmed('alice', [101]);
		$code = $this->tickets()->forReservation($id)[0]['code'];
		$this->ticketCheck()->sync([['code' => $code, 'action' => 'use', 'at' => '2099-01-01 00:00:00']]);
		self::assertLessThanOrEqual(time() + 5, strtotime((string) $this->tickets()->findByCode($code)['used_at']));
	}


	/** @param list<int> $seats */
	private function confirmed(string $owner, array $seats, int $standing = 0): int
	{
		$service = $this->reservations();
		foreach ($seats as $seat) {
			$service->hold($owner, $seat);
		}
		if ($standing) {
			$service->setStanding($owner, $standing);
		}
		return $service->confirm($owner, 'Test Host', '', true, "$owner@example.com");
	}
}
