<?php

declare(strict_types=1);

namespace App\Model\Payment;

use PDO;


/** Reading stored bank transactions for the administration. */
final class PaymentRepository
{
	/** Incoming payments the organizer has to resolve. */
	public const ProblemStatuses = ['unknown_vs', 'no_vs', 'underpaid', 'overpaid'];


	public function __construct(
		private readonly PDO $db,
	) {
	}


	/** The page opens with the payments to resolve. */
	public const DefaultFilter = 'problems';

	/** Filters of the Payments page: key => [Czech label, SQL condition]. */
	public const Filters = [
		'problems' => ['K vyřízení', "match_status IN ('unknown_vs', 'no_vs', 'underpaid', 'overpaid')"],
		'matched' => ['Spárované s rezervací', "reservation_id IS NOT NULL AND match_status = 'matched'"],
		'foreign' => ['Nesouvisí s plesem', "match_status IN ('foreign', 'ignored')"],
		'all' => ['Vše', '1 = 1'],
	];


	/** @return list<array<string, mixed>> newest first */
	public function search(string $filter = self::DefaultFilter): array
	{
		$condition = self::Filters[$filter][1] ?? self::Filters[self::DefaultFilter][1];
		return $this->db->query(
			"SELECT * FROM bank_transactions WHERE $condition ORDER BY booked_on DESC, id DESC LIMIT 500",
		)->fetchAll();
	}


	/** @return array<string, array{count: int, amount: float}> filter => number and sum of payments */
	public function summary(): array
	{
		$result = [];
		foreach (self::Filters as $key => [, $condition]) {
			$row = $this->db->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS a FROM bank_transactions WHERE $condition")->fetch();
			$result[$key] = ['count' => (int) $row['c'], 'amount' => (float) $row['a']];
		}
		return $result;
	}


	/** @return list<array<string, mixed>> */
	public function forReservation(int $reservationId): array
	{
		$stmt = $this->db->prepare('SELECT * FROM bank_transactions WHERE reservation_id = ? ORDER BY id');
		$stmt->execute([$reservationId]);
		return $stmt->fetchAll();
	}


	public function problemCount(): int
	{
		return (int) $this->db->query(
			"SELECT COUNT(*) FROM bank_transactions WHERE match_status IN ('" . implode("', '", self::ProblemStatuses) . "')",
		)->fetchColumn();
	}
}
