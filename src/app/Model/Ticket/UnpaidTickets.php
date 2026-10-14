<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use App\Model\Log\EventLog;
use PDO;


/**
 * Tickets of a reservation that is not paid yet are not shown anywhere (no QR code, no public print page).
 * A logged-in administrator may still show and print one on its print page, with a reason that is printed
 * on the ticket and goes to the event log.
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
	 * A logged-in administrator shows and prints one ticket of an unpaid reservation (e.g. the guest pays in
	 * cash at the entrance). The reason is required, printed in red on the ticket and logged.
	 * @return string the cleaned reason
	 * @throws TicketError when the reservation is not unpaid or the reason is missing
	 */
	public function reveal(string $code, string $reason): string
	{
		$reason = trim((string) preg_replace('~\s+~u', ' ', $reason));
		if (mb_strlen($reason) < self::MinReasonLength) {
			throw new TicketError(sprintf('Napište důvod, proč vstupenku zobrazujete (aspoň %d znaků).', self::MinReasonLength));
		}
		if (mb_strlen($reason) > self::MaxReasonLength) {
			throw new TicketError(sprintf('Důvod může mít nejvýše %d znaků.', self::MaxReasonLength));
		}
		$ticket = $this->tickets->findByCode($code) ?? throw new TicketError('Vstupenka neexistuje.');
		$stmt = $this->db->prepare('SELECT status FROM reservations WHERE id = ?');
		$stmt->execute([$ticket['reservation_id']]);
		if (!in_array($stmt->fetchColumn(), self::UnpaidStatuses, true)) {
			throw new TicketError('Vstupenku lze takto zobrazit jen u potvrzené nezaplacené rezervace.');
		}
		$this->eventLog->record('ticket.revealed', (int) $ticket['reservation_id'], [
			'reason' => $reason,
			'codes' => (string) $ticket['code'],
			'count' => 1,
		], $ticket['seat_id'] !== null ? [(int) $ticket['seat_id']] : []);
		return $reason;
	}
}
