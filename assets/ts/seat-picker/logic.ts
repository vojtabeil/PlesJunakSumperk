// Pure helpers of the seat picker (no DOM, no network) - covered by tests/ts.

import type { ApiState, Prices, ReservationState, SeatStatus } from './types';

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

/** Short list for the summary: "10–1, 10–2", or "–" when empty. */
export function formatSeatList(seats: { label: string }[]): string {
  return seats.length ? seats.map((s) => s.label.replace('/', '–')).join(', ') : '–';
}

/** 125 -> "2:05" */
export function formatCountdown(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds));
  return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
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

export function totals(reservation: ReservationState, prices: Prices): Totals {
  const seats = reservation.seats.length;
  const standing = reservation.standing;
  return {
    seats,
    standing,
    tickets: seats + standing,
    price: seats * prices.seat + standing * prices.standing,
  };
}

/** Whether one more standing ticket may be added (the server checks again). */
export function canAddStanding(state: ApiState): boolean {
  const reservation = state.reservation;
  if (!reservation) {
    return false;
  }
  const { tickets } = totals(reservation, state.prices);
  return tickets < state.limits.max_tickets && reservation.standing < state.limits.standing_left;
}

/** Position of a map point as a CSS percentage. */
export function percent(value: number, total: number): string {
  return `${((value / total) * 100).toFixed(3)}%`;
}
