// Client of the entrance check JSON API (app/Presentation/Admin/Check/CheckPresenter.php).

import type { CheckResponse } from './types';

export interface CheckApi {
  lookup(input: string): Promise<CheckResponse>;
  use(code: string): Promise<CheckResponse>;
  useReservation(reservation: number): Promise<CheckResponse>;
  undo(code: string): Promise<CheckResponse>;
}

const networkError: CheckResponse = {
  ok: false,
  error: 'Nepodařilo se spojit se serverem. Zkontrolujte připojení a zkuste to znovu.',
};

export function createCheckApi(urlTemplate: string, csrfToken: string, fetchImpl: typeof fetch = fetch): CheckApi {
  const call = async (op: string, init: RequestInit, query = ''): Promise<CheckResponse> => {
    try {
      const response = await fetchImpl(urlTemplate.replace('__op__', op) + query, { credentials: 'same-origin', ...init });
      return (await response.json()) as CheckResponse;
    } catch {
      return networkError;
    }
  };
  const post = (op: string, body: Record<string, unknown>) =>
    call(op, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body),
    });

  return {
    lookup: (input) => call('lookup', { method: 'GET' }, `?code=${encodeURIComponent(input)}`),
    use: (code) => post('use', { code }),
    useReservation: (reservation) => post('useReservation', { reservation }),
    undo: (code) => post('undo', { code }),
  };
}
