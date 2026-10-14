import { formatPrice, plural, totals } from '../logic';
import type { ApiState } from '../types';
import { scrollToElement } from '../../scroll';

/**
 * Green button over the map once something is chosen: scrolls (without changing the URL) to the
 * summary with the form where the visitor fills in the details.
 */
export function OrderButton({ state }: { state: ApiState }) {
  const sum = totals(state.reservation, state.prices);
  if (sum.tickets === 0) {
    return null;
  }
  const goToForm = () => {
    scrollToElement('checkout');
    window.setTimeout(() => document.getElementById('email')?.focus({ preventScroll: true }), 450);
  };
  return (
    <button type="button" class="order-button" onClick={goToForm}>
      <span class="order-tick" aria-hidden="true">✓</span>
      <span>
        Objednat · {sum.tickets} {plural(sum.tickets, 'lístek', 'lístky', 'lístků')} · <strong>{formatPrice(sum.price)}</strong>
      </span>
    </button>
  );
}
