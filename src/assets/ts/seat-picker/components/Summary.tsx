import { formatPrice, formatSeat, plural, totals } from '../logic';
import type { Store } from '../store';
import type { ApiState } from '../types';
import { CheckoutForm } from './CheckoutForm';
import { Countdown } from './Countdown';

interface Props {
  store: Store;
  state: ApiState;
}

/** Below the map once something is chosen: the choice (seats can be removed), hold countdown and the form. */
export function Summary({ store, state }: Props) {
  const reservation = state.reservation;
  const sum = totals(reservation, state.prices);
  const busy = store.busy.value;

  return (
    <aside class="summary" id="summary" aria-labelledby="summary-title">
      <h3 id="summary-title">Vaše rezervace</h3>

      {reservation && reservation.seats.length > 0 && (
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
      )}
      {sum.standing > 0 && (
        <p class="chosen-standing">
          {sum.standing} {plural(sum.standing, 'lístek', 'lístky', 'lístků')} bez místenky
        </p>
      )}

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

      <CheckoutForm store={store} email={reservation?.email ?? ''} disabled={busy} />
      <button type="button" class="link-button cancel-choice" disabled={busy} onClick={() => void store.cancel()}>
        Zrušit výběr
      </button>
    </aside>
  );
}
