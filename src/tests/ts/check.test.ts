import { describe, expect, test } from 'bun:test';
import { createCheckApi } from '../../assets/ts/check/api';
import { extractCode, formatTime, isLookupInput, ticketPlace } from '../../assets/ts/check/logic';

describe('extractCode', () => {
  test('reads the code from the QR link', () => {
    expect(extractCode('https://ples.example.cz/admin/kontrola/a3f09c')).toBe('A3F09C');
    expect(extractCode('http://127.0.0.1:8000/admin/kontrola/A3F09C?x=1')).toBe('A3F09C');
  });

  test('accepts a code on its own, typed in any way', () => {
    expect(extractCode('A3F09C')).toBe('A3F09C');
    expect(extractCode(' a3f-09c ')).toBe('A3F09C');
    expect(extractCode('AOOOOO')).toBe('A00000');
  });

  test('rejects other QR codes', () => {
    expect(extractCode('SPD*1.0*ACC:CZ6508000000192000145399*AM:600.00')).toBeNull();
    expect(extractCode('https://example.com/admin/kontrola/A3F09')).toBeNull();
    expect(extractCode('A3F09G')).toBeNull();
    expect(extractCode('')).toBeNull();
  });
});

test('isLookupInput accepts a code or a reservation number', () => {
  expect(isLookupInput('a3f09c')).toBe(true);
  expect(isLookupInput('12')).toBe(true);
  expect(isLookupInput('20260012')).toBe(true);
  expect(isLookupInput('Novák')).toBe(false);
  expect(isLookupInput('')).toBe(false);
});

test('ticketPlace', () => {
  expect(ticketPlace({ code: 'A3F09C', kind: 'seat', seat: '3/5', used_at: null, used_by: null })).toBe('Stůl 3 · místo 5');
  expect(ticketPlace({ code: 'A3F09C', kind: 'standing', seat: null, used_at: null, used_by: null })).toBe('Na stání');
  expect(ticketPlace({ code: 'A3F09C', kind: 'crew', seat: null, used_at: null, used_by: null })).toBe('Z-lístek');
});

test('formatTime shows the date only for another day', () => {
  const now = new Date(2027, 1, 6, 22, 0);
  expect(formatTime('2027-02-06 20:05:00', now)).toBe('20:05');
  expect(formatTime('2027-02-05 09:30:00', now)).toBe('5. 2. 9:30');
  expect(formatTime('nonsense', now)).toBe('nonsense');
});

test('API client: lookup is a GET, changes are POSTs with the CSRF header', async () => {
  const calls: { url: string; init: RequestInit }[] = [];
  const fakeFetch = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return new Response(JSON.stringify({ ok: false, error: 'x' }));
  }) as unknown as typeof fetch;
  const api = createCheckApi('/admin/kontrola/api/__op__', 'secret', fakeFetch);

  await api.lookup('A3F 09C');
  await api.use('A3F09C');
  await api.useReservation(12);

  expect(calls[0]?.url).toBe('/admin/kontrola/api/lookup?code=A3F%2009C');
  expect(calls[0]?.init.method).toBe('GET');
  expect(calls[1]?.url).toBe('/admin/kontrola/api/use');
  expect(calls[1]?.init.body).toBe('{"code":"A3F09C"}');
  expect((calls[1]?.init.headers as Record<string, string>)['X-CSRF-Token']).toBe('secret');
  expect(calls[2]?.init.body).toBe('{"reservation":12}');
});

test('API client reports a network error', async () => {
  const failing = (async () => {
    throw new Error('offline');
  }) as unknown as typeof fetch;
  const result = await createCheckApi('/x/__op__', 't', failing).lookup('A3F09C');
  expect(result.ok).toBe(false);
});
