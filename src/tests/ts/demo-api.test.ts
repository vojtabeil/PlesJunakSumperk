import { describe, expect, test } from 'bun:test';
import { createDemoApi, type DemoConfig, type KeyValueStore } from '../../assets/ts/seat-picker/demo-api';
import type { HallSeat } from '../../assets/ts/seat-picker/types';

const seats: HallSeat[] = [1, 2, 3, 4].map((id) => ({ id, label: `1/${id}`, table_id: 1, svg_id: `seat-${id}` }));
const config: DemoConfig = {
  taken: [4],
  maxTickets: 3,
  standingLeft: 2,
  holdSeconds: 60,
  prices: { seat: 350, standing: 250 },
  redirect: 'moje-rezervace.html',
};

function memoryStore(): KeyValueStore {
  const data = new Map<string, string>();
  return {
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => void data.set(key, value),
    removeItem: (key) => void data.delete(key),
  };
}

describe('demo API', () => {
  test('holds free seats, refuses taken ones and keeps the limit', async () => {
    const api = createDemoApi(seats, config, memoryStore());
    expect((await api('hold', { seat_id: 4 })).ok).toBe(false);
    await api('hold', { seat_id: 2 });
    await api('hold', { seat_id: 1 });
    const state = await api('standing', { count: 1 });
    expect(state.ok).toBe(true);
    expect(state.state?.mine).toEqual([2, 1]);
    expect(state.state?.reservation?.seats.map((s) => s.label)).toEqual(['1/1', '1/2']);
    const over = await api('hold', { seat_id: 3 });
    expect(over).toMatchObject({ ok: false, error: 'Na jednu rezervaci lze koupit nejvýše 3 lístků.' });
    expect((await api('standing', { count: 3 })).ok).toBe(false);
  });

  test('releases seats after the hold time, standing tickets stay', async () => {
    let time = 0;
    const api = createDemoApi(seats, config, memoryStore(), () => time);
    await api('hold', { seat_id: 1 });
    await api('standing', { count: 1 });
    time = 30_000;
    expect((await api('state')).state?.reservation?.expires_in).toBe(30);
    time = 61_000;
    const state = (await api('state')).state;
    expect(state?.mine).toEqual([]);
    expect(state?.reservation?.standing).toBe(1);
  });

  test('confirms like the server and starts again', async () => {
    const store = memoryStore();
    const api = createDemoApi(seats, config, store);
    expect((await api('confirm', { email: 'a@b.cz', name: 'Jana Nováková', phone: '', consent: true })).ok).toBe(false);
    await api('hold', { seat_id: 3 });
    expect(await api('confirm', { email: 'spatne', name: 'Jana', phone: '', consent: true })).toMatchObject({ ok: false });
    expect(await api('confirm', { email: 'a@b.cz', name: 'Jana', phone: '', consent: false })).toMatchObject({ ok: false });
    const done = await api('confirm', { email: 'a@b.cz', name: 'Jana Nováková', phone: '', consent: true });
    expect(done).toMatchObject({ ok: true, redirect: 'moje-rezervace.html' });
    expect(done.state?.reservation).toBeNull();
    expect(createDemoApi(seats, config, store)).toBeDefined();
  });

  test('keeps the choice in the storage', async () => {
    const store = memoryStore();
    await createDemoApi(seats, config, store)('hold', { seat_id: 2 });
    expect((await createDemoApi(seats, config, store)('state')).state?.mine).toEqual([2]);
  });
});
