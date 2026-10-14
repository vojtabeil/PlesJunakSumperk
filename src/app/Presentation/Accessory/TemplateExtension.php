<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use App\Model\Log\EventFormatter;
use App\Model\Log\EventTypes;
use App\Model\Reservation\SeatOverview;
use Latte\Extension;


/** Filters and functions shared by page and e-mail templates. */
final class TemplateExtension extends Extension
{
	public function __construct(
		private readonly string $wwwDir,
	) {
	}


	public function getFilters(): array
	{
		return [
			'price' => self::formatPrice(...),
			'seatLabel' => self::formatSeatLabel(...),
			'statusLabel' => self::formatStatus(...),
			'matchLabel' => self::formatMatchStatus(...),
			'seatStatus' => static fn(string $status): string => SeatOverview::Statuses[$status] ?? $status,
			'eventLabel' => EventTypes::label(...),
			'eventDetails' => EventFormatter::details(...),
			'actorLabel' => static fn(string $type): string => EventTypes::Actors[$type] ?? $type,
		];
	}


	public function getFunctions(): array
	{
		return [
			'asset' => $this->asset(...),
		];
	}


	/** 1234 -> "1 234 Kč" (with non-breaking spaces). */
	public static function formatPrice(int $amount): string
	{
		return number_format($amount, 0, ',', "\u{A0}") . "\u{A0}Kč";
	}


	/** "3/5" -> "stůl 3, místo 5" */
	public static function formatSeatLabel(string $label): string
	{
		[$table, $seat] = array_pad(explode('/', $label, 2), 2, '');
		return "stůl $table, místo $seat";
	}


	/** Czech name of a reservation status. */
	public static function formatStatus(string $status): string
	{
		return match ($status) {
			'draft' => 'rozpracovaná',
			'confirmed' => 'nezaplacená',
			'partially_paid' => 'částečně zaplacená',
			'paid' => 'zaplacená',
			'cancelled' => 'zrušená',
			default => $status,
		};
	}


	/** Czech name of a bank transaction matching status. */
	public static function formatMatchStatus(string $status): string
	{
		return match ($status) {
			'matched' => 'spárováno',
			'underpaid' => 'nedoplatek',
			'overpaid' => 'přeplatek',
			'unknown_vs' => 'neznámý VS',
			'no_vs' => 'bez VS',
			'foreign' => 'nesouvisí s plesem',
			'outgoing' => 'odchozí',
			'ignored' => 'ignorováno',
			default => $status,
		};
	}


	/** Path of a file in www/ with a cache-busting version, e.g. asset('build/front.js'). */
	private function asset(string $path): string
	{
		$file = $this->wwwDir . '/' . $path;
		return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
	}
}
