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
  name: string;
  phone: string;
  consent: boolean;
}

export type Store = ReturnType<typeof createStore>;

export function createStore(api: ApiCall, navigate: (url: string) => void) {
  const state = signal<ApiState | null>(null);
  const busy = signal(false);
  const message = signal<Message | null>(null);

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

  return {
    state,
    busy,
    message,
    show,
    refresh,
    start: (email: string) => run('start', { email }, 'E-mail ověřen. Vyberte místa nebo lístky bez místenky.'),
    toggleSeat: (seatId: number) =>
      run(state.value?.mine.includes(seatId) ? 'release' : 'hold', { seat_id: seatId }),
    setStanding: (count: number) => run('standing', { count }),
    confirm: (input: ConfirmInput) => run('confirm', { ...input }),
    cancel: () => run('cancel', {}, 'Rezervace byla zrušena, můžete zadat jiný e-mail.'),
    holdExpired: async () => {
      show('Čas na rezervaci vypršel, vybraná místa byla uvolněna.', true);
      await refresh();
    },
  };
}
