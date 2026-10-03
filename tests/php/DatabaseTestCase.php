<?php

declare(strict_types=1);

namespace App\Tests;

use App\Bootstrap;
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


	protected function setUp(): void
	{
		$this->db = $this->service(PDO::class);
		$this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach (['seats', 'hall_tables', 'reservations', 'settings', 'audit_log', 'admin_users'] as $table) {
			$this->db->exec("TRUNCATE TABLE $table");
		}
		$this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

		$this->setSettings([
			'sale_open' => '1',
			'event_name' => 'Testovací ples',
			'max_ticket' => '10',
			'hold_seconds' => '120',
			'price_seat' => '350',
			'price_standing' => '250',
			'standing_capacity' => '5',
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
		return new ReservationService($this->db, $this->settings());
	}


	protected function settings(): Settings
	{
		return new Settings($this->db);
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
