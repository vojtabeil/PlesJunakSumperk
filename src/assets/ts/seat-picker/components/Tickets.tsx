import { canAddStanding, formatPrice, freeSeats, totals } from '../logic';
import type { Store } from '../store';
import type { ApiState } from '../types';

interface Props {
  store: Store;
  state: ApiState;
  /** Seats in the plan; 0 without a plan (only tickets without a seat are sold). */
  seatCount: number;
}

/**
 * Both kinds of tickets side by side above the map, as two tickets of the same look: a seat (chosen in
 * the plan, with the colours of the plan) and a ticket without a seat (chosen with the stepper).
 */
export function Tickets({ store, state, seatCount }: Props) {
  const { standing } = totals(state.reservation, state.prices);
  const busy = store.busy.value;

  return (
    <div class="tickets">
      {seatCount > 0 && (
        <div class="ticket-kind ticket-kind--seat">
          <span class="ticket-price" aria-hidden="true">{formatPrice(state.prices.seat)}</span>
          <div class="ticket-text">
            <p>
              <strong>Místenka</strong>
              <span class="visually-hidden"> za {formatPrice(state.prices.seat)}</span> · volných {freeSeats(seatCount, state)}
            </p>
            <ul class="legend" aria-label="Legenda plánku">
              <li><span class="swatch swatch--free" /> volné</li>
              <li><span class="swatch swatch--mine" /> vybrané</li>
              <li><span class="swatch swatch--taken" /> obsazené</li>
            </ul>
          </div>
        </div>
      )}

      <div class="ticket-kind ticket-kind--standing">
        <span class="ticket-price" aria-hidden="true">{formatPrice(state.prices.standing)}</span>
        <p class="ticket-text" id="standing-label">
          <strong>Bez místenky</strong>
          <span class="visually-hidden"> za {formatPrice(state.prices.standing)}</span> · zbývá {state.limits.standing_left}
        </p>
        <div class="stepper" role="group" aria-labelledby="standing-label">
          <button
            type="button"
            class="stepper-button"
            aria-label="Ubrat lístek bez místenky"
            disabled={busy || standing === 0}
            onClick={() => void store.setStanding(standing - 1)}
          >
            −
          </button>
          <output aria-live="polite" aria-label="Počet lístků bez místenky">{standing}</output>
          <button
            type="button"
            class="stepper-button"
            aria-label="Přidat lístek bez místenky"
            disabled={busy || !canAddStanding(state)}
            onClick={() => void store.setStanding(standing + 1)}
          >
            +
          </button>
        </div>
      </div>
    </div>
  );
}
