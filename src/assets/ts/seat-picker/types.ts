// Shapes of the data exchanged with the server (app/Presentation/Front/Api, Home).

export interface HallTable {
  id: number;
  label: string;
  /** Name of the table (scout personality), null when the plan has none. */
  name: string | null;
  /** False = out of sale (administration -> Stoly): hidden from the map, its seats are not in the layout. */
  available?: boolean;
}
export interface HallSeat {
  id: number;
  /** "<table>/<seat>", e.g. "A1/5" */
  label: string;
  table_id: number | null;
  /** Id of the seat's shape in the SVG plan. */
  svg_id: string;
}
export interface HallLayout {
  /** URL of the SVG plan; null while no plan is uploaded (only standing tickets are sold). */
  svg: string | null;
  tables: HallTable[];
  seats: HallSeat[];
}export interface Prices {
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

/** The draft of this browser; it starts with the first chosen ticket. */
export interface ReservationState {
  /** Null until the visitor fills in the confirmation form. */
  email: string | null;
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

export type Operation = 'state' | 'hold' | 'release' | 'standing' | 'extend' | 'confirm' | 'cancel';

export type SeatStatus = 'free' | 'mine' | 'taken';

export type Direction = 'left' | 'right' | 'up' | 'down';
