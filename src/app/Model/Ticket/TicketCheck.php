<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use App\Model\Log\Actor;
use App\Model\Log\EventLog;
use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use App\Model\Site\SiteMode;
use PDO;


/**
 * Entrance check: what a scanned or typed code means, and recording the entry.
 *
 * Result of lookup() (also returned after a change), shaped for the check application:
 *   status: ok (paid, unused) | used | unpaid (let in with a warning) | invalid
 *   message: Czech headline for the organizer
 *   ticket: the scanned ticket or null (a reservation number was typed)
 *   reservation: id, name, status, remaining amount (CZK) or null
 *   crew: name and role of a Z-lístek (free pass of the crew) or null
 *   tickets: all tickets of the reservation (of a Z-lístek: just itself)
 */
final class TicketCheck
{
	public function __construct(
		private readonly PDO $db,
		private readonly Tickets $tickets,
		private readonly Settings $settings,
		private readonly VariableSymbol $variableSymbol,
		private readonly EventLog $eventLog,
		private readonly Actor $actor,
	) {
	}


	/**
	 * A ticket code, or a reservation number / variable symbol (typed by hand).
	 * @return array<string, mixed>
	 */
	public function lookup(string $input): array
	{
		$ticket = $this->tickets->findByCode($input);
		if ($ticket !== null && $ticket['crew_member_id'] !== null) {
			return $this->crewResult($ticket);
		}
		if ($ticket !== null) {
			return $this->result($ticket, $this->reservation((int) $ticket['reservation_id']));
		}

		$digits = trim($input);
		if (ctype_digit($digits)) {
			$id = $this->variableSymbol->reservationId($digits) ?? (int) $digits;
			$reservation = $this->reservation($id);
			if ($reservation !== null && $reservation['status'] !== 'draft') {
				return $this->result(null, $reservation);
			}
			return self::invalid('Rezervace s tímto číslem neexistuje.');
		}

		return self::invalid(Tickets::normalizeCode($input) === null
			? 'Tohle není kód vstupenky (6 znaků 0–9 a A–F).'
			: 'Vstupenka s tímto kódem neexistuje.');
	}


	/**
	 * Records the entry of one ticket. Two organizers scanning the same ticket at once:
	 * only the first one succeeds, the second gets "already used".
	 * @return array<string, mixed> the new state (lookup)
	 */
	public function use(string $code): array
	{
		$ticket = $this->requireTicket($code);
		$this->assertUsable($ticket);
		$stmt = $this->db->prepare('UPDATE tickets SET used_at = NOW(), used_by = ? WHERE id = ? AND used_at IS NULL');
		$stmt->execute([$this->actor->adminId(), $ticket['id']]);
		if ($stmt->rowCount() === 0) {
			throw new TicketError('Vstupenka už byla mezitím použita.');
		}
		$this->logUse('ticket.used', [$ticket]);
		return $this->lookup((string) $ticket['code']);
	}


	/**
	 * Records the entry of all unused tickets of a reservation (a group arriving together).
	 * @return array<string, mixed> the new state (lookup of the reservation)
	 */
	public function useReservation(int $reservationId): array
	{
		$unused = array_values(array_filter(
			$this->tickets->forReservation($reservationId),
			static fn(array $t): bool => $t['used_at'] === null,
		));
		if (!$unused) {
			throw new TicketError('Rezervace nemá žádné nepoužité vstupenky.');
		}
		$this->assertUsable($unused[0]);

		$this->db->beginTransaction();
		try {
			$used = [];
			$stmt = $this->db->prepare('UPDATE tickets SET used_at = NOW(), used_by = ? WHERE id = ? AND used_at IS NULL');
			foreach ($unused as $ticket) {
				$stmt->execute([$this->actor->adminId(), $ticket['id']]);
				if ($stmt->rowCount() > 0) {
					$used[] = $ticket;
				}
			}
			if ($used) {
				$this->logUse('ticket.used', $used);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->result(null, $this->reservation($reservationId));
	}


	/**
	 * Takes an entry back (scanned by mistake).
	 * @return array<string, mixed> the new state (lookup)
	 */
	public function undo(string $code): array
	{
		$ticket = $this->requireTicket($code);
		$stmt = $this->db->prepare('UPDATE tickets SET used_at = NULL, used_by = NULL WHERE id = ? AND used_at IS NOT NULL');
		$stmt->execute([$ticket['id']]);
		if ($stmt->rowCount() === 0) {
			throw new TicketError('Vstupenka ještě nebyla použita.');
		}
		$this->logUse('ticket.unused', [$ticket]);
		return $this->lookup((string) $ticket['code']);
	}


	/**
	 * Valid tickets and those used at the entrance, in total and by kind (seat, standing, crew = Z-lístky).
	 * Tickets of reservations count when finished (test ones only in the testing stage).
	 * @return array{used: int, total: int, kinds: array<string, array{used: int, total: int}>}
	 */
	public function entranceStats(): array
	{
		$rows = $this->db->query(
			"SELECT CASE WHEN t.crew_member_id IS NOT NULL THEN 'crew' WHEN t.seat_id IS NOT NULL THEN 'seat' ELSE 'standing' END AS kind,
				COUNT(*) AS total, COALESCE(SUM(t.used_at IS NOT NULL), 0) AS used
			FROM tickets t LEFT JOIN reservations r ON r.id = t.reservation_id
			WHERE t.crew_member_id IS NOT NULL OR (r.status IN (" . ReservationService::FinishedStatuses . ')'
				. ($this->countsTests() ? '' : " AND r.channel <> 'test'") . ')
			GROUP BY kind',
		)->fetchAll();
		$kinds = array_fill_keys(['seat', 'standing', 'crew'], ['used' => 0, 'total' => 0]);
		foreach ($rows as $row) {
			$kinds[(string) $row['kind']] = ['used' => (int) $row['used'], 'total' => (int) $row['total']];
		}
		return [
			'used' => array_sum(array_column($kinds, 'used')),
			'total' => array_sum(array_column($kinds, 'total')),
			'kinds' => $kinds,
		];
	}


	/** @return array<string, mixed> */
	private function requireTicket(string $code): array
	{
		return $this->tickets->findByCode($code) ?? throw new TicketError('Vstupenka s tímto kódem neexistuje.');
	}


	/** @param array<string, mixed> $ticket */
	private function assertUsable(array $ticket): void
	{
		if ($ticket['crew_member_id'] !== null) {
			return; // a Z-lístek is free and valid in every stage of the site
		}
		$problem = $this->invalidReason($this->reservation((int) $ticket['reservation_id']));
		if ($problem !== null) {
			throw new TicketError($problem);
		}
	}


	/**
	 * @param array<string, mixed>|null $ticket
	 * @param array<string, mixed>|null $reservation
	 * @return array<string, mixed>
	 */
	private function result(?array $ticket, ?array $reservation): array
	{
		if ($reservation === null) {
			return self::invalid('Vstupenka nepatří k žádné rezervaci.');
		}
		$tickets = array_map(self::ticketView(...), $this->tickets->forReservation((int) $reservation['id']));
		$unused = count(array_filter($tickets, static fn(array $t): bool => $t['used_at'] === null));
		$remaining = max(0, (int) $reservation['total_price'] - (int) round((float) $reservation['paid_amount']));

		$problem = $this->invalidReason($reservation);
		[$status, $message] = match (true) {
			$problem !== null => ['invalid', $problem],
			$ticket !== null && $ticket['used_at'] !== null => ['used', 'Vstupenka už byla použita.'],
			$ticket === null && $unused === 0 => ['used', 'Všechny vstupenky rezervace už byly použity.'],
			$remaining > 0 => ['unpaid', "Nezaplaceno – zbývá doplatit $remaining Kč."],
			default => ['ok', $ticket !== null ? 'Vstupenka je v pořádku.' : 'Rezervace je zaplacená.'],
		};

		return [
			'status' => $status,
			'message' => $message,
			'ticket' => $ticket !== null ? self::ticketView($ticket) : null,
			'reservation' => [
				'id' => (int) $reservation['id'],
				'name' => (string) $reservation['name'],
				'status' => (string) $reservation['status'],
				'remaining' => $remaining,
				'unused' => $unused,
			],
			'crew' => null,
			'tickets' => $tickets,
		];
	}


	/**
	 * A Z-lístek: valid until used, nothing to pay.
	 * @param array<string, mixed> $ticket
	 * @return array<string, mixed>
	 */
	private function crewResult(array $ticket): array
	{
		$used = $ticket['used_at'] !== null;
		$view = self::ticketView($ticket);
		return [
			'status' => $used ? 'used' : 'ok',
			'message' => $used ? 'Z-lístek už byl použit.' : 'Z-lístek je v pořádku.',
			'ticket' => $view,
			'reservation' => null,
			'crew' => ['name' => (string) $ticket['crew_name'], 'role' => (string) $ticket['crew_role']],
			'tickets' => [$view],
		];
	}


	/** @param array<string, mixed>|null $reservation */
	private function invalidReason(?array $reservation): ?string
	{
		return match (true) {
			$reservation === null => 'Vstupenka nepatří k žádné rezervaci.',
			$reservation['status'] === 'cancelled' => 'Rezervace byla zrušena, vstupenka neplatí.',
			!in_array($reservation['status'], ['confirmed', 'partially_paid', 'paid'], true) => 'Rezervace není dokončená.',
			$reservation['channel'] === 'test' && !$this->countsTests() => 'Testovací vstupenka, na ples neplatí.',
			default => null,
		};
	}


	/** Test tickets are valid only while the site is being tested (so the check can be tried out). */
	private function countsTests(): bool
	{
		return $this->settings->mode() === SiteMode::Testing;
	}


	/** @return array<string, mixed>|null */
	private function reservation(int $id): ?array
	{
		$stmt = $this->db->prepare('SELECT id, name, status, channel, total_price, paid_amount FROM reservations WHERE id = ?');
		$stmt->execute([$id]);
		return $stmt->fetch() ?: null;
	}


	/**
	 * @param array<string, mixed> $ticket
	 * @return array{code: string, kind: string, seat: ?string, used_at: ?string, used_by: ?string}
	 */
	private static function ticketView(array $ticket): array
	{
		return [
			'code' => (string) $ticket['code'],
			'kind' => match (true) {
				$ticket['crew_member_id'] !== null => 'crew',
				$ticket['seat_id'] !== null => 'seat',
				default => 'standing',
			},
			'seat' => $ticket['seat_label'] !== null ? (string) $ticket['seat_label'] : null,
			'used_at' => $ticket['used_at'] !== null ? (string) $ticket['used_at'] : null,
			'used_by' => $ticket['used_by_name'] !== null ? (string) $ticket['used_by_name'] : null,
		];
	}


	/** @return array<string, mixed> */
	private static function invalid(string $message): array
	{
		return ['status' => 'invalid', 'message' => $message, 'ticket' => null, 'reservation' => null, 'crew' => null, 'tickets' => []];
	}


	/** @param list<array<string, mixed>> $tickets */
	private function logUse(string $action, array $tickets): void
	{
		$seats = array_values(array_filter(array_map(
			static fn(array $t): ?int => $t['seat_id'] !== null ? (int) $t['seat_id'] : null,
			$tickets,
		)));
		$crew = $tickets[0]['crew_member_id'] !== null;
		$this->eventLog->record($action, $crew ? null : (int) $tickets[0]['reservation_id'], [
			'codes' => implode(', ', array_column($tickets, 'code')),
			'count' => count($tickets),
		] + ($crew ? ['crew' => "{$tickets[0]['crew_name']} ({$tickets[0]['crew_role']})"] : []), $seats);
	}
}
