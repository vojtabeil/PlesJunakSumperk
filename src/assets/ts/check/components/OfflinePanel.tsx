import { useState } from 'preact/hooks';
import { plural } from '../../seat-picker/logic';
import { formatTime } from '../logic';
import type { OfflineData } from '../offline';
import type { SyncResult } from '../types';

interface Props {
  data: OfflineData | null;
  offline: boolean;
  busy: boolean;
  syncResult: SyncResult | null;
  onDownload(): void;
  onToggle(): void;
  onSync(): void;
  onDelete(): void;
  onClose(): void;
}

/**
 * Offline mode: download all tickets to the phone before the ball, switch to working from them (by hand),
 * upload the entries recorded offline when the signal is back, delete the data (names of guests).
 */
export function OfflinePanel({ data, offline, busy, syncResult, onDownload, onToggle, onSync, onDelete, onClose }: Props) {
  const [askDelete, setAskDelete] = useState(false);
  const pending = data?.entries.length ?? 0;

  return (
    <section class="check-card check-card--panel" role="dialog" aria-modal="true" aria-labelledby="offline-title">
      <div class="check-card-head">
        <h1 id="offline-title" class="check-card-title">Offline režim</h1>
        <button type="button" class="check-card-close" onClick={onClose} aria-label="Zavřít" title="Zavřít">
          ✕
        </button>
      </div>

      {data ? (
        <p class="check-card-message">
          V telefonu jsou data z {formatTime(data.snapshot.created_at)} ({data.snapshot.tickets.length}{' '}
          {plural(data.snapshot.tickets.length, 'vstupenka', 'vstupenky', 'vstupenek')}).
          {pending > 0 && ` Offline vstupů k nahrání: ${pending}.`} Smažou se samy za 3 dny.
        </p>
      ) : (
        <p class="check-card-message">
          Před plesem stáhněte data všech vstupenek do telefonu. Pak můžete kontrolovat i bez signálu.
        </p>
      )}

      {syncResult && (
        <div class="check-sync">
          <p>
            Nahráno vstupů: <strong>{syncResult.applied}</strong>
            {syncResult.skipped > 0 && ` · už dřív nahráno: ${syncResult.skipped}`}
          </p>
          {syncResult.conflicts.length > 0 && (
            <>
              <p class="check-card-due">Použito dvakrát ({syncResult.conflicts.length}):</p>
              <ul>
                {syncResult.conflicts.map((c) => (
                  <li key={c.code}>
                    <span class="check-code">{c.code}</span> {c.message}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}

      <div class="check-card-actions">
        {data && (
          <button type="button" class="check-button check-button--primary" disabled={busy} onClick={onToggle}>
            {offline ? 'Přepnout na online' : 'Pracovat offline'}
          </button>
        )}
        {data && pending > 0 && !offline && (
          <button type="button" class="check-button" disabled={busy} onClick={onSync}>
            Nahrát vstupy na server ({pending})
          </button>
        )}
        {!offline && (
          <button type="button" class="check-button" disabled={busy} onClick={onDownload}>
            {data ? 'Stáhnout data znovu' : 'Stáhnout data do telefonu'}
          </button>
        )}
        {data && !askDelete && (
          <button type="button" class="check-button check-button--quiet" disabled={busy} onClick={() => setAskDelete(true)}>
            Smazat offline data
          </button>
        )}
        {data && askDelete && (
          <div class="check-confirm" role="group" aria-label="Smazat offline data">
            <p>
              Opravdu smazat data z telefonu?
              {pending > 0 && ` ${pending} offline vstupů ještě není nahraných na server a ztratí se.`}
            </p>
            <button type="button" class="check-button" disabled={busy} onClick={() => { setAskDelete(false); onDelete(); }}>
              Ano, smazat
            </button>
            <button type="button" class="check-button check-button--quiet" onClick={() => setAskDelete(false)}>
              Ne
            </button>
          </div>
        )}
      </div>
    </section>
  );
}
