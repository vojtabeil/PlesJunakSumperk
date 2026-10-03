import { describe, expect, test } from 'bun:test';
import {
  canAddStanding,
  formatCountdown,
  formatSeatList,
  percent,
  seatStatus,
  splitSeatLabel,
  totals,
} from '../../assets/ts/seat-picker/logic';
import type { ApiState } from '../../assets/ts/seat-picker/types';

function state(overrides: Partial<ApiState> = {}): ApiState {
  return {
    sale_open: true,
    reservation: { email: 'a@example.com', standing: 1, seats: [{ id: 301, label: '3/1' }], expires_in: 100 },
    taken: [101],
    mine: [301],
    limits: { max_tickets: 10, standing_left: 5, hold_seconds: 120 },
    prices: { seat: 350, standing: 250 },
    ...overrides,
  };
}

describe('labels and formatting', () => {
  test('splits seat labels', () => {
    expect(splitSeatLabel('12/7')).toEqual({ table: '12', seat: '7' });
    expect(splitSeatLabel('broken')).toEqual({ table: 'broken', seat: '' });
  });

  test('formats the seat list', () => {
    expect(formatSeatList([])).toBe('–');
    expect(formatSeatList([{ label: '10/1' }, { label: '10/2' }])).toBe('10–1, 10–2');
  });

  test('formats the countdown', () => {
    expect(formatCountdown(125)).toBe('2:05');
    expect(formatCountdown(0)).toBe('0:00');
    expect(formatCountdown(-3)).toBe('0:00');
  });

  test('computes map percentages', () => {
    expect(percent(250, 1000)).toBe('25.000%');
  });
});

describe('seat status', () => {
  test('distinguishes mine, taken and free seats', () => {
    const s = state();
    expect(seatStatus(301, s)).toBe('mine');
    expect(seatStatus(101, s)).toBe('taken');
    expect(seatStatus(999, s)).toBe('free');
    expect(seatStatus(301, null)).toBe('free');
  });
});

describe('totals and limits', () => {
  test('sums seats and standing tickets', () => {
    const s = state();
    expect(totals(s.reservation!, s.prices)).toEqual({ seats: 1, standing: 1, tickets: 2, price: 600 });
  });

  test('allows adding standing tickets only within both limits', () => {
    expect(canAddStanding(state())).toBe(true);
    expect(canAddStanding(state({ limits: { max_tickets: 2, standing_left: 5, hold_seconds: 120 } }))).toBe(false);
    expect(canAddStanding(state({ limits: { max_tickets: 10, standing_left: 1, hold_seconds: 120 } }))).toBe(false);
    expect(canAddStanding(state({ reservation: null }))).toBe(false);
  });
});
