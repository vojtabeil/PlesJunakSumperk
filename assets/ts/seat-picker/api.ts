// Client of the seat picker JSON API (app/Presentation/Front/Api/ApiPresenter.php).

import type { ApiResponse, Operation } from './types';

export type ApiCall = (op: Operation, body?: Record<string, unknown>) => Promise<ApiResponse>;

const networkError: ApiResponse = {
  ok: false,
  error: 'Nepodařilo se spojit se serverem. Zkontrolujte připojení.',
};

/** GET when there is no body, otherwise POST with JSON and the CSRF header. */
export function createApi(urlTemplate: string, csrfToken: string, fetchImpl: typeof fetch = fetch): ApiCall {
  return async (op, body) => {
    const init: RequestInit =
      body === undefined
        ? { method: 'GET' }
        : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(body),
          };
    try {
      const response = await fetchImpl(urlTemplate.replace('__op__', op), {
        credentials: 'same-origin',
        ...init,
      });
      return (await response.json()) as ApiResponse;
    } catch {
      return networkError;
    }
  };
}
