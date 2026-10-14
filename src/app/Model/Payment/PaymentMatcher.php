<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Log\EventLog;
use App\Model\Reservation\ReservationService;
use PDO;


/**
 * Matches stored bank transactions to reservations (variable symbol = prefix + reservation id,
 * see VariableSymbol; other variable symbols belong to other payments of the account).
 * All incoming payments of a reservation are summed, so paying in parts works.
 * Callers run it inside a DB transaction.
 */
final class PaymentMatcher
{
	/** Transactions whose amount counts towards the reservation. */
	private const CountedStatuses = "'matched', 'underpaid', 'overpaid'";


	public function __construct(
		private readonly PDO $db,
		private readonly VariableSymbol $variableSymbol,
		private readonly EventLog $eventLog,
	) {
	}


	/** Automatic matching right after import. Returns the payment change, or null when nothing matched. */
	public function apply(int $transactionId): ?PaymentChange
	{
		$tx = $this->transaction($transactionId);
		if (ltrim(trim((string) $tx['variable_symbol']), '0') === '') {
			$this->setStatus($transactionId, 'no_vs');
			$this->eventLog->record('payment.unmatched', null, $this->describe($tx), automatic: true);
			return null;
		}
		$reservationId = $this->variableSymbol->reservationId($tx['variable_symbol']);
		if ($reservationId === null) {
			// Another payment to the scout group's account (membership fee etc.), not a problem.
			$this->setStatus($transactionId, 'foreign');
			return null;
		}
		$reservation = $this->lockReservation($reservationId);
		if ($reservation === null) {
			$this->setStatus($transactionId, 'unknown_vs');
			$this->eventLog->record('payment.unmatched', null, $this->describe($tx), automatic: true);
			return null;
		}
		return $this->assignTo($transactionId, $reservation);
	}


	/** Organizer assigns a payment that could not be matched automatically. */
	public function assign(int $transactionId, int $reservationId): PaymentChange
	{
		$tx = $this->transaction($transactionId);
		if ((float) $tx['amount'] <= 0) {
			throw new PaymentError('Odchozí platbu nelze přiřadit k rezervaci.');
		}
		if (in_array($tx['match_status'], ['matched', 'underpaid', 'overpaid'], true)) {
			throw new PaymentError('Platba už je přiřazená k rezervaci č. ' . $tx['reservation_id'] . '.');
		}
		$reservation = $this->lockReservation($reservationId)
			?? throw new PaymentError("Rezervace č. $reservationId neexistuje nebo není potvrzená.");
		$this->eventLog->record('payment.assigned', $reservationId, $this->describe($tx));
		return $this->assignTo($transactionId, $reservation);
	}


	/**
	 * Underpaid or overpaid payment that the organizer has dealt with (refunded the difference,
	 * got the rest in cash...): it stays with its reservation but is no longer a problem.
	 */
	public function settle(int $transactionId): void
	{
		$tx = $this->transaction($transactionId);
		if ($tx['reservation_id'] === null || !in_array($tx['match_status'], ['underpaid', 'overpaid'], true)) {
			throw new PaymentError('Vyřízenou lze označit jen přiřazenou platbu s nedoplatkem nebo přeplatkem.');
		}
		$this->setStatus($transactionId, 'matched');
		$this->eventLog->record('payment.settled', (int) $tx['reservation_id'], $this->describe($tx));
	}


	/** The payment does not belong to the ball (e.g. a donation); keep it out of the problem list. */
	public function ignore(int $transactionId): void
	{
		$tx = $this->transaction($transactionId);
		if ($tx['reservation_id'] !== null) {
			throw new PaymentError('Přiřazenou platbu nelze ignorovat.');
		}
		$this->setStatus($transactionId, 'ignored');
		$this->eventLog->record('payment.ignored', null, $this->describe($tx));
	}


	/** Takes back "does not belong to the ball" (clicked by mistake): the payment is to be resolved again. */
	public function unignore(int $transactionId): void
	{
		$tx = $this->transaction($transactionId);
		if ($tx['match_status'] !== 'ignored') {
			throw new PaymentError('Platba není označená jako nesouvisející s plesem.');
		}
		$this->setStatus($transactionId, self::unresolvedStatus($tx));
		$this->eventLog->record('payment.unignored', null, $this->describe($tx));
	}


	/**
	 * Takes a payment away from its reservation (assigned to a wrong one): the payment is to be resolved
	 * again and the reservation's paid amount and status are computed from its remaining payments.
	 */
	public function unassign(int $transactionId): void
	{
		$tx = $this->transaction($transactionId);
		if ($tx['reservation_id'] === null) {
			throw new PaymentError('Platba není přiřazená k žádné rezervaci.');
		}
		$reservationId = (int) $tx['reservation_id'];
		$stmt = $this->db->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
		$stmt->execute([$reservationId]);
		$reservation = $stmt->fetch() ?: throw new PaymentError("Rezervace č. $reservationId neexistuje.");

		$this->db->prepare('UPDATE bank_transactions SET reservation_id = NULL, match_status = ? WHERE id = ?')
			->execute([self::unresolvedStatus($tx), $transactionId]);

		$stmt = $this->db->prepare(
			'SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE reservation_id = ? AND match_status IN (' . self::CountedStatuses . ')',
		);
		$stmt->execute([$reservationId]);
		$paid = (int) round((float) $stmt->fetchColumn() * 100);
		$total = (int) $reservation['total_price'] * 100;
		$status = (string) $reservation['status'];
		if (in_array($status, ['confirmed', 'partially_paid', 'paid'], true)) {
			$status = match (true) {
				$paid >= $total => 'paid',
				$paid > 0 => 'partially_paid',
				default => 'confirmed',
			};
		}
		$this->db->prepare(
			"UPDATE reservations SET status = ?, paid_amount = ?, paid_at = IF(? = 'paid', paid_at, NULL) WHERE id = ?",
		)->execute([$status, number_format($paid / 100, 2, '.', ''), $status, $reservationId]);

		$this->eventLog->record('payment.unassigned', $reservationId, $this->describe($tx) + ['paid' => $paid / 100, 'total' => $total / 100]);
	}


	/** @param array<string, mixed> $reservation */
	private function assignTo(int $transactionId, array $reservation): PaymentChange
	{
		$reservationId = (int) $reservation['id'];
		$this->db->prepare('UPDATE bank_transactions SET reservation_id = ? WHERE id = ?')
			->execute([$reservationId, $transactionId]);

		// Sum of all payments of the reservation including this one, in hellers.
		$stmt = $this->db->prepare(
			'SELECT COALESCE(SUM(amount), 0) FROM bank_transactions
			WHERE reservation_id = ? AND (id = ? OR match_status IN (' . self::CountedStatuses . '))',
		);
		$stmt->execute([$reservationId, $transactionId]);
		$paid = (int) round((float) $stmt->fetchColumn() * 100);
		$total = (int) $reservation['total_price'] * 100;

		$txStatus = match (true) {
			$paid > $total => 'overpaid',
			$paid === $total => 'matched',
			default => 'underpaid',
		};
		$this->setStatus($transactionId, $txStatus);

		$newStatus = $paid >= $total ? 'paid' : 'partially_paid';
		if ($newStatus === 'paid') {
			// Earlier partial payments are no longer a problem once the reservation is fully paid.
			$this->db->prepare(
				"UPDATE bank_transactions SET match_status = 'matched'
				WHERE reservation_id = ? AND id <> ? AND match_status = 'underpaid'",
			)->execute([$reservationId, $transactionId]);
		}
		$this->db->prepare(
			'UPDATE reservations
			SET status = ?, paid_amount = ?, paid_at = IF(? = \'paid\', COALESCE(paid_at, NOW()), paid_at)
			WHERE id = ?',
		)->execute([$newStatus, number_format($paid / 100, 2, '.', ''), $newStatus, $reservationId]);

		$tx = $this->transaction($transactionId);
		$this->eventLog->record(
			match ($txStatus) {
				'overpaid' => 'payment.overpaid',
				'underpaid' => 'payment.partial',
				default => 'payment.matched',
			},
			$reservationId,
			$this->describe($tx) + ['paid' => $paid / 100, 'total' => $total / 100],
			automatic: true,
		);
		return new PaymentChange($reservationId, (string) $reservation['status'], $newStatus, $paid, $total);
	}


	/**
	 * @param array<string, mixed> $tx
	 * @return array<string, mixed>
	 */
	private function describe(array $tx): array
	{
		return [
			'transaction' => (int) $tx['id'],
			'amount' => (float) $tx['amount'],
			'vs' => (string) ($tx['variable_symbol'] ?? ''),
			'payer' => (string) ($tx['counter_name'] ?? ''),
			'date' => (string) $tx['booked_on'],
		];
	}


	/** @return array<string, mixed> */
	private function transaction(int $id): array
	{
		$stmt = $this->db->prepare('SELECT * FROM bank_transactions WHERE id = ? FOR UPDATE');
		$stmt->execute([$id]);
		return $stmt->fetch() ?: throw new PaymentError("Platba $id neexistuje.");
	}


	/** @return array<string, mixed>|null a reservation that can receive payments */
	private function lockReservation(int $id): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM reservations WHERE id = ? AND status IN (' . ReservationService::FinishedStatuses . ') FOR UPDATE',
		);
		$stmt->execute([$id]);
		return $stmt->fetch() ?: null;
	}


	/**
	 * Status of a payment the organizer has to resolve (no reservation): without a VS, or with an unknown one.
	 * @param array<string, mixed> $tx
	 */
	private static function unresolvedStatus(array $tx): string
	{
		return ltrim(trim((string) $tx['variable_symbol']), '0') === '' ? 'no_vs' : 'unknown_vs';
	}


	private function setStatus(int $transactionId, string $status): void
	{
		$this->db->prepare('UPDATE bank_transactions SET match_status = ? WHERE id = ?')
			->execute([$status, $transactionId]);
	}
}
