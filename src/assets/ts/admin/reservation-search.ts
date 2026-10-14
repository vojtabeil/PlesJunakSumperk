// Search box of an unmatched payment: typing a name, e-mail or number lists matching reservations
// (GET Payment:reservations, JSON) as buttons that assign the payment in one click (name="pick").
// Without JavaScript the box takes a reservation number and "Přiřadit" works the same.

import { formatPrice } from '../seat-picker/logic';

export interface ReservationHit {
  id: number;
  name: string;
  email: string;
  status: string;
  total: number;
  remaining: number;
}

const DebounceMs = 200;
const MinLength = 2;

/** Text of one hit: "č. 12 Jana Nováková · jana@… · zbývá 700 Kč" (or the status when nothing is left). */
export function hitLabel(hit: ReservationHit): string {
  const rest = hit.remaining > 0 ? `zbývá ${formatPrice(hit.remaining)}` : hit.status.toLowerCase();
  return `č. ${hit.id} ${hit.name} · ${hit.email} · ${rest}`;
}

export function initReservationSearch(root: ParentNode = document): void {
  root.querySelectorAll<HTMLInputElement>('input[data-reservation-search]').forEach((input) => {
    const url = input.dataset['reservationSearch'] ?? '';
    const results = input.closest('form')?.querySelector<HTMLElement>('.resolve-results');
    if (!url || !results) {
      return;
    }
    let timer = 0;
    let request = 0;

    input.addEventListener('input', () => {
      window.clearTimeout(timer);
      const query = input.value.trim();
      if (query.length < MinLength) {
        results.replaceChildren();
        return;
      }
      timer = window.setTimeout(async () => {
        const mine = ++request;
        const separator = url.includes('?') ? '&' : '?';
        const response = await fetch(`${url}${separator}q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
        const hits = response.ok ? ((await response.json()) as ReservationHit[]) : [];
        if (mine !== request) {
          return; // an answer to an older query
        }
        results.replaceChildren(...render(hits, query));
      }, DebounceMs);
    });
  });
}

function render(hits: ReservationHit[], query: string): HTMLElement[] {
  if (hits.length === 0) {
    const empty = document.createElement('span');
    empty.className = 'muted';
    empty.textContent = /^\d+$/.test(query) ? 'Žádná rezervace s tímto číslem.' : 'Nic nenalezeno.';
    return [empty];
  }
  return hits.map((hit) => {
    const button = document.createElement('button');
    button.type = 'submit';
    button.name = 'pick';
    button.value = String(hit.id);
    button.className = 'suggestion';
    button.textContent = hitLabel(hit);
    button.title = 'Přiřadit platbu k této rezervaci';
    return button;
  });
}
