<?php

declare(strict_types=1);

namespace App\Model\Log;

use Nette\Utils\Json;
use Nette\Utils\JsonException;


/** Czech one-line description of a logged event's details (shown next to its label). */
final class EventFormatter
{
	/** @param array<string, mixed> $event row of event_log */
	public static function details(array $event): string
	{
		try {
			$d = $event['details'] ? Json::decode((string) $event['details'], forceArrays: true) : [];
		} catch (JsonException) {
			return '';
		}
		if (!is_array($d)) {
			return '';
		}
		$money = static fn($v): string => number_format((float) $v, 0, ',', "\u{A0}") . "\u{A0}Kč";

		$parts = match ((string) $event['action']) {
			'reservation.confirmed' => [
				isset($d['name']) ? (string) $d['name'] : null,
				isset($d['email']) ? (string) $d['email'] : null,
				isset($d['tickets']) ? "lístků {$d['tickets']}" : null,
				isset($d['total']) ? $money($d['total']) : null,
				match ($d['channel'] ?? null) {
					'test' => 'testovací',
					'vip' => 'VIP',
					default => null,
				},
			],
			'reservation.paid_manually' => [isset($d['amount']) ? $money($d['amount']) . ' hotově' : null],
			'payment.matched', 'payment.partial', 'payment.overpaid', 'payment.unmatched', 'payment.assigned', 'payment.ignored' => [
				isset($d['amount']) ? $money($d['amount']) : null,
				isset($d['vs']) && $d['vs'] !== '' ? "VS {$d['vs']}" : 'bez VS',
				isset($d['payer']) ? (string) $d['payer'] : null,
				isset($d['paid'], $d['total']) ? 'zaplaceno ' . $money($d['paid']) . ' z ' . $money($d['total']) : null,
			],
			'payments.imported' => [isset($d['summary']) ? (string) $d['summary'] : null],
			'payments.rewound' => [isset($d['since']) ? "od {$d['since']}" : null],
			'email.confirmation_sent', 'email.payment_sent' => [isset($d['to']) ? "na {$d['to']}" : null],
			'email.confirmation_failed', 'email.payment_failed' => [
				isset($d['to']) ? "na {$d['to']}" : null,
				isset($d['error']) ? "chyba: {$d['error']}" : null,
			],
			'site.mode_changed' => [isset($d['from'], $d['to']) ? "{$d['from']} → {$d['to']}" : null],
			'settings.changed' => [isset($d['changed']) && is_array($d['changed']) ? 'změněno: ' . implode(', ', $d['changed']) : null],
			'admin.created', 'admin.updated', 'admin.password', 'admin.unlocked', 'admin.deleted', 'admin.setup', 'admin.login_failed', 'admin.locked'
				=> [isset($d['login']) ? "účet {$d['login']}" : null],
			default => [],
		};
		return implode(' · ', array_filter($parts, static fn($p): bool => $p !== null && $p !== ''));
	}
}
