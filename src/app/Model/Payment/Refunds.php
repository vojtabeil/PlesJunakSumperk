<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Log\Actor;
use App\Model\Log\EventLog;
use DateTimeImmutable;
use PDO;
use Throwable;


/**
 * Money returned to customers, written by hand (outgoing bank movements are not imported). A reservation owes
 * a refund of everything paid when it is cancelled, otherwise of what was paid above its price; what is still
 * to return is that minus the refunds written. Amounts in hellers.
 */
final class Refunds
{
	public const Methods = ['bank' => 'převodem', 'cash' => 'hotově'];

	/** SQL: hellers still to return of reservation r (alias r). */
	public const DueSql = "ROUND((CASE WHEN r.status = 'cancelled' THEN r.paid_amount
		ELSE GREATEST(r.paid_amount - r.total_price, 0) END) * 100)
		- ROUND(COALESCE((SELECT SUM(f.amount) FROM refunds f WHERE f.reservation_id = r.id), 0) * 100)";


	public function __construct(
		private readonly PDO $db,
		private readonly EventLog $eventLog,
		private readonly Actor $actor,
	) {
	}


	/** Hellers still to return to the reservation's customer (0 = nothing). */
	public function due(int $reservationId): int
	{
		$stmt = $this->db->prepare('SELECT ' . self::DueSql . ' FROM reservations r WHERE r.id = ?');
		$stmt->execute([$reservationId]);
		return max(0, (int) $stmt->fetchColumn());
	}


	/** @return list<array{id: int, amount: int, refunded_on: string, method: string, note: ?string, created_by: ?string}> */
	public function forReservation(int $reservationId): array
	{
		$stmt = $this->db->prepare(
			'SELECT f.id, f.amount, f.refunded_on, f.method, f.note, a.name AS created_by
			FROM refunds f LEFT JOIN admin_users a ON a.id = f.created_by
			WHERE f.reservation_id = ? ORDER BY f.refunded_on, f.id',
		);
		$stmt->execute([$reservationId]);
		return array_map(static fn(array $row): array => [
			'id' => (int) $row['id'],
			'amount' => (int) round((float) $row['amount'] * 100),
			'refunded_on' => (string) $row['refunded_on'],
			'method' => (string) $row['method'],
			'note' => $row['note'] !== null ? (string) $row['note'] : null,
			'created_by' => $row['created_by'] !== null ? (string) $row['created_by'] : null,
		], $stmt->fetchAll());
	}


	/**
	 * Reservations with money to return and the sum.
	 * @return array{int, int} [count, hellers]
	 */
	public function summary(): array
	{
		$row = $this->db->query(
			'SELECT COUNT(*), COALESCE(SUM(due), 0) FROM (SELECT ' . self::DueSql . ' AS due FROM reservations r) x WHERE due > 0',
		)->fetch(PDO::FETCH_NUM);
		return [(int) ($row[0] ?? 0), (int) ($row[1] ?? 0)];
	}


	/**
	 * Writes a refund. When nothing is left to return, the overpaid payments of the reservation stop being a problem.
	 * @param int $amount hellers, at most what is still to return
	 */
	public function record(int $reservationId, int $amount, DateTimeImmutable $refundedOn, string $method, string $note = ''): void
	{
		if (!isset(self::Methods[$method])) {
			throw new PaymentError('Neznámý způsob vrácení.');
		}
		$this->db->beginTransaction();
		try {
			$this->db->prepare('SELECT id FROM reservations WHERE id = ? FOR UPDATE')->execute([$reservationId]);
			$due = $this->due($reservationId);
			if ($amount <= 0) {
				throw new PaymentError('Zadejte částku větší než nula.');
			}
			if ($amount > $due) {
				throw new PaymentError('Vrátit lze nejvýše ' . self::format($due) . '.');
			}
			$this->db->prepare(
				'INSERT INTO refunds (reservation_id, amount, refunded_on, method, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
			)->execute([
				$reservationId,
				number_format($amount / 100, 2, '.', ''),
				$refundedOn->format('Y-m-d'),
				$method,
				trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
				$this->actor->adminId(),
			]);
			if ($amount === $due) {
				$this->db->prepare(
					"UPDATE bank_transactions SET match_status = 'matched' WHERE reservation_id = ? AND match_status = 'overpaid'",
				)->execute([$reservationId]);
			}
			$this->eventLog->record('refund.recorded', $reservationId, [
				'amount' => $amount / 100,
				'method' => $method,
				'date' => $refundedOn->format('Y-m-d'),
			] + (trim($note) !== '' ? ['note' => trim($note)] : []));
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	/** Removes a refund written by mistake (logged). */
	public function delete(int $refundId): int
	{
		$stmt = $this->db->prepare('SELECT * FROM refunds WHERE id = ?');
		$stmt->execute([$refundId]);
		$refund = $stmt->fetch() ?: throw new PaymentError('Zápis o vrácení neexistuje.');
		$this->db->prepare('DELETE FROM refunds WHERE id = ?')->execute([$refundId]);
		$this->eventLog->record('refund.deleted', (int) $refund['reservation_id'], [
			'amount' => (float) $refund['amount'],
			'method' => (string) $refund['method'],
			'date' => (string) $refund['refunded_on'],
		]);
		return (int) $refund['reservation_id'];
	}


	private static function format(int $hellers): string
	{
		return number_format($hellers / 100, 0, ',', "\u{A0}") . "\u{A0}Kč";
	}
}
