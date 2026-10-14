// Shapes of the data exchanged with the entrance check (app/Presentation/Admin/Check, app/Model/Ticket/TicketCheck.php).

export type CheckStatus = 'ok' | 'used' | 'unpaid' | 'invalid';

/** seat / standing ticket of a reservation, crew = Z-lístek (free pass of the crew). */
export type TicketKind = 'seat' | 'standing' | 'crew';

export interface TicketView {
  code: string;
  kind: TicketKind;
  /** Seat label "<table>/<seat>", null = standing ticket or Z-lístek. */
  seat: string | null;
  used_at: string | null;
  used_by: string | null;
}

export interface ReservationView {
  id: number;
  name: string;
  status: string;
  /** Amount still to pay in CZK. */
  remaining: number;
  /** Tickets of the reservation not used yet. */
  unused: number;
}

export interface CheckResult {
  status: CheckStatus;
  message: string;
  /** The scanned ticket; null when a reservation number was typed. */
  ticket: TicketView | null;
  reservation: ReservationView | null;
  /** Owner of a Z-lístek; null for tickets of reservations. */
  crew: CrewView | null;
  tickets: TicketView[];
}

export interface CrewView {
  name: string;
  /** Free text: bar, kuchyně, šatna, ... */
  role: string;
}

export interface Stats {
  used: number;
  total: number;
}

export type CheckResponse =
  | { ok: true; result: CheckResult; stats: Stats }
  | { ok: false; error: string };

/** Initial data printed into the page (<script id="check-data">). */
export interface CheckData {
  /** API URL with "__op__" as the operation placeholder. */
  api: string;
  csrf: string;
  /** Code from the QR link the page was opened with (/admin/kontrola/<code>). */
  code: string | null;
  /** Back to the administration. */
  back: string;
  stats: Stats;
}
