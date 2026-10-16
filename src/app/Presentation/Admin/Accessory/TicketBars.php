<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accessory;

use App\Model\Crew\CrewMembers;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\SeatOverview;
use App\Model\Ticket\TicketCheck;


/** The three kinds of tickets as stacked bars (ticketBars.latte) for the dashboard and the Statistiky page. */
final class TicketBars
{
	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly SeatOverview $seatOverview,
		private readonly CrewMembers $crew,
		private readonly TicketCheck $ticketCheck,
	) {
	}


	/**
	 * @return array{bars: array<string, array{label: string, sold: int, total: int, parts: list<array{key: string, value: int, percent: float}>, free: int}>,
	 *     entrance: array{used: int, total: int, kinds: array<string, array{used: int, total: int}>}, stats: array<string, int>,
	 *     counts: array<string, int>, forSale: int, unavailable: int}
	 */
	public function build(): array
	{
		$stats = $this->reservationAdmin->stats();
		$counts = $this->seatOverview->counts();
		// Capacity of the sale: seats of the tables for sale and seats sold at tables taken out of sale since.
		$forSale = array_sum(array_intersect_key($counts, array_flip(SeatOverview::ForSale)));
		$crew = $this->crew->stats();
		$standing = $this->reservationAdmin->standingByPayment();
		return [
			// Darker = further along (chosen -> reserved -> paid / picked up).
			'bars' => [
				'seat' => ['label' => 'Místa u stolů', 'sold' => $stats['reserved']] + StackedBar::build(
					['paid' => $counts['paid'], 'partial' => $counts['partial'], 'unpaid' => $counts['unpaid'], 'held' => $counts['held']],
					$forSale,
				),
				'standing' => ['label' => 'Na stání', 'sold' => $stats['standing']] + StackedBar::build(
					['paid' => $standing['paid'], 'partial' => $standing['partial'], 'unpaid' => $standing['unpaid']],
					$stats['standing_capacity'],
				),
				'crew' => ['label' => 'Z-lístky (tým)', 'sold' => $crew['total']] + StackedBar::build(
					['picked_up' => $crew['picked_up'], 'waiting' => $crew['total'] - $crew['picked_up']],
				),
			],
			'entrance' => $this->ticketCheck->entranceStats(),
			'stats' => $stats,
			'counts' => $counts,
			'forSale' => $forSale,
			'unavailable' => $counts['unavailable'],
		];
	}
}
