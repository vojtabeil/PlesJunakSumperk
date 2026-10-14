<?php

declare(strict_types=1);

namespace App\Tests\Model\Log;

use App\Model\Log\EventFilter;
use App\Model\Log\EventFormatter;
use App\Model\Log\EventLogRepository;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationAdmin;
use App\Tests\DatabaseTestCase;
use DateTimeImmutable;


final class EventLogTest extends DatabaseTestCase
{
	public function testReservationLifecycleIsLoggedWithActorsAndSeats(): void
	{
		$this->actor()->asCustomer();
		$service = $this->reservations();
		$service->setEmail('owner', 'jana@example.com');
		$service->hold('owner', 101);
		$service->hold('owner', 102);
		$id = $service->confirm('owner', 'Jana Nováková', '', true);

		// Seat selection itself is not logged (decision: only reservations).
		self::assertSame(['reservation.confirmed'], $this->loggedActions());

		$this->actor()->asAdmin($this->adminId());
		$admin = new ReservationAdmin($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog());
		$admin->saveNote($id, 'VIP');
		$admin->cancel($id);

		$rows = $this->db->query('SELECT action, actor_type, admin_user_id, reservation_id FROM event_log ORDER BY id')->fetchAll();
		self::assertSame(['customer', 'admin', 'admin'], array_column($rows, 'actor_type'));
		self::assertSame([$id, $id, $id], array_map('intval', array_column($rows, 'reservation_id')));

		$repository = new EventLogRepository($this->db);
		$bySeat = $repository->search(new EventFilter(seatId: 102))['events'];
		self::assertSame(
			['reservation.cancelled', 'reservation.note', 'reservation.confirmed'],
			array_column($bySeat, 'action'),
			'The cancelled reservation still shows up for the seat it held',
		);
		self::assertSame('1/1, 1/2', $bySeat[0]['seat_labels']);
		self::assertSame([], $repository->search(new EventFilter(seatId: 201))['events']);
	}


	public function testPaymentEventsAreAutomaticForCustomersButKeepTheAdmin(): void
	{
		$this->actor()->asCustomer();
		$service = $this->reservations();
		$service->setEmail('owner', 'jana@example.com');
		$service->hold('owner', 101);
		$id = $service->confirm('owner', 'Jana Nováková', '', true);
		$this->db->exec(
			"INSERT INTO bank_transactions (source, external_id, booked_on, amount, variable_symbol, counter_name, match_status)
			VALUES ('mock', 'm1', CURDATE(), 350, '" . VariableSymbol::format('2026', $id) . "', 'Jana', 'unknown_vs')",
		);
		$txId = (int) $this->db->lastInsertId();

		$this->actor()->asAdmin($this->adminId());
		(new PaymentMatcher($this->db, new VariableSymbol($this->settings()), $this->eventLog()))->apply($txId);

		$event = $this->db->query("SELECT * FROM event_log WHERE action = 'payment.matched'")->fetch();
		self::assertSame('admin', $event['actor_type'], 'The admin who pressed "Načíst platby" caused it');
		self::assertSame($id, (int) $event['reservation_id']);
		self::assertStringContainsString('zaplaceno 350', EventFormatter::details($event));
	}


	public function testFiltersByCategoryActorAndDate(): void
	{
		$this->actor()->asAdmin($this->adminId());
		$this->settings()->save(['price_seat' => '400']);
		$this->actor()->asCustomer();
		$service = $this->reservations();
		$service->setEmail('owner', 'jana@example.com');
		$service->hold('owner', 101);
		$service->confirm('owner', 'Jana Nováková', '', true);
		$this->db->exec("UPDATE event_log SET created_at = '2026-01-10 10:00:00' WHERE action = 'settings.changed'");

		$repository = new EventLogRepository($this->db);
		self::assertSame(['settings.changed'], array_column($repository->search(new EventFilter(category: 'admin'))['events'], 'action'));
		self::assertSame(['reservation.confirmed'], array_column($repository->search(new EventFilter(actor: 'customer'))['events'], 'action'));
		$january = new EventFilter(from: new DateTimeImmutable('2026-01-10'), to: new DateTimeImmutable('2026-01-10'));
		self::assertSame(1, $repository->search($january)['total']);
	}


	public function testSecretsAreNotLogged(): void
	{
		$this->actor()->asAdmin($this->adminId());
		$this->settings()->save(['tester_token' => 'super-secret-token', 'price_seat' => '400']);

		$details = (string) $this->db->query("SELECT details FROM event_log WHERE action = 'settings.changed'")->fetchColumn();
		self::assertStringNotContainsString('super-secret-token', $details);
		self::assertStringContainsString('tester_token', $details, 'The change itself is visible');
		self::assertStringContainsString('400', $details);
	}


	private function adminId(): int
	{
		$this->db->exec("INSERT INTO admin_users (login, name, password_hash) VALUES ('jana', 'Jana', 'x')");
		return (int) $this->db->lastInsertId();
	}
}
