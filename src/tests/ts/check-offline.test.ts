import { describe, expect, test } from 'bun:test';
import {
  isExpired,
  KeepMs,
  localTime,
  lookupOffline,
  reservationId,
  statsOffline,
  undoOffline,
  useOffline,
  useReservationOffline,
  type OfflineData,
  type Snapshot,
} from '../../assets/ts/check/offline';

const snapshot: Snapshot = {
  created_at: '2027-02-06 18:00:00',
  vs_prefix: '2027',
  testing: false,
  tickets: [
    { code: 'A00001', kind: 'seat', seat: '3/5', used_at: null, used_by: null, reservation: 1, crew: null },
    { code: 'A00002', kind: 'standing', seat: null, used_at: null, used_by: null, reservation: 1, crew: null },
    { code: 'B00001', kind: 'seat', seat: '4/1', used_at: '2027-02-06 19:00:00', used_by: 'Pepa', reservation: 2, crew: null },
    { code: 'C00001', kind: 'seat', seat: '5/1', used_at: null, used_by: null, reservation: 3, crew: null },
    { code: 'D00001', kind: 'crew', seat: null, used_at: null, used_by: null, reservation: null, crew: { name: 'Jana', role: 'Bar' } },
  ],
  reservations: [
    { id: 1, name: 'Paid Guest', status: 'paid', remaining: 0, invalid: null },
    { id: 2, name: 'Unpaid Guest', status: 'confirmed', remaining: 350, invalid: null },
    { id: 3, name: 'Cancelled', status: 'cancelled', remaining: 350, invalid: 'Rezervace byla zrušena, vstupenka neplatí.' },
  ],
};
const data: OfflineData = { snapshot, entries: [], downloadedAt: 0 };
const now = new Date(2027, 1, 6, 20, 15, 0);

describe('offline lookup', () => {
  test('answers like the server', () => {
    expect(lookupOffline(data, 'a00001')).toMatchObject({ ok: true, result: { status: 'ok', message: 'Vstupenka je v pořádku.' } });
    expect(lookupOffline(data, 'B00001')).toMatchObject({ result: { status: 'used' } });
    expect(lookupOffline(data, 'C00001')).toMatchObject({ result: { status: 'invalid', message: 'Rezervace byla zrušena, vstupenka neplatí.' } });
    expect(lookupOffline(data, 'D00001')).toMatchObject({ result: { status: 'ok', crew: { name: 'Jana', role: 'Bar' } } });
    expect(lookupOffline(data, 'FFFFFF')).toMatchObject({ result: { status: 'invalid', message: 'Vstupenka s tímto kódem neexistuje.' } });
    // By variable symbol.
    expect(lookupOffline(data, '20270002')).toMatchObject({ result: { status: 'used', reservation: { id: 2 } } });
    expect(lookupOffline(data, '1')).toMatchObject({ result: { status: 'ok', message: 'Rezervace je zaplacená.', reservation: { unused: 2 } } });
    expect(lookupOffline(data, '99')).toMatchObject({ result: { message: 'Rezervace s tímto číslem neexistuje.' } });
  });

  test('records entries on the phone, refuses invalid and used tickets', () => {
    const [afterUse, used] = useOffline(data, 'A00001', now);
    expect(used).toMatchObject({ ok: true, result: { status: 'used', ticket: { used_at: '2027-02-06 20:15:00' } } });
    expect(afterUse.entries).toEqual([{ code: 'A00001', action: 'use', at: '2027-02-06 20:15:00' }]);
    expect(useOffline(afterUse, 'A00001', now)[1]).toEqual({ ok: false, error: 'Vstupenka už byla mezitím použita.' });
    expect(useOffline(data, 'C00001', now)[1]).toMatchObject({ ok: false });

    const [afterUndo, undone] = undoOffline(afterUse, 'A00001', now);
    expect(undone).toMatchObject({ result: { status: 'ok' } });
    expect(afterUndo.entries.map((e) => e.action)).toEqual(['use', 'undo']);
    expect(undoOffline(data, 'A00001', now)[1]).toEqual({ ok: false, error: 'Vstupenka ještě nebyla použita.' });
  });

  test('lets a whole reservation in and counts only valid tickets', () => {
    expect(statsOffline(data)).toEqual({ used: 1, total: 4 });
    const [next, response] = useReservationOffline(data, 1, now);
    expect(response).toMatchObject({ ok: true, result: { status: 'used', message: 'Všechny vstupenky rezervace už byly použity.' } });
    expect(next.entries).toHaveLength(2);
    expect(statsOffline(next)).toEqual({ used: 3, total: 4 });
    expect(useReservationOffline(next, 1, now)[1]).toEqual({ ok: false, error: 'Rezervace nemá žádné nepoužité vstupenky.' });
  });

  test('expires after three days and formats local time', () => {
    expect(isExpired({ ...data, downloadedAt: 1000 }, 1000 + KeepMs)).toBe(false);
    expect(isExpired({ ...data, downloadedAt: 1000 }, 1001 + KeepMs)).toBe(true);
    expect(localTime(new Date(2027, 0, 2, 3, 4, 5))).toBe('2027-01-02 03:04:05');
    expect(reservationId('2027', '0020270003')).toBe(3);
    expect(reservationId('2027', '3')).toBeNull();
  });
});
