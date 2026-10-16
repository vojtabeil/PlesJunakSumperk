<?php

declare(strict_types=1);

namespace App\Tests\Model\Ticket;

use App\Model\Ticket\Tickets;
use App\Tests\Support\DatabaseTestCase;


final class TicketsTest extends DatabaseTestCase
{
	public function testConfirmationIssuesOneTicketPerSeatAndStandingTicket(): void
	{
		$id = $this->confirmed('alice', [102, 101], standing: 2);

		$tickets = $this->tickets()->forReservation($id);
		self::assertSame([101, 102, null, null], array_map(
			static fn(array $t): ?int => $t['seat_id'] === null ? null : (int) $t['seat_id'],
			$tickets,
		), 'Seats first, then standing tickets');
		self::assertSame(['A1/1', 'A1/2', null, null], array_column($tickets, 'seat_label'));

		$codes = array_column($tickets, 'code');
		self::assertCount(4, array_unique($codes));
		foreach ($tickets as $ticket) {
			self::assertMatchesRegularExpression('~^[0-9A-F]{6}$~', $ticket['code']);
			self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $ticket['print_token']);
			self::assertNull($ticket['used_at']);
		}
	}


	public function testDraftHasNoTickets(): void
	{
		$service = $this->reservations();
		$service->hold('bob', 101);
		self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
	}


	public function testFindByCodeAcceptsTypedVariants(): void
	{
		$id = $this->confirmed('alice', [101]);
		$this->db->prepare('UPDATE tickets SET code = ? WHERE reservation_id = ?')->execute(['A0B1C2', $id]);

		foreach (['A0B1C2', 'a0b1c2', ' a0b-1c2 ', 'AOB1C2'] as $typed) {
			self::assertSame('A0B1C2', $this->tickets()->findByCode($typed)['code'] ?? null, "Typed as '$typed'");
		}
		self::assertNull($this->tickets()->findByCode('A0B1C3'));
		self::assertNull($this->tickets()->findByCode('A0B1C'));
	}


	public function testNormalizeCode(): void
	{
		self::assertSame('00FF0A', Tickets::normalizeCode('oo ff-0a'));
		self::assertNull(Tickets::normalizeCode(''));
		self::assertNull(Tickets::normalizeCode('12345G'));
		self::assertNull(Tickets::normalizeCode('1234567'));
	}


	public function testPrintLinkNeedsTheSecretToken(): void
	{
		$id = $this->confirmed('alice', [101]);
		$ticket = $this->tickets()->forReservation($id)[0];

		self::assertSame($ticket['id'], $this->tickets()->findForPrint($ticket['code'], $ticket['print_token'])['id'] ?? null);
		self::assertNull($this->tickets()->findForPrint($ticket['code'], str_repeat('0', 32)));
		self::assertNull($this->tickets()->findForPrint($ticket['code'], ''));
	}


	public function testDeletedTestReservationTakesItsTickets(): void
	{
		$this->setSettings(['site_mode' => 'testing']);
		$this->confirmed('tester', [101], standing: 1);
		self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn());

		$this->reservationAdmin()->deleteTestReservations();
		self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
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
