// The seat picker API without a server, for the static demo (dev\demo.cmd): the same operations and the
// same rules as app/Model/Reservation/ReservationService (limit per reservation, standing capacity, seat
// hold with a countdown), the visitor's choice kept in the browser. Nothing is sent anywhere.

import type { ApiCall } from './api';
import type { ApiResponse, ApiState, HallSeat, Operation, ReservationState } from './types';

/** Printed into the demo page (<script id="seat-picker-demo">) by bin/export-demo.php. */
export interface DemoConfig {
  /** Seats "sold to other people". */
  taken: number[];
  maxTickets: number;
  standingLeft: number;
  holdSeconds: number;
  prices: { seat: number; standing: number };
  /** The static "Moje rezervace" page shown after confirming. */
  redirect: string;
}

/** The visitor's choice as stored in the browser. */
interface Draft {
  seats: number[];
  standing: number;
  email: string | null;
  /** When the seats were held or extended last (ms). */
  heldAt: number;
}

export interface KeyValueStore {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

const StorageKey = 'ples-demo-draft';
const EmailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const PhonePattern = /^\+?[0-9 ]{9,20}$/;

export function createDemoApi(
  seats: HallSeat[],
  config: DemoConfig,
  storage: KeyValueStore | null,
  now: () => number = Date.now,
): ApiCall {
  const labels = new Map(seats.map((seat) => [seat.id, seat.label]));
  // The layout lists the seats in hall order (A1/1, A1/2, ..., A2/1); ids say nothing about it.
  const order = new Map(seats.map((seat, index) => [seat.id, index]));
  const taken = new Set(config.taken);
  let memory: Draft | null = null;

  const load = (): Draft | null => {
    try {
      const text = storage?.getItem(StorageKey);
      memory = text ? (JSON.parse(text) as Draft) : memory;
    } catch {
      // private window, blocked storage: keep the choice in memory only
    }
    return memory;
  };
  const save = (draft: Draft | null): void => {
    memory = draft;
    try {
      if (draft) {
        storage?.setItem(StorageKey, JSON.stringify(draft));
      } else {
        storage?.removeItem(StorageKey);
      }
    } catch {
      // see load()
    }
  };

  /** The draft with expired seat holds released (standing tickets stay, as on the server). */
  const current = (): Draft | null => {
    const draft = load();
    if (draft && draft.seats.length > 0 && secondsLeft(draft) <= 0) {
      draft.seats = [];
      save(draft);
    }
    return draft;
  };
  const secondsLeft = (draft: Draft): number => Math.ceil(config.holdSeconds - (now() - draft.heldAt) / 1000);

  const state = (): ApiState => {
    const draft = current();
    const active = draft && (draft.seats.length > 0 || draft.standing > 0 || draft.email !== null);
    const reservation: ReservationState | null = active
      ? {
          email: draft.email,
          standing: draft.standing,
          seats: [...draft.seats].sort((a, b) => (order.get(a) ?? a) - (order.get(b) ?? b)).map((id) => ({ id, label: labels.get(id) ?? String(id) })),
          expires_in: draft.seats.length > 0 ? Math.max(0, secondsLeft(draft)) : null,
        }
      : null;
    return {
      sale_open: true,
      reservation,
      taken: [...taken].filter((id) => !draft?.seats.includes(id)),
      mine: draft ? [...draft.seats] : [],
      limits: { max_tickets: config.maxTickets, standing_left: config.standingLeft, hold_seconds: config.holdSeconds },
      prices: config.prices,
    };
  };

  const fail = (error: string): ApiResponse => ({ ok: false, error, state: state() });
  const draftOrNew = (): Draft => current() ?? { seats: [], standing: 0, email: null, heldAt: now() };
  const tickets = (draft: Draft): number => draft.seats.length + draft.standing;

  const operations: Record<Operation, (body: Record<string, unknown>) => ApiResponse> = {
    state: () => ({ ok: true, state: state() }),
    hold: (body) => {
      const id = Number(body['seat_id']);
      const draft = draftOrNew();
      if (!labels.has(id) || taken.has(id)) {
        return fail('Toto místo už je obsazené.');
      }
      if (!draft.seats.includes(id)) {
        if (tickets(draft) + 1 > config.maxTickets) {
          return fail(`Na jednu rezervaci lze koupit nejvýše ${config.maxTickets} lístků.`);
        }
        draft.seats.push(id);
      }
      draft.heldAt = now();
      save(draft);
      return { ok: true, state: state() };
    },
    release: (body) => {
      const draft = current();
      if (draft) {
        draft.seats = draft.seats.filter((id) => id !== Number(body['seat_id']));
        save(draft);
      }
      return { ok: true, state: state() };
    },
    standing: (body) => {
      const count = Number(body['count']);
      const draft = draftOrNew();
      if (!Number.isInteger(count) || count < 0) {
        return fail('Neplatný počet lístků.');
      }
      if (draft.seats.length + count > config.maxTickets) {
        return fail(`Na jednu rezervaci lze koupit nejvýše ${config.maxTickets} lístků.`);
      }
      if (count > config.standingLeft) {
        return fail('Lístky bez místenky jsou vyprodané.');
      }
      draft.standing = count;
      save(draft);
      return { ok: true, state: state() };
    },
    extend: () => {
      const draft = current();
      if (draft && draft.seats.length > 0) {
        draft.heldAt = now();
        save(draft);
      }
      return { ok: true, state: state() };
    },
    confirm: (body) => {
      const draft = current();
      const name = String(body['name'] ?? '').trim().replace(/\s+/g, ' ');
      const email = String(body['email'] ?? '').trim();
      const phone = String(body['phone'] ?? '').trim();
      if (!draft || tickets(draft) === 0) {
        return fail('Vyberte alespoň jeden lístek. Pokud jste místa vybrali dříve, mohla vypršet.');
      }
      if (!EmailPattern.test(email)) {
        return fail('Zadejte e-mail ve tvaru jmeno@example.cz.');
      }
      if (name.length < 3 || name.length > 255) {
        return fail('Vyplňte jméno a příjmení.');
      }
      if (phone !== '' && !PhonePattern.test(phone)) {
        return fail('Telefon zadejte jen jako čísla, např. +420 777 123 456.');
      }
      if (body['consent'] !== true) {
        return fail('Pro rezervaci je potřeba souhlas se zpracováním osobních údajů.');
      }
      save(null); // the demo "reservation" is done: the seats are free again
      return { ok: true, state: state(), redirect: config.redirect };
    },
    cancel: () => {
      save(null);
      return { ok: true, state: state() };
    },
  };

  return async (op, body) => operations[op](body ?? {});
}
