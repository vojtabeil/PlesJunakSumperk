<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Presentation\Admin\BaseTemplate;


final class DashboardTemplate extends BaseTemplate
{
	/** @var array<string, int> */
	public array $stats;

	/** "Do plesu zbývá 12 dní" (EventDate::countdown); null while the date of the ball is not set. */
	public ?string $eventCountdown;


	public int $seatTotal;

	/** Free seats at tables out of sale (not in the sale's capacity). */
	public int $seatsUnavailable;

	/** @var list<array<string, mixed>> */
	public array $seatRows;

	public ?string $seatFilter;

	public int $paymentProblems;

	/** @var array<string, int> */
	public array $reservationProblems;

	/** Overdue reservations without a recent reminder. */
	public int $toRemind;

	public ?\DateTimeImmutable $lastImportAt;

	public bool $importOverdue;

	/** Hellers still to return to customers (Refunds::summary). */
	public int $refundTotal;

	/** @var array{used: int, total: int, kinds: array<string, array{used: int, total: int}>} tickets used at the entrance */
	public array $entrance;

	/**
	 * The three kinds of tickets (seat, standing, crew) as stacked bars (StackedBar::build + label, sold).
	 * @var array<string, array{label: string, sold: int, total: int, parts: list<array{key: string, value: int, percent: float}>, free: int}>
	 */
	public array $bars;

	/** @var array<string, list<array<string, mixed>>> full lists of the tickets by kind (Tickets::listing) */
	public array $ticketLists;

	/** @var list<array<string, mixed>> the newest reservations (ReservationAdmin::search) */
	public array $latestReservations;

	/** @var array<string, mixed> sales of the last 14 days (BarChart::build) */
	public array $recentChart;

	/** Tickets sold in the last 7 days. */
	public int $recentWeek;

	/** A hall plan is uploaded (without it seats cannot be sold). */
	public bool $hasPlan;
}
