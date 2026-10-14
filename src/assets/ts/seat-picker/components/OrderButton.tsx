import { formatPrice, totals } from '../logic';
import type { ApiState } from '../types';
import { scrollToElement } from '../../scroll';

interface Props {
  state: ApiState;
  /** Called first: the map falls asleep (keeping its zoom and position). */
  onOrder: () => void;
}

/**
 * Green button in the corner of the map once something is chosen: scrolls (without changing the URL)
 * to the form and puts the cursor into its first empty field.
 */
export function OrderButton({ state, onOrder }: Props) {
  const sum = totals(state.reservation, state.prices);
  if (sum.tickets === 0) {
    return null;
  }
  const order = () => {
    onOrder();
    scrollToElement('checkout');
    window.setTimeout(() => {
      const fields = [...document.querySelectorAll<HTMLInputElement>('#checkout input:not([type="checkbox"])')];
      (fields.find((field) => field.value === '') ?? fields[0])?.focus({ preventScroll: true });
    }, 450);
  };
  return (
    <button type="button" class="order-button" onClick={order}>
      Objednat · <strong>{formatPrice(sum.price)}</strong>
    </button>
  );
}
