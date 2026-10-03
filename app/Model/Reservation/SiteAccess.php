<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use App\Model\Log\EventLog;
use PDO;


/**
 * Stage of the site and the two secret links: the tester link (token in the database, can be
 * regenerated in the administration) and the VIP link (token in the configuration file).
 */
final class SiteAccess
{
	/** A shorter VIP token is treated as not configured, so a weak one never opens the sale. */
	public const MinVipTokenLength = 16;


	public function __construct(
		private readonly string $vipToken,
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


	/** Secret part of the tester link; created on first use. */
	public function testerToken(): string
	{
		$token = $this->settings->get('tester_token');
		if ($token === '') {
			$token = self::newToken();
			$this->settings->remember('tester_token', $token);
		}
		return $token;
	}


	public function isTester(?string $token): bool
	{
		return self::matches($this->settings->get('tester_token'), $token);
	}


	/** New tester link; the old one and all tester cookies stop working. */
	public function regenerateTesterToken(): string
	{
		$token = self::newToken();
		$this->settings->remember('tester_token', $token);
		$this->eventLog->record('tester.link_regenerated');
		return $token;
	}


	/** Secret part of the VIP link from the configuration (site.vipToken); null = not configured. */
	public function vipToken(): ?string
	{
		return strlen($this->vipToken) >= self::MinVipTokenLength ? $this->vipToken : null;
	}


	public function isVip(?string $token): bool
	{
		return self::matches($this->vipToken() ?? '', $token);
	}


	private static function matches(string $expected, ?string $token): bool
	{
		return $expected !== '' && $token !== null && hash_equals($expected, $token);
	}


	private static function newToken(): string
	{
		return bin2hex(random_bytes(16));
	}
}
