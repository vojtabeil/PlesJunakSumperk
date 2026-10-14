import { canAddStanding, formatPrice, totals } from '../logic';
import type { Store } from '../store';
import type { ApiState } from '../types';

interface Props {
  store: Store;
  state: ApiState;
}

/** Tickets without a seat: a row under the map with the price, how many are left and a stepper. */
export function StandingTickets({ store, state }: Props) {
  const { standing } = totals(state.reservation, state.prices);
  const busy = store.busy.value;
  const left = state.limits.standing_left;

  return (
    <div class="standing">
      <span class="standing-price" aria-hidden="true">{formatPrice(state.prices.standing)}</span>
      <p class="standing-text" id="standing-label">
        <strong>Lístek bez místenky</strong>
        <span class="muted"> · zbývá {left}</span>
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
          aria-label={`Přidat lístek bez místenky za ${formatPrice(state.prices.standing)}`}
          disabled={busy || !canAddStanding(state)}
          onClick={() => void store.setStanding(standing + 1)}
        >
          +
        </button>
      </div>
    </div>
  );
}
