// Client of the entrance check JSON API (app/Presentation/Admin/Check/CheckPresenter.php).

import type { OfflineEntry } from './offline';
import type { CheckResponse, SnapshotResponse, SyncResponse } from './types';

export interface CheckApi {
  lookup(input: string): Promise<CheckResponse>;
  use(code: string): Promise<CheckResponse>;
  useReservation(reservation: number): Promise<CheckResponse>;
  undo(code: string): Promise<CheckResponse>;
}

/** The server part of the offline mode: download all tickets, upload the entries recorded offline. */
export interface OfflineSyncApi {
  snapshot(): Promise<SnapshotResponse>;
  sync(entries: OfflineEntry[]): Promise<SyncResponse>;
}

const networkError: CheckResponse = {
  ok: false,
  error: 'Nepodařilo se spojit se serverem. Zkontrolujte připojení a zkuste to znovu.',
};

export function createCheckApi(urlTemplate: string, csrfToken: string, fetchImpl: typeof fetch = fetch): CheckApi & OfflineSyncApi {
  const call = async <T = CheckResponse>(op: string, init: RequestInit, query = ''): Promise<T> => {
    try {
      const response = await fetchImpl(urlTemplate.replace('__op__', op) + query, { credentials: 'same-origin', ...init });
      return (await response.json()) as T;
    } catch {
      return networkError as T;
    }
  };
  const post = <T = CheckResponse>(op: string, body: Record<string, unknown>) =>
    call<T>(op, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body),
    });

  return {
    lookup: (input) => call('lookup', { method: 'GET' }, `?code=${encodeURIComponent(input)}`),
    use: (code) => post('use', { code }),
    useReservation: (reservation) => post('useReservation', { reservation }),
    undo: (code) => post('undo', { code }),
    snapshot: () => call<SnapshotResponse>('snapshot', { method: 'GET', cache: 'no-store' }),
    sync: (entries) => post<SyncResponse>('sync', { entries }),
  };
}
