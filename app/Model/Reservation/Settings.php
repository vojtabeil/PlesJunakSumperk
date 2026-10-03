<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use PDO;


/** Key/value settings from the `settings` table (event info, prices, limits, sale switch). */
final class Settings
{
	/** Values that are secrets: their changes are logged without the value. */
	private const Secret = ['tester_token'];

	/** @var array<string, string>|null */
	private ?array $values = null;


	public function __construct(
		private readonly PDO $db,
		private readonly EventLog $eventLog,
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
		foreach ($values as $name => $value) {
			$old = $current[$name] ?? '';
			if ($old !== $value) {
				$this->remember($name, $value);
				$changed[$name] = [$old, $value];
			}
		}
		if ($changed) {
			$visible = array_diff_key($changed, array_flip(self::Secret));
			$this->eventLog->record('settings.changed', details: ['changed' => array_keys($changed), 'values' => $visible]);
		}
		return $changed;
	}


	/** Internal value (e.g. time of the last bank import): stored without logging. */
	public function remember(string $name, string $value): void
	{
		$this->db->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
		$this->values = null;
	}


	/** False while only testers with the tester link may see the site (see TesterAccess). */
	public function isPublic(): bool
	{
		return $this->get('public_access', 'testers') === 'public';
	}


	public function isSaleOpen(): bool
	{
		return $this->get('sale_open') === '1';
	}
}
