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
