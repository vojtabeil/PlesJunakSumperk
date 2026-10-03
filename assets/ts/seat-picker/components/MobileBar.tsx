import { useEffect, useState } from 'preact/hooks';
import { formatPrice, plural, totals } from '../logic';
import type { ApiState } from '../types';

/**
 * Bottom bar on phones: what is chosen and a button to the form, so the visitor does not have
 * to look for it under the map. Hidden while the form itself is on screen.
 */
export function MobileBar({ state }: { state: ApiState }) {
  const [formVisible, setFormVisible] = useState(false);
  const sum = totals(state.reservation, state.prices);

  useEffect(() => {
    const form = document.getElementById('checkout');
    if (!form || !('IntersectionObserver' in window)) {
      return;
    }
    const observer = new IntersectionObserver(([entry]) => setFormVisible(entry?.isIntersecting ?? false));
    observer.observe(form);
    return () => observer.disconnect();
  }, [sum.tickets > 0]);

  if (sum.tickets === 0 || formVisible) {
    return null;
  }

  const goToForm = () => {
    document.getElementById('checkout')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.setTimeout(() => document.getElementById('email')?.focus({ preventScroll: true }), 400);
  };

  return (
    <div class="mobile-bar">
      <span>
        {sum.tickets} {plural(sum.tickets, 'lístek', 'lístky', 'lístků')} · <strong>{formatPrice(sum.price)}</strong>
      </span>
      <button type="button" class="button" onClick={goToForm}>
        Pokračovat
      </button>
    </div>
  );
}
