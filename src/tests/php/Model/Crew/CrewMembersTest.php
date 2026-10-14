<?php

declare(strict_types=1);

namespace App\Tests\Model\Crew;

use App\Model\Crew\CrewError;
use App\Model\Crew\CrewSchedule;
use App\Model\Mail\CrewMailer;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketLinks;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\RecordingMailSender;
use Nette\Application\LinkGenerator;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tracy\ILogger;


final class CrewMembersTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->db->exec("INSERT INTO admin_users (id, login, name, password_hash) VALUES (7, 'pepa', 'Pepa Vrátný', 'x')");
		$this->actor()->asAdmin(7);
	}


	public function testCreatesMemberWithOneTicketWithoutSeatOrReservation(): void
	{
		$id = $this->crew()->create('  Jana   Nováková ', 'Bar', 'jana@example.com', 'jen sobota');

		$member = $this->crew()->find($id);
		self::assertSame('Jana Nováková', $member['name'], 'Whitespace is cleaned');
		self::assertSame('Bar', $member['role']);
		self::assertSame('jana@example.com', $member['email']);
		self::assertMatchesRegularExpression('~^[0-9A-F]{6}$~', $member['code']);

		$ticket = $this->tickets()->findByCode($member['code']);
		self::assertSame($id, (int) $ticket['crew_member_id']);
		self::assertNull($ticket['reservation_id']);
		self::assertNull($ticket['seat_id']);
		self::assertSame(['crew.created'], $this->loggedActions());
	}


	public function testValidatesInput(): void
	{
		foreach ([['', 'Bar', ''], ['Jana', ' ', ''], ['Jana', 'Bar', 'není-email']] as [$name, $role, $email]) {
			try {
				$this->crew()->create($name, $role, $email);
				self::fail("Refused: '$name', '$role', '$email'");
			} catch (CrewError) {
			}
		}
		self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM crew_members')->fetchColumn());
	}


	public function testImportsLinesSeparatedBySemicolonsOrTabs(): void
	{
		$count = $this->crew()->import("Jana Nováková; Bar\n\nPetr Dvořák\tKuchyně\tpetr@example.com\r\nEva Malá ; Šatna ; ");

		self::assertSame(3, $count);
		self::assertSame(['Bar' => 1, 'Kuchyně' => 1, 'Šatna' => 1], $this->crew()->roles());
		self::assertSame('petr@example.com', $this->crew()->all(search: 'Petr')[0]['email']);
		self::assertSame(3, (int) $this->db->query('SELECT COUNT(*) FROM tickets WHERE crew_member_id IS NOT NULL')->fetchColumn());
		self::assertSame(['crew.imported'], $this->loggedActions());
	}


	public function testImportWithAWrongLineCreatesNothing(): void
	{
		try {
			$this->crew()->import("Jana Nováková; Bar\nPetr bez funkce\nEva; Šatna; spatny-email");
			self::fail('Wrong lines are refused');
		} catch (CrewError $e) {
			self::assertStringContainsString('Řádek 2:', $e->getMessage());
			self::assertStringContainsString('Řádek 3:', $e->getMessage());
			self::assertStringNotContainsString('Řádek 1:', $e->getMessage());
		}
		self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM crew_members')->fetchColumn());
	}


	public function testFiltersByRoleAndSearch(): void
	{
		$this->crew()->import("Jana; Bar\nPetr; Bar\nEva; Šatna");
		self::assertSame(['Jana', 'Petr'], array_column($this->crew()->all('Bar'), 'name'));
		self::assertSame(['Eva'], array_column($this->crew()->all(search: 'šat'), 'name'));
		$code = $this->crew()->all(search: 'Eva')[0]['code'];
		self::assertSame(['Eva'], array_column($this->crew()->all(search: strtolower($code)), 'name'), 'Search by code');
	}


	public function testPickUpAndStats(): void
	{
		$this->crew()->import("Jana; Bar\nPetr; Bar");
		$ids = array_map(static fn(array $m): int => (int) $m['id'], $this->crew()->all());

		self::assertSame(2, $this->crew()->setPickedUp($ids, true));
		self::assertSame(0, $this->crew()->setPickedUp($ids, true), 'Already picked up');
		self::assertSame(1, $this->crew()->setPickedUp([$ids[0]], false));
		self::assertSame(['total' => 2, 'picked_up' => 1, 'emailed' => 0, 'used' => 0], $this->crew()->stats());
	}


	public function testEntranceCheckAcceptsTheZListekOnce(): void
	{
		$id = $this->crew()->create('Jana Nováková', 'Bar');
		$code = $this->crew()->find($id)['code'];

		$result = $this->ticketCheck()->lookup($code);
		self::assertSame('ok', $result['status']);
		self::assertSame(['name' => 'Jana Nováková', 'role' => 'Bar'], $result['crew']);
		self::assertSame('crew', $result['ticket']['kind']);
		self::assertNull($result['reservation']);

		self::assertSame('used', $this->ticketCheck()->use($code)['status']);
		self::assertSame('used', $this->ticketCheck()->lookup($code)['status']);
		self::assertSame('ok', $this->ticketCheck()->undo($code)['status']);

		$log = $this->db->query("SELECT reservation_id, details FROM event_log WHERE action = 'ticket.used'")->fetch();
		self::assertNull($log['reservation_id']);
		self::assertStringContainsString('Jana Nováková (Bar)', (string) $log['details']);
	}


	public function testEntranceStatsCountTheThreeKindsOfTickets(): void
	{
		$this->confirmed('alice', [101, 102], standing: 1);
		$this->crew()->import("Jana; Bar\nPetr; Kuchyně");
		$this->ticketCheck()->use($this->crew()->all(search: 'Jana')[0]['code']);

		$stats = $this->ticketCheck()->entranceStats();
		self::assertSame(['used' => 0, 'total' => 2], $stats['kinds']['seat']);
		self::assertSame(['used' => 0, 'total' => 1], $stats['kinds']['standing']);
		self::assertSame(['used' => 1, 'total' => 2], $stats['kinds']['crew']);
		self::assertSame(['used' => 1, 'total' => 5], ['used' => $stats['used'], 'total' => $stats['total']]);
	}


	public function testListsTicketsByKind(): void
	{
		$this->confirmed('alice', [102, 101], standing: 2);
		$this->crew()->import("Petr; Kuchyně
Jana; Bar");

		self::assertSame(['1/1', '1/2'], array_column($this->tickets()->listing('seat'), 'seat_label'));
		self::assertSame(['Test Host', 'Test Host'], array_column($this->tickets()->listing('standing'), 'name'));
		self::assertSame(['Jana', 'Petr'], array_column($this->tickets()->listing('crew'), 'name'), 'By role, then name');
	}


	public function testDeleteRemovesTheTicketButNotAfterEntry(): void
	{
		$this->crew()->import("Jana; Bar\nPetr; Bar");
		[$jana, $petr] = $this->crew()->all();

		$this->crew()->delete((int) $petr['id']);
		self::assertNull($this->tickets()->findByCode($petr['code']));

		$this->ticketCheck()->use($jana['code']);
		$this->expectException(CrewError::class);
		$this->crew()->delete((int) $jana['id']);
	}


	public function testPrintLinkShowsTheZListek(): void
	{
		$id = $this->crew()->create('Jana Nováková', 'Bar');
		$member = $this->crew()->find($id);

		$ticket = $this->tickets()->findForPrint($member['code'], $member['print_token']);
		self::assertSame('Jana Nováková', $ticket['crew_name']);
		self::assertNull($this->tickets()->findForPrint($member['code'], str_repeat('0', 32)));
	}


	public function testSendsTheZListekByEmailWithTheSchedule(): void
	{
		$this->setSettings(['crew_schedule' => "Přípravy v pátek | 16:00–18:30\n\nÚklid | neděle 8:00"]);
		$id = $this->crew()->create('Jana Nováková', 'Bar', 'jana@example.com');
		$noEmail = $this->crew()->create('Petr Dvořák', 'Bar');
		$sender = new RecordingMailSender;

		self::assertTrue($this->crewMailer($sender)->send($id));
		self::assertFalse($this->crewMailer($sender)->send($noEmail), 'Nobody to send to');

		self::assertCount(1, $sender->sent);
		$message = $sender->sent[0];
		$code = $this->crew()->find($id)['code'];
		self::assertSame('jana@example.com', $message->to);
		self::assertSame('Z-lístek – Testovací ples', $message->subject);
		self::assertStringContainsString("Kód: $code", $message->text);
		self::assertStringContainsString('Přípravy v pátek · 16:00–18:30', $message->text);
		self::assertStringNotContainsString('Rezervace a platební údaje', $message->text);
		self::assertStringStartsWith("\x89PNG", $message->inlineImages["ticket-$code"] ?? '');
		self::assertNotNull($this->crew()->find($id)['emailed_at']);
		self::assertContains('email.crew_sent', $this->loggedActions());
		self::assertContains('email.crew_failed', $this->loggedActions());
	}


	public function testScheduleParsing(): void
	{
		self::assertSame(
			[['Přípravy', '16:00'], ['Úklid']],
			CrewSchedule::parse(" Přípravy | 16:00 \r\n\n | \nÚklid"),
		);
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


	private function crewMailer(RecordingMailSender $sender): CrewMailer
	{
		$logger = new class implements ILogger {
			public function log(mixed $value, string $level = self::INFO): void
			{
			}
		};
		return new CrewMailer(
			$this->crew(),
			new Settings($this->db, $this->eventLog()),
			$sender,
			$this->service(LatteFactory::class),
			new TicketLinks($this->service(LinkGenerator::class)),
			$logger,
			$this->eventLog(),
		);
	}
}
