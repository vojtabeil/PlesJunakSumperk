// Pure helpers of the seat picker (no DOM, no network) - covered by tests/ts.

import type { ApiState, Direction, HallSeat, HallTable, Prices, ReservationState, SeatStatus } from './types';

const priceFormat = new Intl.NumberFormat('cs-CZ', {
  style: 'currency',
  currency: 'CZK',
  maximumFractionDigits: 0,
});

export function formatPrice(amount: number): string {
  return priceFormat.format(amount);
}

/** "3/5" -> { table: "3", seat: "5" } */
export function splitSeatLabel(label: string): { table: string; seat: string } {
  const [table = '', seat = ''] = label.split('/', 2);
  return { table, seat };
}

/** "3/5" -> "Stůl 3 · místo 5" */
export function formatSeat(label: string): string {
  const { table, seat } = splitSeatLabel(label);
  return seat ? `Stůl ${table} · místo ${seat}` : label;
}

/** 125 -> "2:05" */
export function formatCountdown(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds));
  return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

/** Czech plural: 1 lístek, 2-4 lístky, 5+ lístků. */
export function plural(count: number, one: string, few: string, many: string): string {
  if (count === 1) {
    return one;
  }
  return count >= 2 && count <= 4 ? few : many;
}

export function seatStatus(seatId: number, state: ApiState | null): SeatStatus {
  if (!state) {
    return 'free';
  }
  if (state.mine.includes(seatId)) {
    return 'mine';
  }
  return state.taken.includes(seatId) ? 'taken' : 'free';
}

export interface Totals {
  seats: number;
  standing: number;
  tickets: number;
  price: number;
}

const empty: ReservationState = { email: null, standing: 0, seats: [], expires_in: null };

export function totals(reservation: ReservationState | null, prices: Prices): Totals {
  const { seats: chosen, standing } = reservation ?? empty;
  const seats = chosen.length;
  return {
    seats,
    standing,
    tickets: seats + standing,
    price: seats * prices.seat + standing * prices.standing,
  };
}

/** Whether one more standing ticket may be added (the server checks again). */
export function canAddStanding(state: ApiState): boolean {
  const { tickets, standing } = totals(state.reservation, state.prices);
  return tickets < state.limits.max_tickets && standing < state.limits.standing_left;
}

/** Free seats in the whole hall (for the information above the map). */
export function freeSeats(totalSeats: number, state: ApiState): number {
  return Math.max(0, totalSeats - state.taken.length - state.mine.length);
}

/** A seat's position on the screen (centre of its shape in the plan), for the arrow keys. */
export interface MapPoint {
  id: number;
  x: number;
  y: number;
}

/**
 * The nearest seat in the given direction (arrow keys on the map), or null.
 * Seats more to the side than forward are ignored, so "right" stays in the row.
 */
export function nearestSeat(from: MapPoint, seats: MapPoint[], direction: Direction): MapPoint | null {
  let best: MapPoint | null = null;
  let bestScore = Infinity;
  for (const seat of seats) {
    const dx = seat.x - from.x;
    const dy = seat.y - from.y;
    const [forward, side] =
      direction === 'right' ? [dx, dy] : direction === 'left' ? [-dx, dy] : direction === 'down' ? [dy, dx] : [-dy, dx];
    if (seat.id === from.id || forward <= 0 || Math.abs(side) > forward) {
      continue;
    }
    const score = forward + 2 * Math.abs(side);
    if (score < bestScore) {
      best = seat;
      bestScore = score;
    }
  }
  return best;
}

const StatusText: Record<SeatStatus, string> = { free: 'volné', mine: 'vybrané', taken: 'obsazené' };

/** What a screen reader says about a seat: "Stůl 3 (Jaroslav Foglar), místo 25, volné, 350 Kč". */
export function seatDescription(seat: HallSeat, table: HallTable | undefined, status: SeatStatus, price: number): string {
  const { table: number, seat: place } = splitSeatLabel(seat.label);
  const tableText = `Stůl ${number}${table?.name ? ` (${table.name})` : ''}`;
  return `${tableText}, místo ${place}, ${StatusText[status]}${status === 'taken' ? '' : `, ${formatPrice(price)}`}`;
}