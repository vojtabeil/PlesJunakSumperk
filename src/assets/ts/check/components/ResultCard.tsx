import { useEffect, useRef, useState } from 'preact/hooks';
import { formatPrice, plural } from '../../seat-picker/logic';
import { formatTime, StatusTitles, ticketPlace } from '../logic';
import type { CheckResult } from '../types';

interface Props {
  result: CheckResult;
  /** The entry was just recorded by this phone. */
  justUsed: boolean;
  busy: boolean;
  onUse(code: string): void;
  onUseReservation(reservationId: number): void;
  onUndo(code: string): void;
  onClose(): void;
}

/**
 * What the scanned code means, in the colour of its status, with the actions that make sense:
 * confirm the entry, confirm the rest of the reservation, take an entry back (asks first), next.
 */
export function ResultCard({ result, justUsed, busy, onUse, onUseReservation, onUndo, onClose }: Props) {
  const [askUndo, setAskUndo] = useState(false);
  const titleRef = useRef<HTMLHeadingElement>(null);
  const { ticket, reservation, tickets } = result;

  useEffect(() => {
    setAskUndo(false);
    titleRef.current?.focus();
  }, [result]);

  const status = justUsed ? 'ok' : result.status;
  const title = justUsed ? 'Vstup zapsán' : StatusTitles[result.status];
  const canEnter = result.status === 'ok' || result.status === 'unpaid';
  const ticketUnused = ticket !== null && ticket.used_at === null;
  const others = reservation ? reservation.unused - (ticketUnused ? 1 : 0) : 0;

  return (
    <section class={`check-card check-card--${status}`} role="dialog" aria-modal="true" aria-labelledby="check-title">
      <h1 id="check-title" class="check-card-title" tabIndex={-1} ref={titleRef}>
        {title}
      </h1>
      {!justUsed && <p class="check-card-message">{result.message}</p>}

      {ticket && (
        <p class="check-card-place">
          {ticketPlace(ticket)}
          <span class="check-code">{ticket.code}</span>
        </p>
      )}
      {ticket?.used_at && (
        <p class={justUsed ? 'check-card-entered' : 'check-card-used'}>
          Vstup {formatTime(ticket.used_at)}
          {ticket.used_by ? ` · ${ticket.used_by}` : ''}
        </p>
      )}

      {reservation && (
        <dl class="check-card-facts">
          <dt>Jméno</dt>
          <dd>{reservation.name}</dd>
          <dt>Rezervace</dt>
          <dd>č. {reservation.id}</dd>
          {reservation.remaining > 0 && (
            <>
              <dt>Doplatit</dt>
              <dd class="check-card-due">{formatPrice(reservation.remaining)}</dd>
            </>
          )}
        </dl>
      )}

      {tickets.length > 1 && (
        <details class="check-card-tickets" open={ticket === null}>
          <summary>
            Vstupenky rezervace: {tickets.length - (reservation?.unused ?? 0)} / {tickets.length} použito
          </summary>
          <ul>
            {tickets.map((t) => (
              <li key={t.code} class={t.used_at ? 'is-used' : ''}>
                <span>{ticketPlace(t)}</span>
                <span class="check-code">{t.code}</span>
                <span>{t.used_at ? formatTime(t.used_at) : 'nepoužita'}</span>
              </li>
            ))}
          </ul>
        </details>
      )}

      <div class="check-card-actions">
        {canEnter && ticketUnused && !justUsed && (
          <button type="button" class="check-button check-button--primary" disabled={busy} onClick={() => onUse(ticket.code)}>
            {result.status === 'unpaid' ? `Pustit (doplatit ${formatPrice(reservation?.remaining ?? 0)})` : 'Potvrdit vstup'}
          </button>
        )}
        {(canEnter || justUsed) && reservation && others > 0 && (
          <button type="button" class="check-button" disabled={busy} onClick={() => onUseReservation(reservation.id)}>
            {ticketUnused
              ? `Pustit celou rezervaci (${others + 1} ${plural(others + 1, 'vstupenka', 'vstupenky', 'vstupenek')})`
              : `Pustit zbytek rezervace (${others} ${plural(others, 'vstupenka', 'vstupenky', 'vstupenek')})`}
          </button>
        )}
        {ticket?.used_at && !askUndo && (
          <button type="button" class="check-button check-button--quiet" disabled={busy} onClick={() => setAskUndo(true)}>
            Vrátit vstup
          </button>
        )}
        {ticket?.used_at && askUndo && (
          <div class="check-confirm" role="group" aria-label="Vrátit vstup">
            <p>Opravdu vrátit vstup této vstupenky? Půjde použít znovu.</p>
            <button type="button" class="check-button" disabled={busy} onClick={() => onUndo(ticket.code)}>
              Ano, vrátit
            </button>
            <button type="button" class="check-button check-button--quiet" onClick={() => setAskUndo(false)}>
              Ne
            </button>
          </div>
        )}
        <button type="button" class="check-button check-button--next" onClick={onClose}>
          Další
        </button>
      </div>
    </section>
  );
}
