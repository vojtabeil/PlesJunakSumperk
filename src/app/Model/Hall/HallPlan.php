<?php

declare(strict_types=1);

namespace App\Model\Hall;


/** A parsed hall plan: the sanitized SVG, its tables and seats, and what is wrong with it. */
final class HallPlan
{
	/**
	 * @param array<int, array{svg_id: string, name: ?string}> $tables table number => table
	 * @param array<int, array{svg_id: string, table: int}> $seats seat number => seat
	 * @param list<string> $errors Czech messages; a plan with errors cannot be used
	 */
	public function __construct(
		public readonly string $svg,
		public readonly array $tables,
		public readonly array $seats,
		public readonly array $errors,
	) {
	}


	public function isValid(): bool
	{
		return $this->errors === [];
	}
}
