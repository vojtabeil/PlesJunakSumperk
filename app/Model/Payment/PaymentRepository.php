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


	/** @return list<array<string, mixed>> newest first */
	public function search(bool $problemsOnly = false): array
	{
		$where = $problemsOnly
			? "WHERE match_status IN ('" . implode("', '", self::ProblemStatuses) . "')"
			: '';
		return $this->db->query("SELECT * FROM bank_transactions $where ORDER BY booked_on DESC, id DESC LIMIT 500")->fetchAll();
	}


	/** @return list<array<string, mixed>> */
	public function forReservation(int $reservationId): array
	{
		$stmt = $this->db->prepare('SELECT * FROM bank_transactions WHERE reservation_id = ? ORDER BY id');
		$stmt->execute([$reservationId]);
		return $stmt->fetchAll();
	}


	/**
	 * Payments that can still be assigned by hand (id => label for a select box).
	 * @return array<int, string>
	 */
	public function unassigned(): array
	{
		$rows = $this->db->query(
			"SELECT id, booked_on, amount, variable_symbol, counter_name FROM bank_transactions
			WHERE reservation_id IS NULL AND match_status IN ('unknown_vs', 'no_vs') ORDER BY id DESC",
		)->fetchAll();
		$options = [];
		foreach ($rows as $row) {
			$options[(int) $row['id']] = sprintf(
				'%s · %s Kč · VS %s · %s',
				date('j. n.', (int) strtotime((string) $row['booked_on'])),
				number_format((float) $row['amount'], 0, ',', ' '),
				$row['variable_symbol'] ?? '–',
				$row['counter_name'] ?? '?',
			);
		}
		return $options;
	}


	public function problemCount(): int
	{
		return (int) $this->db->query(
			"SELECT COUNT(*) FROM bank_transactions WHERE match_status IN ('" . implode("', '", self::ProblemStatuses) . "')",
		)->fetchColumn();
	}
}
