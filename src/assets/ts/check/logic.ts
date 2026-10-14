// Pure helpers of the entrance check (unit-tested in tests/ts/check.test.ts).

import { formatSeat } from '../seat-picker/logic';
import type { CheckStatus, TicketView } from './types';

export const CodeLength = 6;

/**
 * The ticket code in a scanned text: the QR code holds the link /admin/kontrola/<code>, a printed
 * code may be typed or scanned on its own. Accepts any case, spaces, dashes and O for zero.
 */
export function extractCode(text: string): string | null {
  const fromLink = /\/kontrola\/([0-9a-f]{6})(?:[/?#]|$)/i.exec(text.trim());
  if (fromLink?.[1]) {
    return fromLink[1].toUpperCase();
  }
  const code = text.replace(/[\s-]+/g, '').toUpperCase().replace(/O/g, '0');
  return new RegExp(`^[0-9A-F]{${CodeLength}}$`).test(code) ? code : null;
}

/**
 * What the manual input field holds: a code (6 hex characters) or a reservation number /
 * variable symbol (digits). Both are sent to the server as typed.
 */
export function isLookupInput(text: string): boolean {
  const trimmed = text.trim();
  return extractCode(trimmed) !== null || /^\d{1,10}$/.test(trimmed);
}

export const StatusTitles: Record<CheckStatus, string> = {
  ok: 'Vstup povolen',
  used: 'Už použito',
  unpaid: 'Nezaplaceno',
  invalid: 'Neplatné',
};

/** "Stůl 3 · místo 5", "Na stání" or "Z-lístek". */
export function ticketPlace(ticket: TicketView): string {
  if (ticket.kind === 'crew') {
    return 'Z-lístek';
  }
  return ticket.seat === null ? 'Na stání' : formatSeat(ticket.seat);
}

/** "14:05" for today, "3. 2. 14:05" otherwise (time of an entry). */
export function formatTime(value: string, now: Date = new Date()): string {
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  const time = `${date.getHours()}:${String(date.getMinutes()).padStart(2, '0')}`;
  return date.toDateString() === now.toDateString() ? time : `${date.getDate()}. ${date.getMonth() + 1}. ${time}`;
}
