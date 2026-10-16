<?php

declare(strict_types=1);

namespace App\Model\Settings;

use App\Model\Log\EventLog;
use App\Model\Site\SiteMode;
use PDO;


/** Key/value settings from the `settings` table (event info, prices, limits, sale switch). */
final class Settings
{
	/** Values not copied into the log: secrets and long HTML pages (only their names are logged). */
	private const NotLogged = ['tester_token', 'vip_token', 'page_testing', 'page_vip', 'page_closed', 'page_after'];

	/** @var array<string, string>|null */
	private ?array $values = null;


	public function __construct(
		private readonly PDO $db,
		private readonly EventLog $eventLog,
	) {
	}


	/**
	 * Stored values plus event_date: the date of the ball as text (EventDate::text(), '' while not set), used by
	 * the templates of the site, tickets and e-mails.
	 * @return array<string, string>
	 */
	public function all(): array
	{
		if ($this->values === null) {
			$values = $this->db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
			$date = EventDate::fromValues($values['event_day'] ?? '', $values['event_time'] ?? '');
			$this->values = ['event_date' => $date?->text() ?? ''] + $values;
		}
		return $this->values;
	}


	/** Date of the ball; null while it is not set. */
	public function eventDate(): ?EventDate
	{
		return EventDate::fromValues($this->get('event_day'), $this->get('event_time'));
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
			$visible = array_diff_key($changed, array_flip(self::NotLogged));
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


	/** Stage of the site (see SiteAccess); an unknown value counts as testing, the safest one. */
	public function mode(): SiteMode
	{
		return SiteMode::tryFrom($this->get('site_mode')) ?? SiteMode::Testing;
	}
}
