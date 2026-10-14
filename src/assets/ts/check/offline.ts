// Offline mode of the entrance check: the same answers as app/Model/Ticket/TicketCheck.php, computed
// from a downloaded snapshot of all tickets plus the entries recorded on this phone. Pure functions
// (unit-tested in tests/ts/check-offline.test.ts); storage is in offline-store.ts.

import { extractCode } from './logic';
import type { CheckResponse, CheckResult, Stats, TicketView } from './types';

export interface SnapshotTicket extends TicketView {
  reservation: number | null;
  crew: { name: string; role: string } | null;
}

export interface SnapshotReservation {
  id: number;
  name: string;
  status: string;
  remaining: number;
  /** Why its tickets are not valid (cancelled, test ticket...), null = valid. */
  invalid: string | null;
}

/** GET api/snapshot */
export interface Snapshot {
  created_at: string;
  vs_prefix: string;
  testing: boolean;
  tickets: SnapshotTicket[];
  reservations: SnapshotReservation[];
}

/** An entry recorded on this phone while offline, uploaded later (api/sync). */
export interface OfflineEntry {
  code: string;
  action: 'use' | 'undo';
  /** Local time "Y-m-d H:i:s". */
  at: string;
}

export interface OfflineData {
  snapshot: Snapshot;
  entries: OfflineEntry[];
  /** When the snapshot was downloaded (ms). */
  downloadedAt: number;
}

/** Downloaded data are deleted from the phone after this time (names of guests). */
export const KeepMs = 3 * 24 * 3600 * 1000;
export const OfflineBy = 'tento telefon (offline)';

export function isExpired(data: OfflineData, now: number): boolean {
  return now - data.downloadedAt > KeepMs;
}

/** "2026-02-07 18:30:05" in local time (the format of the server). */
export function localTime(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** Tickets with the entries of this phone applied. */
export function currentTickets(data: OfflineData): SnapshotTicket[] {
  const tickets = new Map(data.snapshot.tickets.map((t) => [t.code, { ...t }]));
  for (const entry of data.entries) {
    const ticket = tickets.get(entry.code);
    if (ticket) {
      ticket.used_at = entry.action === 'use' ? entry.at : null;
      ticket.used_by = entry.action === 'use' ? OfflineBy : null;
    }
  }
  return [...tickets.values()];
}

export function statsOffline(data: OfflineData): Stats {
  const reservations = reservationMap(data);
  const valid = currentTickets(data).filter((t) => t.reservation === null || reservations.get(t.reservation)?.invalid === null);
  return { used: valid.filter((t) => t.used_at !== null).length, total: valid.length };
}

/** What a scanned or typed code (or a reservation number / variable symbol) means. */
export function lookupOffline(data: OfflineData, input: string): CheckResponse {
  const tickets = currentTickets(data);
  const code = extractCode(input);
  const ticket = code ? tickets.find((t) => t.code === code) : undefined;
  if (ticket) {
    return respond(data, result(data, tickets, ticket, null));
  }
  const digits = input.trim();
  if (/^\d+$/.test(digits)) {
    const id = reservationId(data.snapshot.vs_prefix, digits) ?? Number(digits);
    const reservation = reservationMap(data).get(id);
    return reservation
      ? respond(data, result(data, tickets, null, reservation))
      : respond(data, invalid('Rezervace s tímto číslem neexistuje.'));
  }
  return respond(data, invalid(code === null ? 'Tohle není kód vstupenky (6 znaků 0–9 a A–F).' : 'Vstupenka s tímto kódem neexistuje.'));
}

/** Records the entry of one ticket on this phone. */
export function useOffline(data: OfflineData, code: string, now: Date): [OfflineData, CheckResponse] {
  const ticket = currentTickets(data).find((t) => t.code === code);
  if (!ticket) {
    return [data, { ok: false, error: 'Vstupenka s tímto kódem neexistuje.' }];
  }
  const problem = problemOf(data, ticket);
  if (problem) {
    return [data, { ok: false, error: problem }];
  }
  if (ticket.used_at !== null) {
    return [data, { ok: false, error: 'Vstupenka už byla mezitím použita.' }];
  }
  const next = withEntries(data, [{ code, action: 'use', at: localTime(now) }]);
  return [next, lookupOffline(next, code)];
}

/** Records the entry of all unused tickets of a reservation (a group arriving together). */
export function useReservationOffline(data: OfflineData, reservationId: number, now: Date): [OfflineData, CheckResponse] {
  const unused = currentTickets(data).filter((t) => t.reservation === reservationId && t.used_at === null);
  const first = unused[0];
  if (!first) {
    return [data, { ok: false, error: 'Rezervace nemá žádné nepoužité vstupenky.' }];
  }
  const problem = problemOf(data, first);
  if (problem) {
    return [data, { ok: false, error: problem }];
  }
  const at = localTime(now);
  const next = withEntries(data, unused.map((t) => ({ code: t.code, action: 'use' as const, at })));
  const reservation = reservationMap(next).get(reservationId);
  return [next, reservation ? respond(next, result(next, currentTickets(next), null, reservation)) : lookupOffline(next, first.code)];
}

/** Takes an entry back (scanned by mistake). */
export function undoOffline(data: OfflineData, code: string, now: Date): [OfflineData, CheckResponse] {
  const ticket = currentTickets(data).find((t) => t.code === code);
  if (!ticket) {
    return [data, { ok: false, error: 'Vstupenka s tímto kódem neexistuje.' }];
  }
  if (ticket.used_at === null) {
    return [data, { ok: false, error: 'Vstupenka ještě nebyla použita.' }];
  }
  const next = withEntries(data, [{ code, action: 'undo', at: localTime(now) }]);
  return [next, lookupOffline(next, code)];
}

/** Variable symbol = prefix + reservation id padded to 4 digits (app/Model/Payment/VariableSymbol.php). */
export function reservationId(prefix: string, variableSymbol: string): number | null {
  const vs = variableSymbol.trim().replace(/^0+/, '');
  if (vs === '' || !/^\d+$/.test(vs) || !vs.startsWith(prefix)) {
    return null;
  }
  const id = vs.slice(prefix.length);
  return id.length >= 4 && Number(id) > 0 ? Number(id) : null;
}

function withEntries(data: OfflineData, entries: OfflineEntry[]): OfflineData {
  return { ...data, entries: [...data.entries, ...entries] };
}

function reservationMap(data: OfflineData): Map<number, SnapshotReservation> {
  return new Map(data.snapshot.reservations.map((r) => [r.id, r]));
}

function problemOf(data: OfflineData, ticket: SnapshotTicket): string | null {
  if (ticket.reservation === null) {
    return null; // a Z-lístek is valid in every stage
  }
  const reservation = reservationMap(data).get(ticket.reservation);
  return reservation ? reservation.invalid : 'Vstupenka nepatří k žádné rezervaci.';
}

function respond(data: OfflineData, checkResult: CheckResult): CheckResponse {
  return { ok: true, result: checkResult, stats: statsOffline(data) };
}

function view(ticket: SnapshotTicket): TicketView {
  return { code: ticket.code, kind: ticket.kind, seat: ticket.seat, used_at: ticket.used_at, used_by: ticket.used_by };
}

function result(
  data: OfflineData,
  tickets: SnapshotTicket[],
  ticket: SnapshotTicket | null,
  reservationArg: SnapshotReservation | null,
): CheckResult {
  if (ticket && ticket.crew) {
    const used = ticket.used_at !== null;
    return {
      status: used ? 'used' : 'ok',
      message: used ? 'Z-lístek už byl použit.' : 'Z-lístek je v pořádku.',
      ticket: view(ticket),
      reservation: null,
      crew: ticket.crew,
      tickets: [view(ticket)],
    };
  }
  const reservation = reservationArg ?? (ticket?.reservation != null ? reservationMap(data).get(ticket.reservation) ?? null : null);
  if (!reservation) {
    return invalid('Vstupenka nepatří k žádné rezervaci.');
  }
  const own = tickets.filter((t) => t.reservation === reservation.id).map(view);
  const unused = own.filter((t) => t.used_at === null).length;
  const [status, message]: [CheckResult['status'], string] =
    reservation.invalid !== null
      ? ['invalid', reservation.invalid]
      : ticket && ticket.used_at !== null
        ? ['used', 'Vstupenka už byla použita.']
        : !ticket && unused === 0
          ? ['used', 'Všechny vstupenky rezervace už byly použity.']
          : reservation.remaining > 0
            ? ['unpaid', `Nezaplaceno – zbývá doplatit ${reservation.remaining} Kč.`]
            : ['ok', ticket ? 'Vstupenka je v pořádku.' : 'Rezervace je zaplacená.'];
  return {
    status,
    message,
    ticket: ticket ? view(ticket) : null,
    reservation: { id: reservation.id, name: reservation.name, status: reservation.status, remaining: reservation.remaining, unused },
    crew: null,
    tickets: own,
  };
}

function invalid(message: string): CheckResult {
  return { status: 'invalid', message, ticket: null, reservation: null, crew: null, tickets: [] };
}
