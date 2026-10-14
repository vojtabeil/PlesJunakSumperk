import { useEffect, useState } from 'preact/hooks';
import { formatPrice, totals } from '../logic';
import type { ApiState } from '../types';
import { scrollToElement } from '../../scroll';

interface Props {
  state: ApiState;
  /** Called first: the map falls asleep (keeping its zoom and position). */
  onOrder: () => void;
}

/** The form counts as visible once it takes this share of the screen (or of itself, if it is shorter). */
const VisibleShare = 0.4;
const Thresholds = Array.from({ length: 21 }, (_, i) => i / 20);

/**
 * Green button fixed in the top right corner of the screen while something is chosen and the form is not
 * reasonably visible: scrolls (without changing the URL) to the form and puts the cursor into its first empty field.
 */
export function OrderButton({ state, onOrder }: Props) {
  const sum = totals(state.reservation, state.prices);
  const [formVisible, setFormVisible] = useState(false);
  const active = sum.tickets > 0;

  // The form (CheckoutForm, #checkout) exists only while something is chosen.
  useEffect(() => {
    const form = active ? document.getElementById('checkout') : null;
    if (!form || typeof IntersectionObserver === 'undefined') {
      setFormVisible(false);
      return;
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (entry) {
        const needed = Math.min(window.innerHeight, entry.boundingClientRect.height) * VisibleShare;
        setFormVisible(entry.intersectionRect.height >= needed);
      }
    }, { threshold: Thresholds });
    observer.observe(form);
    return () => observer.disconnect();
  }, [active]);

  const order = () => {
    onOrder();
    scrollToElement('checkout');
    window.setTimeout(() => {
      const fields = [...document.querySelectorAll<HTMLInputElement>('#checkout input:not([type="checkbox"])')];
      (fields.find((field) => field.value === '') ?? fields[0])?.focus({ preventScroll: true });
    }, 450);
  };

  const shown = active && !formVisible;
  return (
    <button
      type="button"
      class={`order-button${shown ? ' is-shown' : ''}`}
      onClick={order}
      aria-hidden={!shown}
      tabIndex={shown ? 0 : -1}
    >
      Objednat · <strong>{formatPrice(sum.price)}</strong>
    </button>
  );
}
