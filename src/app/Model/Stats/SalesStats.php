<?php

declare(strict_types=1);

namespace App\Model\Stats;

use App\Core\Clock\Clock;
use App\Model\Payment\Refunds;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use DateTimeImmutable;
use PDO;


/**
 * Numbers of the Statistiky page (and the small chart of the dashboard). Test reservations are never counted;
 * money in hellers. A day of sale is the day the reservation was made (confirmed): only reservations valid now
 * count there, a cancelled one is shown in the "cancelled" column of its day.
 */
final class SalesStats
{
	/** Parts of the hall by the letter of the table label (the 2026 drawing); others are "Stoly X". */
	public const Areas = ['A' => 'Hlavní sál', 'B' => 'Salónek', 'C' => 'Balkon'];

	private const Finished = ReservationService::FinishedStatuses;

	/** Bank payments that count towards their reservation (PaymentMatcher). */
	private const Counted = "'matched', 'underpaid', 'overpaid'";


	public function __construct(
		private readonly PDO $db,
		private readonly Settings $settings,
		private readonly Clock $clock,
	) {
	}


	/**
	 * Every day from the first reservation (or $from) to today, or to the day of the ball once it is over;
	 * days without anything are zeros.
	 * @return list<array{date: string, reservations: int, seats: int, standing: int, value: int, cancelled: int,
	 *     cancelled_tickets: int, paid_bank: int, paid_cash: int}>
	 */
	public function daily(?DateTimeImmutable $from = null): array
	{
		$days = [];
		$add = function (string $date, string $key, int $value) use (&$days): void {
			$days[$date] ??= ['reservations' => 0, 'seats' => 0, 'standing' => 0, 'value' => 0, 'cancelled' => 0,
				'cancelled_tickets' => 0, 'paid_bank' => 0, 'paid_cash' => 0];
			$days[$date][$key] += $value;
		};

		$rows = $this->db->query(
			"SELECT DATE(r.confirmed_at) AS day, COUNT(*) AS reservations, SUM(r.standing_tickets) AS standing,
				SUM(r.total_price) AS value,
				SUM((SELECT COUNT(*) FROM tickets t WHERE t.reservation_id = r.id AND t.seat_id IS NOT NULL)) AS seats
			FROM reservations r
			WHERE r.status IN (" . self::Finished . ") AND r.channel <> 'test' AND r.confirmed_at IS NOT NULL
			GROUP BY day",
		)->fetchAll();
		foreach ($rows as $row) {
			$add((string) $row['day'], 'reservations', (int) $row['reservations']);
			$add((string) $row['day'], 'seats', (int) $row['seats']);
			$add((string) $row['day'], 'standing', (int) $row['standing']);
			$add((string) $row['day'], 'value', (int) $row['value'] * 100);
		}

		$rows = $this->db->query(
			"SELECT DATE(r.confirmed_at) AS day, COUNT(*) AS reservations,
				SUM((SELECT COUNT(*) FROM tickets t WHERE t.reservation_id = r.id)) AS tickets
			FROM reservations r
			WHERE r.status = 'cancelled' AND r.channel <> 'test' AND r.confirmed_at IS NOT NULL
			GROUP BY day",
		)->fetchAll();
		foreach ($rows as $row) {
			$add((string) $row['day'], 'cancelled', (int) $row['reservations']);
			$add((string) $row['day'], 'cancelled_tickets', (int) $row['tickets']);
		}

		$rows = $this->db->query(
			"SELECT b.booked_on AS day, SUM(b.amount) AS amount
			FROM bank_transactions b JOIN reservations r ON r.id = b.reservation_id
			WHERE b.match_status IN (" . self::Counted . ") AND r.channel <> 'test'
			GROUP BY day",
		)->fetchAll();
		foreach ($rows as $row) {
			$add((string) $row['day'], 'paid_bank', (int) round((float) $row['amount'] * 100));
		}

		foreach ($this->cashPayments() as [$day, $amount]) {
			$add($day, 'paid_cash', $amount);
		}

		if ($days === []) {
			return [];
		}
		ksort($days);
		$first = $from ?? new DateTimeImmutable((string) array_key_first($days));
		$today = $this->clock->now()->setTime(0, 0);
		$ball = $this->settings->eventDate()?->day;
		$last = $ball !== null && $ball < $today ? $ball : $today;
		$last = max($last, new DateTimeImmutable((string) array_key_last($days)));

		$result = [];
		for ($day = $first->setTime(0, 0); $day <= $last; $day = $day->modify('+1 day')) {
			$date = $day->format('Y-m-d');
			$result[] = ['date' => $date] + ($days[$date] ?? ['reservations' => 0, 'seats' => 0, 'standing' => 0,
				'value' => 0, 'cancelled' => 0, 'cancelled_tickets' => 0, 'paid_bank' => 0, 'paid_cash' => 0]);
		}
		return $result;
	}


	/**
	 * Money of the sale. "paid" = what the valid reservations have paid (bank + cash); "received" also counts
	 * cancelled ones (their money is returned: refunded, to_refund).
	 * @return array<string, int>
	 */
	public function revenue(): array
	{
		$days = $this->settings->int('payment_days', 2);
		$row = $this->db->query(
			"SELECT
				COUNT(*) AS reservations,
				COALESCE(SUM(r.total_price), 0) * 100 AS ordered,
				COALESCE(SUM(r.standing_tickets), 0) AS standing,
				COALESCE(SUM((SELECT COUNT(*) FROM tickets t WHERE t.reservation_id = r.id AND t.seat_id IS NOT NULL)), 0) AS seats,
				ROUND(COALESCE(SUM(LEAST(r.paid_amount, r.total_price)), 0) * 100) AS paid,
				ROUND(COALESCE(SUM((SELECT SUM(b.amount) FROM bank_transactions b
					WHERE b.reservation_id = r.id AND b.match_status IN (" . self::Counted . "))), 0) * 100) AS bank,
				ROUND(COALESCE(SUM(IF(r.status IN ('confirmed', 'partially_paid'), r.total_price - r.paid_amount, 0)), 0) * 100) AS unpaid,
				ROUND(COALESCE(SUM(IF(r.status IN ('confirmed', 'partially_paid')
					AND DATE(r.confirmed_at) + INTERVAL $days DAY < CURDATE(), r.total_price - r.paid_amount, 0)), 0) * 100) AS overdue,
				ROUND(COALESCE(SUM(GREATEST(r.paid_amount - r.total_price, 0)), 0) * 100) AS overpaid
			FROM reservations r
			WHERE r.status IN (" . self::Finished . ") AND r.channel <> 'test'",
		)->fetch();
		$all = $this->db->query(
			"SELECT ROUND(COALESCE(SUM(r.paid_amount), 0) * 100) AS received,
				ROUND(COALESCE(SUM((SELECT SUM(f.amount) FROM refunds f WHERE f.reservation_id = r.id)), 0) * 100) AS refunded,
				COALESCE(SUM(GREATEST(" . Refunds::DueSql . ", 0)), 0) AS to_refund
			FROM reservations r WHERE r.channel <> 'test'",
		)->fetch();

		$result = array_map('intval', $row + $all);
		$result['tickets'] = $result['seats'] + $result['standing'];
		// Bank payments of valid reservations beyond their price are overpaid; the rest of "paid" came in cash.
		$result['paid_bank'] = min($result['paid'], $result['bank']);
		$result['paid_cash'] = $result['paid'] - $result['paid_bank'];
		$result['net'] = $result['received'] - $result['refunded'];
		$result['per_reservation'] = $result['reservations'] > 0 ? intdiv($result['ordered'], $result['reservations']) : 0;
		$result['per_ticket'] = $result['tickets'] > 0 ? intdiv($result['ordered'], $result['tickets']) : 0;
		unset($result['bank']);
		return $result;
	}


	/**
	 * Valid reservations by channel of the sale (public, VIP).
	 * @return array<string, array{reservations: int, tickets: int, ordered: int, paid: int}>
	 */
	public function byChannel(): array
	{
		$result = [];
		foreach (['public', 'vip'] as $channel) {
			$result[$channel] = ['reservations' => 0, 'tickets' => 0, 'ordered' => 0, 'paid' => 0];
		}
		$rows = $this->db->query(
			"SELECT r.channel, COUNT(*) AS reservations,
				SUM((SELECT COUNT(*) FROM tickets t WHERE t.reservation_id = r.id)) AS tickets,
				SUM(r.total_price) * 100 AS ordered, ROUND(SUM(LEAST(r.paid_amount, r.total_price)) * 100) AS paid
			FROM reservations r
			WHERE r.status IN (" . self::Finished . ") AND r.channel <> 'test'
			GROUP BY r.channel",
		)->fetchAll();
		foreach ($rows as $row) {
			$result[(string) $row['channel']] = [
				'reservations' => (int) $row['reservations'],
				'tickets' => (int) $row['tickets'],
				'ordered' => (int) $row['ordered'],
				'paid' => (int) $row['paid'],
			];
		}
		return $result;
	}


	/**
	 * Money of every reservation that ordered or paid something (CSV of the Tržby block).
	 * @return list<array<string, mixed>>
	 */
	public function reservationMoney(): array
	{
		$days = $this->settings->int('payment_days', 2);
		return $this->db->query(
			"SELECT r.id, r.name, r.email, r.status, r.channel, r.confirmed_at,
				IF(r.status IN ('confirmed', 'partially_paid'), DATE(r.confirmed_at) + INTERVAL $days DAY, NULL) AS due_on,
				r.total_price,
				COALESCE((SELECT SUM(b.amount) FROM bank_transactions b
					WHERE b.reservation_id = r.id AND b.match_status IN (" . self::Counted . ")), 0) AS paid_bank,
				r.paid_amount,
				COALESCE((SELECT SUM(f.amount) FROM refunds f WHERE f.reservation_id = r.id), 0) AS refunded,
				GREATEST(" . Refunds::DueSql . ", 0) / 100 AS to_refund
			FROM reservations r
			WHERE r.channel <> 'test' AND (r.status IN (" . self::Finished . ") OR r.paid_amount > 0)
			ORDER BY r.id",
		)->fetchAll();
	}


	/**
	 * Seats per table in hall order, with the part of the hall.
	 * @return list<array{table: string, area: string, available: bool, seats: int, sold: int, paid: int, held: int, free: int, unavailable: int}>
	 */
	public function tables(): array
	{
		$rows = $this->db->query(
			"SELECT t.label, t.available, COUNT(s.id) AS seats,
				COALESCE(SUM(b.state = 'reserved'), 0) AS sold,
				COALESCE(SUM(b.state = 'reserved' AND r.status = 'paid'), 0) AS paid,
				COALESCE(SUM(b.state = 'held'), 0) AS held
			FROM hall_tables t
			JOIN seats s ON s.table_id = t.id AND s.active = 1
			LEFT JOIN seat_bookings b ON b.seat_id = s.id
			LEFT JOIN reservations r ON r.id = b.reservation_id
			WHERE t.active = 1
			GROUP BY t.id
			ORDER BY t.sort_order, t.id",
		)->fetchAll();
		return array_map(static function (array $row): array {
			$label = (string) $row['label'];
			$letter = (string) preg_replace('~\d.*$~', '', $label);
			$rest = (int) $row['seats'] - (int) $row['sold'] - (int) $row['held'];
			$available = (bool) $row['available'];
			return [
				'table' => $label,
				'area' => self::Areas[$letter] ?? "Stoly $letter",
				'available' => $available,
				'seats' => (int) $row['seats'],
				'sold' => (int) $row['sold'],
				'paid' => (int) $row['paid'],
				'held' => (int) $row['held'],
				'free' => $available ? $rest : 0,
				'unavailable' => $available ? 0 : $rest,
			];
		}, $rows);
	}


	/**
	 * Cash payments ("Označit jako zaplacené (hotově)") by day, from the event log.
	 * @return list<array{string, int}> [date, hellers]
	 */
	private function cashPayments(): array
	{
		$rows = $this->db->query(
			"SELECT DATE(e.created_at) AS day, e.details
			FROM event_log e JOIN reservations r ON r.id = e.reservation_id
			WHERE e.action = 'reservation.paid_manually' AND r.channel <> 'test'",
		)->fetchAll();
		$result = [];
		foreach ($rows as $row) {
			$details = json_decode((string) $row['details'], true);
			$amount = is_array($details) ? (float) ($details['amount'] ?? 0) : 0.0;
			if ($amount > 0) {
				$result[] = [(string) $row['day'], (int) round($amount * 100)];
			}
		}
		return $result;
	}
}
