import { canAddStanding, formatPrice, formatSeatList, totals } from '../logic';
import type { Store } from '../store';
import type { ApiState, ReservationState } from '../types';
import { Countdown } from './Countdown';

interface Props {
  store: Store;
  state: ApiState;
  reservation: ReservationState;
}

/** Step 2-3: standing tickets, totals, hold countdown and the confirmation form. */
export function Summary({ store, state, reservation }: Props) {
  const sum = totals(reservation, state.prices);
  const busy = store.busy.value;

  const confirm = (event: SubmitEvent) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget as HTMLFormElement);
    void store.confirm({
      name: String(form.get('name') ?? ''),
      phone: String(form.get('phone') ?? ''),
      consent: form.get('consent') === 'on',
    });
  };

  return (
    <aside class="summary" aria-labelledby="summary-title">
      <h3 id="summary-title">Vaše rezervace</h3>
      <p class="summary-email">
        E-mail: <strong>{reservation.email}</strong>{' '}
        <button type="button" class="link-button" disabled={busy} onClick={() => void store.cancel()}>
          změnit
        </button>
      </p>

      <div class="standing">
        <span id="standing-label">Lístky bez místenky</span>
        <div class="stepper" role="group" aria-labelledby="standing-label">
          <button
            type="button"
            class="stepper-button"
            aria-label="Ubrat lístek bez místenky"
            disabled={busy || reservation.standing === 0}
            onClick={() => void store.setStanding(reservation.standing - 1)}
          >
            −
          </button>
          <output aria-live="polite">{reservation.standing}</output>
          <button
            type="button"
            class="stepper-button"
            aria-label="Přidat lístek bez místenky"
            disabled={busy || !canAddStanding(state)}
            onClick={() => void store.setStanding(reservation.standing + 1)}
          >
            +
          </button>
        </div>
      </div>

      <dl class="totals">
        <dt>Místa u stolu</dt>
        <dd>{formatSeatList(reservation.seats)}</dd>
        <dt>Celkem lístků</dt>
        <dd>{sum.tickets}</dd>
        <dt>Cena</dt>
        <dd>{formatPrice(sum.price)}</dd>
      </dl>
      <p class="prices">
        Místenka {formatPrice(state.prices.seat)}, bez místenky {formatPrice(state.prices.standing)}. Nejvýše{' '}
        {state.limits.max_tickets} lístků na rezervaci.
      </p>

      {reservation.expires_in !== null && (
        <Countdown seconds={reservation.expires_in} onExpire={store.holdExpired} />
      )}

      <form class="confirm-form" noValidate onSubmit={confirm}>
        <label for="name">Jméno a příjmení</label>
        <input type="text" id="name" name="name" autoComplete="name" required maxLength={255} />
        <label for="phone">
          Telefon <span class="optional">(nepovinné)</span>
        </label>
        <input type="tel" id="phone" name="phone" autoComplete="tel" maxLength={20} />
        <label class="checkbox">
          <input type="checkbox" name="consent" required />
          Souhlasím se zpracováním osobních údajů pro účely rezervace.
        </label>
        <button type="submit" class="button button--primary" disabled={busy || sum.tickets === 0}>
          Závazně rezervovat
        </button>
      </form>
    </aside>
  );
}
