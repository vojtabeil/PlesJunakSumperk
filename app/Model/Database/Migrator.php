<?php

declare(strict_types=1);

namespace App\Model\Database;

use PDO;
use RuntimeException;


/**
 * Applies migrations/NNN_description.sql files in order and records them in the `migrations` table.
 * Applied files must never be edited; add a new file instead.
 */
final class Migrator
{
	public function __construct(
		private readonly string $directory,
		private readonly PDO $db,
	) {
	}


	/** @return list<string> names of migrations applied by this call */
	public function migrate(): array
	{
		$this->db->exec(
			'CREATE TABLE IF NOT EXISTS migrations (
				name       VARCHAR(255) NOT NULL PRIMARY KEY,
				applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB',
		);
		$applied = $this->db->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

		$done = [];
		foreach ($this->pending($applied) as $name => $file) {
			// DDL commits implicitly in MySQL, so a migration cannot be wrapped in a transaction.
			foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
				$this->db->exec($statement);
			}
			$this->db->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
			$done[] = $name;
		}
		return $done;
	}


	/**
	 * @param list<string> $applied
	 * @return array<string, string> name => path
	 */
	private function pending(array $applied): array
	{
		$files = glob($this->directory . '/*.sql') ?: [];
		sort($files, SORT_STRING);
		$pending = [];
		foreach ($files as $file) {
			$name = basename($file, '.sql');
			if (!preg_match('/^\d{3}_[a-z0-9_]+$/', $name)) {
				throw new RuntimeException("Invalid migration file name: $name");
			}
			if (!in_array($name, $applied, true)) {
				$pending[$name] = $file;
			}
		}
		return $pending;
	}


	/**
	 * Splits SQL on semicolons at the end of a line; keep each statement's terminating `;` last on its line.
	 * @return list<string>
	 */
	public static function splitStatements(string $sql): array
	{
		// Whole-line comments and trailing " -- ..." comments (they may end with a semicolon).
		$sql = (string) preg_replace(['/^\s*--.*$/m', '/\s+--\s.*$/m'], '', $sql);
		$parts = preg_split('/;\s*$/m', $sql) ?: [];
		return array_values(array_filter(array_map('trim', $parts), fn(string $s): bool => $s !== ''));
	}
}
