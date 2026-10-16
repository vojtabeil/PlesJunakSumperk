<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Bootstrap;
use App\Model\Crew\CrewMembers;
use App\Model\Log\Actor;
use App\Model\Log\EventLog;
use App\Model\Mail\MailSender;
use App\Model\Mail\ReservationMailer;
use App\Model\Payment\QrPayment;
use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use App\Model\Ticket\TicketCheck;
use App\Model\Ticket\TicketLinks;
use App\Model\Ticket\Tickets;
use Nette\Application\LinkGenerator;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;
use Tracy\ILogger;


/**
 * Base for tests against the test database (ples_test, created by `dev\init-db.cmd -Test`).
 * Every test starts with a small fixture: tables A1 and A2 with seats A1/1-A1/4 (ids 101-104) and A2/1-A2/4
 * (ids 201-204), and default settings.
 */
abstract class DatabaseTestCase extends TestCase
{
	private static ?Container $container = null;

	protected PDO $db;

	private ?Actor $actor = null;


	protected function setUp(): void
	{
		$this->db = $this->service(PDO::class);
		$this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach (['hall_plan', 'refunds', 'tickets', 'crew_members', 'seat_bookings', 'seats', 'hall_tables', 'bank_transactions', 'mock_bank_transactions', 'reservations', 'settings', 'event_log_seats', 'event_log', 'admin_users'] as $table) {
			$this->db->exec("TRUNCATE TABLE $table");
		}
		$this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

		$this->setSettings([
			'site_mode' => 'public',
			'event_name' => 'Testovací ples',
			'max_ticket' => '10',
			'hold_seconds' => '120',
			'price_seat' => '350',
			'price_standing' => '250',
			'standing_capacity' => '5',
			'bank_account' => '2501895120/2010',
			'payment_vs_prefix' => '2026',
			'payment_days' => '2',
			'contact_email' => 'dotazy@example.com',
		]);
		$this->db->exec("INSERT INTO hall_tables (id, label, svg_id, sort_order) VALUES (1, 'A1', 'table-A1', 1), (2, 'A2', 'table-A2', 2)");
		$this->db->exec(
			"INSERT INTO seats (id, label, table_id, svg_id, sort_order) VALUES
			(101, 'A1/1', 1, 'seat-A1-1', 11), (102, 'A1/2', 1, 'seat-A1-2', 12), (103, 'A1/3', 1, 'seat-A1-3', 13), (104, 'A1/4', 1, 'seat-A1-4', 14),
			(201, 'A2/1', 2, 'seat-A2-1', 21), (202, 'A2/2', 2, 'seat-A2-2', 22), (203, 'A2/3', 2, 'seat-A2-3', 23), (204, 'A2/4', 2, 'seat-A2-4', 24)",
		);
	}


	/** @param array<string, string> $values */
	protected function setSettings(array $values): void
	{
		$stmt = $this->db->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
		foreach ($values as $name => $value) {
			$stmt->execute([$name, $value]);
		}
	}


	/** Fresh services per test, so cached settings never leak between tests. */
	protected function reservations(): ReservationService
	{
		return new ReservationService($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog(), $this->tickets());
	}


	protected function tickets(): Tickets
	{
		return new Tickets($this->db);
	}


	protected function crew(): CrewMembers
	{
		return new CrewMembers($this->db, $this->tickets(), $this->eventLog());
	}


	protected function ticketCheck(): TicketCheck
	{
		return new TicketCheck($this->db, $this->tickets(), $this->settings(), new VariableSymbol($this->settings()), $this->eventLog(), $this->actor());
	}


	protected function reservationAdmin(): ReservationAdmin
	{
		return new ReservationAdmin($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog());
	}


	protected function mailer(MailSender $sender, ILogger $logger): ReservationMailer
	{
		return new ReservationMailer(
			$this->reservations(),
			$this->reservationAdmin(),
			$this->settings(),
			$sender,
			$this->service(LatteFactory::class),
			new QrPayment($this->settings()),
			$this->service(LinkGenerator::class),
			$logger,
			$this->eventLog(),
			$this->tickets(),
			new TicketLinks($this->service(LinkGenerator::class)),
		);
	}


	protected function settings(): Settings
	{
		return new Settings($this->db, $this->eventLog());
	}


	/** Shared per test, so a test can switch the actor (customer, admin, cron). */
	protected function actor(): Actor
	{
		return $this->actor ??= new Actor;
	}


	protected function eventLog(): EventLog
	{
		return new EventLog($this->db, $this->actor());
	}


	/** @return list<string> logged actions, oldest first */
	protected function loggedActions(): array
	{
		return $this->db->query('SELECT action FROM event_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
	}


	/**
	 * @template T of object
	 * @param class-string<T> $type
	 * @return T
	 */
	protected function service(string $type): object
	{
		return self::container()->getByType($type);
	}


	private static function container(): Container
	{
		return self::$container ??= Bootstrap::bootForTests()->createContainer();
	}
}
