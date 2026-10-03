<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use PDO;


/** Seats as the organizer sees them: one status combining the seat and its reservation. */
final class SeatOverview
{
	/** Status => Czech label, in the order of the chart and the legend. */
	public const Statuses = [
		'free' => 'Volné',
		'held' => 'Právě se vybírá',
		'unpaid' => 'Rezervované, nezaplacené',
		'partial' => 'Částečně zaplacené',
		'paid' => 'Zaplacené',
	];


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/**
	 * @return list<array{id: int, label: string, table: string, status: string, reservation_id: ?int,
	 *     name: ?string, channel: ?string, changed_at: string}>
	 */
	public function seats(?string $status = null, string $query = ''): array
	{
		$rows = $this->db->query(
			"SELECT s.id, s.label, t.label AS table_label, s.state, s.updated_at,
				r.id AS reservation_id, r.name, r.status, r.channel, r.updated_at AS reservation_updated_at
			FROM seats s
			LEFT JOIN hall_tables t ON t.id = s.table_id
			LEFT JOIN reservations r ON r.id = s.reservation_id
			ORDER BY s.table_id, s.id",
		)->fetchAll();

		$query = mb_strtolower(trim($query));
		$seatLabel = preg_match('~^\d+\s*/\s*\d+$~', $query) ? preg_replace('~\s+~', '', $query) : null;
		$result = [];
		foreach ($rows as $row) {
			$seatStatus = self::status((string) $row['state'], $row['status']);
			$isFinished = $seatStatus !== 'free' && $seatStatus !== 'held';
			$item = [
				'id' => (int) $row['id'],
				'label' => (string) $row['label'],
				'table' => (string) ($row['table_label'] ?? ''),
				'status' => $seatStatus,
				// Drafts (seat being selected) are not shown as reservations.
				'reservation_id' => $isFinished ? (int) $row['reservation_id'] : null,
				'name' => $isFinished ? $row['name'] : null,
				'channel' => $isFinished ? (string) $row['channel'] : null,
				'changed_at' => max((string) $row['updated_at'], $isFinished ? (string) $row['reservation_updated_at'] : ''),
			];
			if ($status !== null && $item['status'] !== $status) {
				continue;
			}
			if ($seatLabel !== null ? $item['label'] !== $seatLabel
				: $query !== '' && !str_contains(mb_strtolower($item['label'] . ' ' . ($item['name'] ?? '')), $query)
			) {
				continue;
			}
			$result[] = $item;
		}
		return $result;
	}


	/** @return array<string, int> status => number of seats, for all statuses */
	public function counts(): array
	{
		$counts = array_fill_keys(array_keys(self::Statuses), 0);
		foreach ($this->seats() as $seat) {
			$counts[$seat['status']]++;
		}
		return $counts;
	}


	public static function status(string $seatState, ?string $reservationStatus): string
	{
		return match (true) {
			$seatState === 'free' => 'free',
			$seatState === 'book' => 'held',
			$reservationStatus === 'paid' => 'paid',
			$reservationStatus === 'partially_paid' => 'partial',
			default => 'unpaid',
		};
	}
}
