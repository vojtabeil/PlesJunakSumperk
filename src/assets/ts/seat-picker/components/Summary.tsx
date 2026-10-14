import { canAddStanding, formatPrice, formatSeat, plural, totals } from '../logic';
import type { Store } from '../store';
import type { ApiState } from '../types';
import { CheckoutForm } from './CheckoutForm';
import { Countdown } from './Countdown';

interface Props {
  store: Store;
  state: ApiState;
}

/** The visitor's choice (seats, standing tickets, price, hold countdown) and the confirmation form. */
export function Summary({ store, state }: Props) {
  const reservation = state.reservation;
  const sum = totals(reservation, state.prices);
  const busy = store.busy.value;

  return (
    <aside class="summary" aria-labelledby="summary-title">
      <h3 id="summary-title">Váš výběr</h3>

      {reservation && reservation.seats.length > 0 ? (
        <ul class="chosen" aria-label="Vybraná místa">
          {reservation.seats.map((seat) => (
            <li key={seat.id}>
              {formatSeat(seat.label)}
              <button
                type="button"
                class="chosen-remove"
                disabled={busy}
                aria-label={`Odebrat ${formatSeat(seat.label)}`}
                onClick={() => void store.toggleSeat(seat.id)}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p class="muted">Klepněte na volné místo v plánku sálu.</p>
      )}

      <div class="standing">
        <span id="standing-label">
          Lístky bez místenky <span class="muted">({formatPrice(state.prices.standing)})</span>
        </span>
        <div class="stepper" role="group" aria-labelledby="standing-label">
          <button
            type="button"
            class="stepper-button"
            aria-label="Ubrat lístek bez místenky"
            disabled={busy || sum.standing === 0}
            onClick={() => void store.setStanding(sum.standing - 1)}
          >
            −
          </button>
          <output aria-live="polite">{sum.standing}</output>
          <button
            type="button"
            class="stepper-button"
            aria-label="Přidat lístek bez místenky"
            disabled={busy || !canAddStanding(state)}
            onClick={() => void store.setStanding(sum.standing + 1)}
          >
            +
          </button>
        </div>
      </div>

      <p class="total">
        <span>
          {sum.tickets} {plural(sum.tickets, 'lístek', 'lístky', 'lístků')}
        </span>
        <strong>{formatPrice(sum.price)}</strong>
      </p>
      <p class="prices">Nejvýše {state.limits.max_tickets} lístků na jednu rezervaci.</p>

      {reservation?.expires_in != null && (
        <Countdown seconds={reservation.expires_in} onExpire={store.holdExpired} onExtend={() => void store.extend()} />
      )}

      {sum.tickets > 0 && (
        <>
          <CheckoutForm store={store} email={reservation?.email ?? ''} disabled={busy} />
          <button type="button" class="link-button cancel-choice" disabled={busy} onClick={() => void store.cancel()}>
            Zrušit výběr
          </button>
        </>
      )}
    </aside>
  );
}
