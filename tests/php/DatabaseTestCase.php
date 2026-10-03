<?php

declare(strict_types=1);

namespace App\Tests;

use App\Bootstrap;
use App\Model\Log\Actor;
use App\Model\Mail\MailSender;
use App\Model\Mail\ReservationMailer;
use App\Model\Payment\QrPayment;
use App\Model\Reservation\ReservationAdmin;
use Nette\Application\LinkGenerator;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tracy\ILogger;
use App\Model\Log\EventLog;
use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\Settings;
use Nette\DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;


/**
 * Base for tests against the test database (ples_test, created by `init-db.cmd -Test`).
 * Every test starts with a small fixture: 2 tables x 4 seats and default settings.
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
		foreach (['seats', 'hall_tables', 'bank_transactions', 'mock_bank_transactions', 'reservations', 'settings', 'event_log_seats', 'event_log', 'admin_users'] as $table) {
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
		$this->db->exec("INSERT INTO hall_tables (id, label, x, y, width, height) VALUES (1, '1', 0, 0, 160, 40), (2, '2', 0, 100, 160, 40)");
		$this->db->exec(
			"INSERT INTO seats (id, label, table_id, x, y) VALUES
			(101, '1/1', 1, 10, 0), (102, '1/2', 1, 50, 0), (103, '1/3', 1, 90, 0), (104, '1/4', 1, 130, 0),
			(201, '2/1', 2, 10, 100), (202, '2/2', 2, 50, 100), (203, '2/3', 2, 90, 100), (204, '2/4', 2, 130, 100)",
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
		return new ReservationService($this->db, $this->settings(), new VariableSymbol($this->settings()), $this->eventLog());
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
