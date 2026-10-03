<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use PDO;


/** Key/value settings from the `settings` table (event info, prices, limits, sale switch). */
final class Settings
{
	/** @var array<string, string>|null */
	private ?array $values = null;


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/** @return array<string, string> */
	public function all(): array
	{
		return $this->values ??= $this->db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
	}


	public function get(string $name, string $default = ''): string
	{
		return $this->all()[$name] ?? $default;
	}


	public function int(string $name, int $default = 0): int
	{
		return (int) $this->get($name, (string) $default);
	}


	/**
	 * Stores the given values and returns the ones that actually changed (name => [old, new]).
	 * @param array<string, string> $values
	 * @return array<string, array{string, string}>
	 */
	public function save(array $values): array
	{
		$current = $this->all();
		$changed = [];
		$stmt = $this->db->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
		foreach ($values as $name => $value) {
			$old = $current[$name] ?? '';
			if ($old !== $value) {
				$stmt->execute([$name, $value]);
				$changed[$name] = [$old, $value];
			}
		}
		$this->values = null;
		return $changed;
	}


	public function isSaleOpen(): bool
	{
		return $this->get('sale_open') === '1';
	}
}
