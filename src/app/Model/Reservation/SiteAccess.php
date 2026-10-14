<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use PDO;


/**
 * Stage of the site and the two secret links: the tester link (testing stage) and the VIP link
 * (VIP sale). Their tokens are in the settings and organizers can replace them in the administration.
 */
final class SiteAccess
{
	public const Tester = 'tester';
	public const Vip = 'vip';

	/** Link => [settings key, logged action when replaced]. */
	private const Links = [
		self::Tester => ['tester_token', 'tester.link_regenerated'],
		self::Vip => ['vip_token', 'vip.link_regenerated'],
	];


	public function __construct(
		private readonly Settings $settings,
		private readonly EventLog $eventLog,
		private readonly PDO $db,
	) {
	}


	public function mode(): SiteMode
	{
		return $this->settings->mode();
	}


	/** Switches the stage; leaving the sale also frees the seats being selected right now. */
	public function setMode(SiteMode $mode): void
	{
		$old = $this->mode();
		if ($old === $mode) {
			return;
		}
		$this->settings->remember('site_mode', $mode->value);
		if (!$mode->isSelling()) {
			$this->db->exec("UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL WHERE state = 'book'");
		}
		$this->eventLog->record('site.mode_changed', details: ['from' => $old->label(), 'to' => $mode->label()]);
	}


	/**
	 * Secret part of the link (self::Tester or self::Vip); created on first use.
	 */
	public function token(string $link): string
	{
		$key = self::Links[$link][0];
		$token = $this->settings->get($key);
		if ($token === '') {
			$token = self::newToken();
			$this->settings->remember($key, $token);
		}
		return $token;
	}


	public function isValid(string $link, ?string $token): bool
	{
		$expected = $this->settings->get(self::Links[$link][0]);
		return $expected !== '' && $token !== null && hash_equals($expected, $token);
	}


	/** New link; the old one and all cookies set by it stop working. */
	public function regenerate(string $link): string
	{
		[$key, $action] = self::Links[$link];
		$token = self::newToken();
		$this->settings->remember($key, $token);
		$this->eventLog->record($action);
		return $token;
	}


	private static function newToken(): string
	{
		return bin2hex(random_bytes(16));
	}
}
