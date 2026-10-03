import { describe, expect, test } from 'bun:test';
import {
  canAddStanding,
  formatCountdown,
  formatSeat,
  freeSeats,
  nearestSeat,
  percent,
  plural,
  seatStatus,
  splitSeatLabel,
  tableZones,
  totals,
} from '../../assets/ts/seat-picker/logic';
import type { ApiState, HallSeat } from '../../assets/ts/seat-picker/types';
import { emailSuggestion, validate } from '../../assets/ts/seat-picker/components/CheckoutForm';

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

  test('formats a seat for people', () => {
    expect(formatSeat('10/2')).toBe('Stůl 10 · místo 2');
    expect(formatSeat('X')).toBe('X');
  });

  test('uses Czech plurals', () => {
    expect([1, 2, 4, 5, 0].map((n) => plural(n, 'lístek', 'lístky', 'lístků'))).toEqual(['lístek', 'lístky', 'lístky', 'lístků', 'lístků']);
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
    expect(canAddStanding(state({ reservation: null })), 'Standing tickets can be the first choice').toBe(true);
  });

  test('counts free seats and works without a draft', () => {
    expect(freeSeats(10, state())).toBe(8);
    expect(totals(null, state().prices)).toEqual({ seats: 0, standing: 0, tickets: 0, price: 0 });
  });
});

describe('map', () => {
  const seat = (id: number, x: number, y: number, table: number | null = 1): HallSeat => ({ id, label: `${table}/${id}`, table_id: table, x, y });
  const row = [seat(1, 0, 0), seat(2, 40, 0), seat(3, 80, 0), seat(4, 0, 40), seat(5, 40, 40)];

  test('arrow keys move to the nearest seat in that direction', () => {
    expect(nearestSeat(row[0]!, row, 'right')?.id).toBe(2);
    expect(nearestSeat(row[1]!, row, 'left')?.id).toBe(1);
    expect(nearestSeat(row[1]!, row, 'down')?.id).toBe(5);
    expect(nearestSeat(row[0]!, row, 'up')).toBeNull();
    expect(nearestSeat(row[2]!, row, 'right')).toBeNull();
  });

  test('draws one zone around each table with its seats', () => {
    const zones = tableZones([...row, seat(9, 200, 200, 2), seat(10, 0, 0, null)], 10);
    expect(zones).toEqual([
      { tableId: 1, x: -10, y: -10, width: 100, height: 60 },
      { tableId: 2, x: 190, y: 190, width: 20, height: 20 },
    ]);
  });
});

describe('checkout form', () => {
  test('validates fields like the server', () => {
    expect(validate('email', 'jana@example.cz')).toBeUndefined();
    expect(validate('email', 'jana@')).toBe('Zadejte e-mail ve tvaru jmeno@example.cz.');
    expect(validate('name', ' Jo ')).toBe('Vyplňte jméno a příjmení.');
    expect(validate('phone', '')).toBeUndefined();
    expect(validate('phone', '777 abc')).toBeDefined();
    expect(validate('consent', false)).toBeDefined();
  });

  test('suggests a fix of a mistyped e-mail domain', () => {
    expect(emailSuggestion('Jana@GMIAL.com')).toBe('jana@gmail.com');
    expect(emailSuggestion('jana@seznam.cz')).toBeNull();
    expect(emailSuggestion('jana')).toBeNull();
  });
});
