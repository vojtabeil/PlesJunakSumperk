// The offline data of the entrance check in the phone (IndexedDB): the downloaded snapshot and the
// entries recorded offline. One record; deleted by hand or 3 days after the download (offline.ts).

import type { OfflineData } from './offline';

const DbName = 'ples-kontrola';
const StoreName = 'offline';
const Key = 'data';

export interface OfflineStore {
  load(): Promise<OfflineData | null>;
  save(data: OfflineData): Promise<void>;
  clear(): Promise<void>;
}

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DbName, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(StoreName);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('IndexedDB'));
  });
}

async function run<T>(mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await open();
  try {
    return await new Promise<T>((resolve, reject) => {
      const request = work(db.transaction(StoreName, mode).objectStore(StoreName));
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('IndexedDB'));
    });
  } finally {
    db.close();
  }
}

export function createOfflineStore(): OfflineStore {
  return {
    load: async () => ((await run('readonly', (store) => store.get(Key))) as OfflineData | undefined) ?? null,
    save: async (data) => {
      await run('readwrite', (store) => store.put(data, Key));
    },
    clear: async () => {
      await run('readwrite', (store) => store.delete(Key));
    },
  };
}
