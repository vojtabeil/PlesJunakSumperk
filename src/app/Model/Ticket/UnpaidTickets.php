<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use App\Model\Log\EventLog;
use PDO;


/**
 * Tickets of a reservation that is not paid yet are not shown anywhere (no QR code, no public print page).
 * An administrator may still show and print them, but only with a reason, which goes to the event log.
 */
final class UnpaidTickets
{
	public const MinReasonLength = 5;
	public const MaxReasonLength = 300;

	/** Reservations whose tickets are hidden until paid (cancelled ones are not valid at all). */
	public const UnpaidStatuses = ['confirmed', 'partially_paid'];


	public function __construct(
		private readonly PDO $db,
		private readonly Tickets $tickets,
		private readonly EventLog $eventLog,
	) {
	}


	/**
	 * Whether a ticket may be shown to anybody who has its link: Z-lístky and tickets of paid reservations.
	 * @param array<string, mixed> $ticket from Tickets::findForPrint
	 */
	public static function isVisible(array $ticket): bool
	{
		return $ticket['crew_member_id'] !== null || ($ticket['reservation_status'] ?? null) === 'paid';
	}


	/**
	 * An administrator shows the tickets of an unpaid reservation (e.g. to print them for a guest who pays
	 * at the entrance). The reason is required and logged with the codes.
	 * @return list<array<string, mixed>> the tickets (Tickets::forReservation)
	 * @throws TicketError when the reservation is not unpaid or the reason is missing
	 */
	public function reveal(int $reservationId, string $reason): array
	{
		$reason = trim((string) preg_replace('~\s+~u', ' ', $reason));
		if (mb_strlen($reason) < self::MinReasonLength) {
			throw new TicketError(sprintf('Napište důvod, proč vstupenky zobrazujete (aspoň %d znaků).', self::MinReasonLength));
		}
		if (mb_strlen($reason) > self::MaxReasonLength) {
			throw new TicketError(sprintf('Důvod může mít nejvýše %d znaků.', self::MaxReasonLength));
		}
		$stmt = $this->db->prepare('SELECT status FROM reservations WHERE id = ?');
		$stmt->execute([$reservationId]);
		$status = $stmt->fetchColumn();
		if (!in_array($status, self::UnpaidStatuses, true)) {
			throw new TicketError($status === 'paid'
				? 'Rezervace je zaplacená, vstupenky lze zobrazit bez důvodu.'
				: 'Vstupenky této rezervace nelze zobrazit (rezervace není potvrzená nebo je zrušená).');
		}
		$tickets = $this->tickets->forReservation($reservationId);
		$this->eventLog->record('ticket.revealed', $reservationId, [
			'reason' => $reason,
			'codes' => implode(', ', array_column($tickets, 'code')),
			'count' => count($tickets),
		]);
		return $tickets;
	}
}
