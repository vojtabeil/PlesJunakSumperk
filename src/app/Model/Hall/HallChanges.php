<?php

declare(strict_types=1);

namespace App\Model\Hall;


/** What replacing the hall plan changes in the database (HallSync::preview). */
final class HallChanges
{
	/**
	 * @param list<int> $newSeats seat numbers added (or back in the plan)
	 * @param list<int> $removedSeats seat numbers no longer in the plan
	 * @param array<int, array{from: ?int, to: int}> $movedSeats seat number => table numbers
	 * @param list<int> $newTables
	 * @param list<int> $removedTables
	 * @param array<int, array{from: ?string, to: ?string}> $renamedTables table number => names
	 * @param list<array{id: int, name: ?string, email: ?string, phone: ?string, status: string, channel: string,
	 *     paid: int, seats: list<string>}> $affected reservations that lose a seat and will be cancelled
	 * @param int $heldSeats removed seats somebody is selecting right now (only released)
	 */
	public function __construct(
		public readonly array $newSeats,
		public readonly array $removedSeats,
		public readonly array $movedSeats,
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
			'moved_seats' => count($this->movedSeats),
			'new_tables' => count($this->newTables),
			'removed_tables' => count($this->removedTables),
			'renamed_tables' => count($this->renamedTables),
			'cancelled' => count($this->affected),
		]);
	}
}
