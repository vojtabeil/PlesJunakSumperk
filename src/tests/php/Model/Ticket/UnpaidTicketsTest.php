<?php

declare(strict_types=1);

namespace App\Tests\Model\Ticket;

use App\Model\Ticket\TicketError;
use App\Model\Ticket\UnpaidTickets;
use App\Tests\Support\DatabaseTestCase;


final class UnpaidTicketsTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->db->exec("INSERT INTO admin_users (id, login, name, password_hash) VALUES (7, 'pepa', 'Pepa Vrátný', 'x')");
		$this->actor()->asAdmin(7);
	}


	public function testOnlyPaidTicketsAndZListkyAreVisibleByLink(): void
	{
		$id = $this->confirmed('alice', [101]);
		$ticket = $this->tickets()->forReservation($id)[0];
		$print = fn(): array => $this->tickets()->findForPrint((string) $ticket['code'], (string) $ticket['print_token']) ?? [];

		self::assertFalse(UnpaidTickets::isVisible($print()), 'Confirmed, not paid');
		$this->db->exec("UPDATE reservations SET status = 'partially_paid' WHERE id = $id");
		self::assertFalse(UnpaidTickets::isVisible($print()));
		$this->db->exec("UPDATE reservations SET status = 'paid' WHERE id = $id");
		self::assertTrue(UnpaidTickets::isVisible($print()));
		$this->db->exec("UPDATE reservations SET status = 'cancelled' WHERE id = $id");
		self::assertFalse(UnpaidTickets::isVisible($print()));

		$crewId = $this->crew()->create('Jana', 'Bar');
		$crew = $this->crew()->find($crewId);
		self::assertTrue(UnpaidTickets::isVisible($this->tickets()->findForPrint($crew['code'], $crew['print_token']) ?? []));
	}


	public function testRevealNeedsAReasonAndIsLogged(): void
	{
		$id = $this->confirmed('alice', [101], standing: 1);

		try {
			$this->unpaid()->reveal($id, '  ok ');
			self::fail('A reason is required');
		} catch (TicketError) {
		}

		$tickets = $this->unpaid()->reveal($id, 'zaplatí   hotově u vstupu');
		self::assertCount(2, $tickets);
		$log = $this->db->query("SELECT reservation_id, admin_user_id, details FROM event_log WHERE action = 'ticket.revealed'")->fetch();
		self::assertSame($id, (int) $log['reservation_id']);
		self::assertSame(7, (int) $log['admin_user_id']);
		self::assertStringContainsString('zaplatí hotově u vstupu', (string) $log['details']);
	}


	public function testPaidOrCancelledReservationsNeedNoReveal(): void
	{
		$id = $this->confirmed('alice', [101]);
		$this->db->exec("UPDATE reservations SET status = 'cancelled' WHERE id = $id");
		$this->expectException(TicketError::class);
		$this->unpaid()->reveal($id, 'chci je vidět');
	}


	private function unpaid(): UnpaidTickets
	{
		return new UnpaidTickets($this->db, $this->tickets(), $this->eventLog());
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
