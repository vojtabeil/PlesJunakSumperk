// State of the seat picker. The server is the source of truth: every action sends a request
// and the returned state replaces the local one.

import { signal } from '@preact/signals';
import type { ApiCall } from './api';
import type { ApiState, Operation } from './types';

export interface Message {
  text: string;
  error: boolean;
}

export interface ConfirmInput {
  email: string;
  name: string;
  phone: string;
  consent: boolean;
}

/** Typing in the form extends the hold at most this often. */
const ExtendEveryMs = 30_000;

export type Store = ReturnType<typeof createStore>;

export function createStore(api: ApiCall, navigate: (url: string) => void, now: () => number = Date.now) {
  const state = signal<ApiState | null>(null);
  const busy = signal(false);
  const message = signal<Message | null>(null);
  let lastExtend = 0;

  function show(text: string, error = false): void {
    message.value = text ? { text, error } : null;
  }

  async function run(op: Operation, body: Record<string, unknown>, success = ''): Promise<boolean> {
    if (busy.value) {
      return false;
    }
    busy.value = true;
    try {
      const result = await api(op, body);
      if (result.state) {
        state.value = result.state;
      }
      if (!result.ok) {
        show(result.error, true);
        return false;
      }
      show(success);
      if (result.redirect) {
        navigate(result.redirect);
      }
      return true;
    } finally {
      busy.value = false;
    }
  }

  async function refresh(): Promise<void> {
    if (busy.value) {
      return;
    }
    const result = await api('state');
    if (result.state) {
      state.value = result.state;
    }
  }

  /** Holds the chosen seats for another period; quietly, without blocking the page. */
  async function extend(): Promise<void> {
    lastExtend = now();
    const result = await api('extend', {});
    if (result.state) {
      state.value = result.state;
    }
  }

  return {
    state,
    busy,
    message,
    show,
    refresh,
    extend,
    /** The visitor is typing in the form: keep the seats held (at most every 30 s). */
    keepAlive: () => {
      if (state.value?.reservation?.expires_in !== null && now() - lastExtend >= ExtendEveryMs) {
        void extend();
      }
    },
    toggleSeat: (seatId: number) =>
      run(state.value?.mine.includes(seatId) ? 'release' : 'hold', { seat_id: seatId }),
    setStanding: (count: number) => run('standing', { count }),
    confirm: (input: ConfirmInput) => run('confirm', { ...input }),
    cancel: () => run('cancel', {}, 'Výběr jsme zrušili, místa jsou opět volná.'),
    holdExpired: async () => {
      show('Čas na dokončení vypršel a vybraná místa jsme uvolnili. Vyberte je prosím znovu.', true);
      await refresh();
    },
  };
}
