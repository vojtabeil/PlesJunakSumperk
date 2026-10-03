// Shapes of the data exchanged with the server (app/Presentation/Front/Api, Home).

export interface HallArea {
  kind: 'stage' | 'floor';
  x: number;
  y: number;
  width: number;
  height: number;
  label: string;
}

export interface HallTable {
  id: number;
  label: string;
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface HallSeat {
  id: number;
  /** "<table>/<seat>", e.g. "3/5" */
  label: string;
  table_id: number | null;
  x: number;
  y: number;
}

export interface HallLayout {
  width: number;
  height: number;
  areas: HallArea[];
  tables: HallTable[];
  seats: HallSeat[];
}

export interface Prices {
  seat: number;
  standing: number;
}

/** Initial data printed into the page (<script id="seat-picker-data">). */
export interface PickerData {
  /** API URL with "__op__" as the operation placeholder. */
  api: string;
  csrf: string;
  layout: HallLayout;
  prices: Prices;
  maxTickets: number;
}

export interface ReservationState {
  email: string;
  standing: number;
  seats: { id: number; label: string }[];
  /** Seconds until held seats are released; null when nothing is held. */
  expires_in: number | null;
}

export interface ApiState {
  sale_open: boolean;
  reservation: ReservationState | null;
  taken: number[];
  mine: number[];
  limits: {
    max_tickets: number;
    standing_left: number;
    hold_seconds: number;
  };
  prices: Prices;
}

export type ApiResponse =
  | { ok: true; state: ApiState; redirect?: string }
  | { ok: false; error: string; state?: ApiState | null };

export type Operation = 'state' | 'start' | 'hold' | 'release' | 'standing' | 'confirm' | 'cancel';

export type SeatStatus = 'free' | 'mine' | 'taken';
