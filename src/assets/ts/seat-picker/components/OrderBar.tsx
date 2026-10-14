import { choiceSummary, formatPrice, totals } from '../logic';
import type { ApiState } from '../types';
import { scrollToElement } from '../../scroll';

/**
 * The bottom bar of the map screen: what is chosen, the price and the way on to the summary and form below
 * (scrolls without changing the URL). Always there, so the next step is always in the same place.
 */
export function OrderBar({ state }: { state: ApiState }) {
  const sum = totals(state.reservation, state.prices);
  const goToForm = () => {
    scrollToElement('summary');
    window.setTimeout(() => document.getElementById('email')?.focus({ preventScroll: true }), 450);
  };

  return (
    <div class="order-bar">
      <p class="order-choice" aria-live="polite">
        {sum.tickets > 0 ? (
          <>
            <span class="order-label">Vybráno: </span>
            <span>{choiceSummary(state.reservation)}</span> · <strong>{formatPrice(sum.price)}</strong>
          </>
        ) : (
          <span class="muted">Vyberte místa v plánku nebo lístky bez místenky.</span>
        )}
      </p>
      <button type="button" class="button order-next" disabled={sum.tickets === 0} onClick={goToForm}>
        Pokračovat k údajům
      </button>
    </div>
  );
}
