<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\GuestListCsv;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationError;
use App\Tests\DatabaseTestCase;


final class ReservationAdminTest extends DatabaseTestCase
{
	private ReservationAdmin $admin;


	protected function setUp(): void
	{
		parent::setUp();
		$this->admin = new ReservationAdmin($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog());
	}


	public function testStatsCountSeatsTicketsAndMoney(): void
	{
		$paid = $this->confirmed('alice@example.com', [101, 102], standing: 1);
		$this->confirmed('bob@example.com', [201]);
		$this->admin->markPaid($paid);

		$stats = $this->admin->stats();
		self::assertSame(8, $stats['total']);
		self::assertSame(3, $stats['reserved']);
		self::assertSame(5, $stats['free']);
		self::assertSame(1, $stats['standing']);
		self::assertSame(1, $stats['paid']);
		self::assertSame(950, $stats['paid_amount']);
		self::assertSame(1, $stats['confirmed']);
		self::assertSame(350, $stats['unpaid_amount']);
	}


	public function testCashPaymentSettlesAnUnderpaidBankPayment(): void
	{
		$id = $this->confirmed('alice@example.com', [101]);
		$this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, reservation_id, match_status)
			VALUES ('mock', 'part', CURDATE(), 100, ?, 'underpaid')",
		)->execute([$id]);

		$this->admin->markPaid($id);

		self::assertSame('matched', $this->db->query('SELECT match_status FROM bank_transactions')->fetchColumn());
	}


	public function testMarkPaidOnlyForConfirmed(): void
	{
		$id = $this->confirmed('alice@example.com', [101]);
		$this->admin->markPaid($id);
		self::assertSame('paid', $this->admin->get($id)['status'] ?? null);

		$this->expectException(ReservationError::class);
		$this->admin->markPaid($id);
	}


	public function testCancelFreesSeatsAndAllowsNewReservationWithTheSameEmail(): void
	{
		$id = $this->confirmed('alice@example.com', [101, 102]);

		$this->admin->cancel($id);

		self::assertSame('cancelled', $this->admin->get($id)['status'] ?? null);
		self::assertSame([], $this->admin->get($id)['seats'] ?? null);
		self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM seats WHERE state <> 'free'")->fetchColumn());

		$this->reservations()->start('owner-new', 'alice@example.com');
		self::assertSame('alice@example.com', $this->reservations()->state('owner-new')['reservation']['email']);
	}


	public function testSearchFiltersByStatusAndText(): void
	{
		$alice = $this->confirmed('alice@example.com', [101]);
		$this->confirmed('bob@example.com', [201]);
		$this->admin->markPaid($alice);

		self::assertSame([$alice], array_column($this->admin->search('paid'), 'id'));
		self::assertCount(1, $this->admin->search(null, 'BOB'));
		self::assertCount(1, $this->admin->search(null, (string) $alice));
		self::assertSame('1/1', $this->admin->search(null, 'alice')[0]['seat_labels']);
	}


	public function testSettingsSaveReportsOnlyChanges(): void
	{
		$settings = $this->settings();
		$changed = $settings->save(['price_seat' => '400', 'price_standing' => '250']);

		self::assertSame(['price_seat' => ['350', '400']], $changed);
		self::assertSame(400, $settings->int('price_seat'));
	}


	public function testGuestListCsvIsExcelFriendlyAndSafe(): void
	{
		$id = $this->confirmed('alice@example.com', [101], name: '=HYPERLINK("x")');
		$this->admin->saveNote($id, 'VIP; "stůl u okna"');

		$csv = GuestListCsv::build($this->admin->guestList());
		$lines = explode("\r\n", trim($csv));

		self::assertStringStartsWith("\u{FEFF}\"Číslo\";\"Jméno\"", $lines[0]);
		self::assertCount(2, $lines);
		self::assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $lines[1], 'Formula is neutralized and quotes escaped');
		self::assertStringContainsString('"VIP; ""stůl u okna"""', $lines[1]);
		self::assertStringContainsString('"nezaplaceno"', $lines[1]);
	}


	public function testProblemsFindUnsentEmailsAndOverdueReservations(): void
	{
		$sent = $this->confirmed('alice@example.com', [101]);
		$unsent = $this->confirmed('bob@example.com', [102]);
		$this->db->prepare('UPDATE reservations SET email_sent_at = NOW() WHERE id = ?')->execute([$sent]);
		$this->db->prepare('UPDATE reservations SET email_sent_at = NOW(), confirmed_at = NOW() - INTERVAL 8 DAY WHERE id = ?')->execute([$unsent]);
		$late = $this->confirmed('carol@example.com', [103]);

		self::assertSame(['email' => 1, 'overdue' => 1], $this->admin->problemCounts());
		self::assertSame([$late], array_column($this->admin->search(null, '', 'email'), 'id'));
		self::assertSame([$unsent], array_column($this->admin->search(null, '', 'overdue'), 'id'));
		self::assertCount(3, $this->admin->search(null, '', 'unknown'), 'Unknown problem is ignored');
	}


	public function testCustomersGroupReservationsByEmail(): void
	{
		$first = $this->confirmed('alice@example.com', [101], name: 'Alice');
		$this->confirmed('alice@example.com', [102, 103], name: 'Alice Nováková');
		$this->confirmed('bob@example.com', [201], name: 'Bob');
		$this->admin->markPaid($first);

		$customers = $this->admin->customers('');
		self::assertSame(['alice@example.com', 'bob@example.com'], array_column($customers, 'email'));
		self::assertSame(3, $customers[0]['tickets']);
		self::assertSame(1050, $customers[0]['total']);
		self::assertSame(350, $customers[0]['paid']);
		self::assertCount(2, $customers[0]['reservations']);
		self::assertSame(['bob@example.com'], array_column($this->admin->customers('BOB'), 'email'));
	}


	public function testTesterReservationsAreMarkedLeftOutOfGuestListAndDeletable(): void
	{
		$this->setSettings(['site_mode' => 'testing']);
		$test = $this->confirmed('tester@example.com', [101, 102]);
		$this->setSettings(['site_mode' => 'vip']);
		$vip = $this->confirmed('vip@example.com', [103]);
		$this->setSettings(['site_mode' => 'public']);
		$real = $this->confirmed('alice@example.com', [201]);
		$this->db->prepare(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, reservation_id, match_status)
			VALUES ('mock', 'x1', CURDATE(), 700, ?, 'matched')",
		)->execute([$test]);

		self::assertSame(1, $this->admin->testCount());
		self::assertSame([$vip, $real], array_column($this->admin->guestList(), 'id'));
		self::assertSame(['test' => 2, 'vip' => 1, 'public' => 1], $this->admin->ticketsByChannel());

		self::assertSame(1, $this->admin->deleteTestReservations());
		self::assertNull($this->admin->get($test));
		self::assertNotNull($this->admin->get($real));
		self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM seats WHERE state <> 'free'")->fetchColumn());
		self::assertSame(
			['reservation_id' => null, 'match_status' => 'ignored'],
			$this->db->query('SELECT reservation_id, match_status FROM bank_transactions')->fetch(),
		);
		$seats = $this->db->query(
			"SELECT s.seat_id FROM event_log e JOIN event_log_seats s ON s.event_id = e.id WHERE e.action = 'reservation.test_deleted' ORDER BY s.seat_id",
		)->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame([101, 102], array_map('intval', $seats), 'The log keeps the freed seats');
	}


	/** @param list<int> $seats */
	private function confirmed(string $email, array $seats, int $standing = 0, string $name = 'Test Host'): int
	{
		$service = $this->reservations();
		$owner = 'owner-' . $email;
		$service->start($owner, $email);
		foreach ($seats as $seat) {
			$service->hold($owner, $seat);
		}
		if ($standing) {
			$service->setStanding($owner, $standing);
		}
		return $service->confirm($owner, $name, '', true);
	}
}
