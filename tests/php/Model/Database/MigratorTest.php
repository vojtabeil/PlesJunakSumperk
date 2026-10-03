<?php

declare(strict_types=1);

namespace App\Tests\Model\Database;

use App\Model\Database\Migrator;
use App\Tests\DatabaseTestCase;


final class MigratorTest extends DatabaseTestCase
{
	public function testSplitsStatementsAndDropsComments(): void
	{
		$sql = "-- comment\nCREATE TABLE a (\n  id INT -- not a statement end;\n);\n\nINSERT INTO a VALUES (1);\n";

		self::assertSame(
			["CREATE TABLE a (\n  id INT\n)", 'INSERT INTO a VALUES (1)'],
			Migrator::splitStatements($sql),
		);
	}


	public function testTestDatabaseIsFullyMigrated(): void
	{
		$applied = $this->db->query('SELECT name FROM migrations ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
		$files = array_map(fn(string $f): string => basename($f, '.sql'), glob(__DIR__ . '/../../../../migrations/*.sql') ?: []);
		sort($files);

		self::assertSame($files, $applied, 'Run init-db.cmd -Test after adding a migration');
	}
}
