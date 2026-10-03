<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Reservation\Settings;


/**
 * Variable symbol of a reservation = prefix + reservation id padded to 4 digits, e.g. 2026 + 0003.
 * The prefix keeps ball payments apart from other payments on the scout group's account
 * (membership fees etc. have other variable symbols).
 */
final class VariableSymbol
{
	public const MaxPrefixLength = 6;
	private const IdDigits = 4;


	public function __construct(
		private readonly Settings $settings,
	) {
	}


	public function forReservation(int $reservationId): string
	{
		return self::format($this->prefix(), $reservationId);
	}


	/** Reservation id from a received variable symbol, or null when the payment is not for the ball. */
	public function reservationId(?string $variableSymbol): ?int
	{
		return self::parse($this->prefix(), $variableSymbol);
	}


	public static function format(string $prefix, int $reservationId): string
	{
		return $prefix . str_pad((string) $reservationId, self::IdDigits, '0', STR_PAD_LEFT);
	}


	public static function parse(string $prefix, ?string $variableSymbol): ?int
	{
		$vs = ltrim(trim((string) $variableSymbol), '0');
		if ($vs === '' || !ctype_digit($vs) || !str_starts_with($vs, $prefix)) {
			return null;
		}
		$id = substr($vs, strlen($prefix));
		return strlen($id) >= self::IdDigits && (int) $id > 0 ? (int) $id : null;
	}


	private function prefix(): string
	{
		return ltrim($this->settings->get('payment_vs_prefix'), '0');
	}
}
