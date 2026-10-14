import { describe, expect, test } from 'bun:test';
import { createApi, type ApiCall } from '../../assets/ts/seat-picker/api';
import { createStore } from '../../assets/ts/seat-picker/store';
import type { ApiResponse, ApiState, Operation } from '../../assets/ts/seat-picker/types';

const baseState: ApiState = {
  sale_open: true,
  reservation: null,
  taken: [],
  mine: [],
  limits: { max_tickets: 10, standing_left: 5, hold_seconds: 120 },
  prices: { seat: 350, standing: 250 },
};

function fakeApi(responses: Partial<Record<Operation, ApiResponse>>) {
  const calls: { op: Operation; body: Record<string, unknown> | undefined }[] = [];
  const api: ApiCall = async (op, body) => {
    calls.push({ op, body });
    return responses[op] ?? { ok: true, state: baseState };
  };
  return { api, calls };
}

describe('store', () => {
  test('the first seat starts the reservation and replaces the state', async () => {
    const withSeat: ApiState = {
      ...baseState,
      mine: [5],
      reservation: { email: null, standing: 0, seats: [{ id: 5, label: '1/5' }], expires_in: 300 },
    };
    const { api, calls } = fakeApi({ hold: { ok: true, state: withSeat } });
    const store = createStore(api, () => {});

    expect(await store.toggleSeat(5)).toBe(true);
    expect(calls).toEqual([{ op: 'hold', body: { seat_id: 5 } }]);
    expect(store.state.value?.mine).toEqual([5]);
    expect(store.message.value).toBeNull();
  });

  test('typing in the form extends the hold at most every 30 s', async () => {
    const { api, calls } = fakeApi({});
    let time = 100_000;
    const store = createStore(api, () => {}, () => time);
    store.state.value = { ...baseState, reservation: { email: null, standing: 0, seats: [], expires_in: 200 } };

    store.keepAlive();
    store.keepAlive();
    time += 31_000;
    store.state.value = { ...baseState, reservation: { email: null, standing: 0, seats: [], expires_in: 200 } };
    store.keepAlive();
    await Promise.resolve();

    expect(calls.map((c) => c.op)).toEqual(['extend', 'extend']);
  });

  test('shows server errors and keeps the returned state', async () => {
    const { api } = fakeApi({ hold: { ok: false, error: 'Toto místo už je obsazené.', state: { ...baseState, taken: [5] } } });
    const store = createStore(api, () => {});

    expect(await store.toggleSeat(5)).toBe(false);
    expect(store.message.value).toEqual({ text: 'Toto místo už je obsazené.', error: true });
    expect(store.state.value?.taken).toEqual([5]);
  });

  test('releases a seat that is already mine', async () => {
    const { api, calls } = fakeApi({});
    const store = createStore(api, () => {});
    store.state.value = { ...baseState, mine: [7] };

    await store.toggleSeat(7);
    expect(calls[0]?.op).toBe('release');
  });

  test('navigates after a confirmed reservation', async () => {
    const { api } = fakeApi({ confirm: { ok: true, state: baseState, redirect: '/hotovo/3' } });
    const visited: string[] = [];
    const store = createStore(api, (url) => visited.push(url));

    await store.confirm({ email: 'jana@example.com', name: 'Jana', phone: '', consent: true });
    expect(visited).toEqual(['/hotovo/3']);
  });

  test('ignores a second action while one is running', async () => {
    let release: () => void = () => {};
    const api: ApiCall = (op) =>
      new Promise((resolve) => {
        release = () => resolve({ ok: true, state: baseState });
      });
    const store = createStore(api, () => {});

    const first = store.setStanding(1);
    expect(await store.setStanding(2)).toBe(false);
    release();
    expect(await first).toBe(true);
  });
});

describe('api client', () => {
  test('sends POST with JSON body and CSRF header', async () => {
    let captured: { url: string; init: RequestInit } | null = null;
    const fetchStub = (async (url: string, init: RequestInit) => {
      captured = { url, init };
      return new Response(JSON.stringify({ ok: true, state: baseState }));
    }) as unknown as typeof fetch;

    const call = createApi('/api/__op__', 'token123', fetchStub);
    await call('hold', { seat_id: 1 });

    expect(captured!.url).toBe('/api/hold');
    expect(captured!.init.method).toBe('POST');
    expect((captured!.init.headers as Record<string, string>)['X-CSRF-Token']).toBe('token123');
    expect(captured!.init.body).toBe('{"seat_id":1}');
  });

  test('turns network failures into a user message', async () => {
    const failing = (async () => {
      throw new TypeError('offline');
    }) as unknown as typeof fetch;
    const result = await createApi('/api/__op__', 't', failing)('state');
    expect(result.ok).toBe(false);
  });
});
