<?php

declare(strict_types=1);

namespace App\Model\Hall;


/** What replacing the hall plan changes in the database (HallSync::preview). */
final class HallChanges
{
	/**
	 * @param list<string> $newSeats seat labels added (or back in the plan)
	 * @param list<string> $removedSeats seat labels no longer in the plan
	 * @param list<int> $removedSeatIds their ids in the database
	 * @param list<string> $newTables table labels
	 * @param list<string> $removedTables
	 * @param array<string, array{from: ?string, to: ?string}> $renamedTables table label => names
	 * @param list<array{id: int, name: ?string, email: ?string, phone: ?string, status: string, channel: string,
	 *     paid: int, seats: list<string>}> $affected reservations that lose a seat and will be cancelled
	 * @param int $heldSeats removed seats somebody is selecting right now (only released)
	 */
	public function __construct(
		public readonly array $newSeats,
		public readonly array $removedSeats,
		public readonly array $removedSeatIds,
		public readonly array $newTables,
		public readonly array $removedTables,
		public readonly array $renamedTables,
		public readonly array $affected,
		public readonly int $heldSeats,
	) {
	}


	/** @return list<int> */
	public function affectedIds(): array
	{
		return array_column($this->affected, 'id');
	}


	/** CZK paid by the affected reservations (to be refunded by hand). */
	public function affectedPaid(): int
	{
		return array_sum(array_column($this->affected, 'paid'));
	}


	/** @return array<string, int> counts for the event log */
	public function counts(): array
	{
		return array_filter([
			'new_seats' => count($this->newSeats),
			'removed_seats' => count($this->removedSeats),
			'new_tables' => count($this->newTables),
			'removed_tables' => count($this->removedTables),
			'renamed_tables' => count($this->renamedTables),
			'cancelled' => count($this->affected),
		]);
	}
}
