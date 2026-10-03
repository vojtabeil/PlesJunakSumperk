<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;


/**
 * Tester mode: until the site is public, only visitors who opened the semi-secret tester link
 * (settings public_access = testers, tester_token) see it. Reservations made meanwhile are test ones.
 */
final class TesterAccess
{
	public const Public = 'public';
	public const Testers = 'testers';


	public function __construct(
		private readonly Settings $settings,
		private readonly EventLog $eventLog,
	) {
	}


	public function isPublic(): bool
	{
		return $this->settings->isPublic();
	}


	/** Secret part of the tester link; created on first use. */
	public function token(): string
	{
		$token = $this->settings->get('tester_token');
		if ($token === '') {
			$token = self::newToken();
			$this->settings->remember('tester_token', $token);
		}
		return $token;
	}


	public function isValid(?string $token): bool
	{
		$current = $this->settings->get('tester_token');
		return $current !== '' && $token !== null && hash_equals($current, $token);
	}


	/** New link; the old one and all tester cookies stop working. */
	public function regenerate(): string
	{
		$token = self::newToken();
		$this->settings->remember('tester_token', $token);
		$this->eventLog->record('tester.link_regenerated');
		return $token;
	}


	private static function newToken(): string
	{
		return bin2hex(random_bytes(16));
	}
}
