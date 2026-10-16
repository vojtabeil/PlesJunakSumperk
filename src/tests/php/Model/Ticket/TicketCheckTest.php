<?php

declare(strict_types=1);

namespace App\Tests\Model\Ticket;

use App\Model\Ticket\TicketError;
use App\Tests\Support\DatabaseTestCase;


final class TicketCheckTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->db->exec("INSERT INTO admin_users (id, login, name, password_hash) VALUES (7, 'pepa', 'Pepa Vrátný', 'x')");
		$this->actor()->asAdmin(7);
	}


	public function testPaidTicketIsOkAndCanBeUsedOnce(): void
	{
		$id = $this->paid('alice', [101, 102]);
		$code = $this->codes($id)[0];

		$result = $this->ticketCheck()->lookup(strtolower($code));
		self::assertSame('ok', $result['status']);
		self::assertSame('A1/1', $result['ticket']['seat']);
		self::assertSame('Test Host', $result['reservation']['name']);
		self::assertCount(2, $result['tickets']);

		$after = $this->ticketCheck()->use($code);
		self::assertSame('used', $after['status']);
		self::assertSame('Pepa Vrátný', $after['ticket']['used_by']);
		self::assertNotNull($after['ticket']['used_at']);
		self::assertSame(1, $after['reservation']['unused']);

		try {
			$this->ticketCheck()->use($code);
			self::fail('A ticket can be used only once');
		} catch (TicketError $e) {
			self::assertSame('Vstupenka už byla mezitím použita.', $e->getMessage());
		}
		self::assertSame(['ticket.used'], array_values(array_filter($this->loggedActions(), static fn($a) => str_starts_with($a, 'ticket.'))));
	}


	public function testUnpaidReservationIsLetInWithAWarning(): void
	{
		$id = $this->confirmed('bob', [201], standing: 1);
		$result = $this->ticketCheck()->lookup($this->codes($id)[0]);
		self::assertSame('unpaid', $result['status']);
		self::assertSame(600, $result['reservation']['remaining']);
		self::assertSame('Nezaplaceno – zbývá doplatit 600 Kč.', $result['message']);

		self::assertSame('used', $this->ticketCheck()->use($this->codes($id)[0])['status']);
	}


	public function testCancelledReservationIsInvalid(): void
	{
		$id = $this->paid('alice', [101]);
		$code = $this->codes($id)[0];
		$this->reservationAdmin()->cancel($id);

		$result = $this->ticketCheck()->lookup($code);
		self::assertSame('invalid', $result['status']);
		self::assertSame('Rezervace byla zrušena, vstupenka neplatí.', $result['message']);

		$this->expectExceptionObject(new TicketError('Rezervace byla zrušena, vstupenka neplatí.'));
		$this->ticketCheck()->use($code);
	}


	public function testTestTicketsAreValidOnlyInTheTestingStage(): void
	{
		$this->setSettings(['site_mode' => 'testing']);
		$id = $this->paid('tester', [101]);
		$code = $this->codes($id)[0];
		self::assertSame('ok', $this->ticketCheck()->lookup($code)['status']);
		self::assertSame(['used' => 0, 'total' => 1], $this->entranceTotals());

		$this->setSettings(['site_mode' => 'closed']);
		self::assertSame('invalid', $this->ticketCheck()->lookup($code)['status']);
		self::assertSame(['used' => 0, 'total' => 0], $this->entranceTotals());
	}


	public function testUnknownInput(): void
	{
		self::assertSame('Vstupenka s tímto kódem neexistuje.', $this->ticketCheck()->lookup('ABCDEF')['message']);
		self::assertSame('Tohle není kód vstupenky (6 znaků 0–9 a A–F).', $this->ticketCheck()->lookup('hello')['message']);
		self::assertSame('Rezervace s tímto číslem neexistuje.', $this->ticketCheck()->lookup('42')['message']);
	}


	public function testReservationNumberShowsAllTicketsAndUsesThemTogether(): void
	{
		$id = $this->paid('alice', [101, 102], standing: 1);
		$codes = $this->codes($id);
		$this->ticketCheck()->use($codes[0]);

		foreach ([(string) $id, '2026' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)] as $typed) {
			$result = $this->ticketCheck()->lookup($typed);
			self::assertSame('ok', $result['status'], "Typed as '$typed'");
			self::assertNull($result['ticket']);
			self::assertSame(2, $result['reservation']['unused']);
		}

		$result = $this->ticketCheck()->useReservation($id);
		self::assertSame('used', $result['status']);
		self::assertSame('Všechny vstupenky rezervace už byly použity.', $result['message']);
		self::assertSame(['used' => 3, 'total' => 3], $this->entranceTotals());

		$details = $this->db->query("SELECT details FROM event_log WHERE action = 'ticket.used' ORDER BY id DESC LIMIT 1")->fetchColumn();
		self::assertStringContainsString('"count":2', (string) $details);

		$this->expectExceptionObject(new TicketError('Rezervace nemá žádné nepoužité vstupenky.'));
		$this->ticketCheck()->useReservation($id);
	}


	public function testUndoTakesTheEntryBack(): void
	{
		$id = $this->paid('alice', [101]);
		$code = $this->codes($id)[0];
		$this->ticketCheck()->use($code);

		$result = $this->ticketCheck()->undo($code);
		self::assertSame('ok', $result['status']);
		self::assertNull($result['ticket']['used_at']);
		self::assertContains('ticket.unused', $this->loggedActions());

		$this->expectExceptionObject(new TicketError('Vstupenka ještě nebyla použita.'));
		$this->ticketCheck()->undo($code);
	}


	/** @return list<string> */
	private function codes(int $reservationId): array
	{
		return array_column($this->tickets()->forReservation($reservationId), 'code');
	}


	/** @param list<int> $seats */
	private function paid(string $owner, array $seats, int $standing = 0): int
	{
		$id = $this->confirmed($owner, $seats, $standing);
		$this->reservationAdmin()->markPaid($id);
		return $id;
	}


	/** @return array{used: int, total: int} */
	private function entranceTotals(): array
	{
		$stats = $this->ticketCheck()->entranceStats();
		return ['used' => $stats['used'], 'total' => $stats['total']];
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
