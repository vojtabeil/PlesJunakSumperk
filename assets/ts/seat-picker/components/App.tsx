import { useEffect } from 'preact/hooks';
import { formatPrice, freeSeats } from '../logic';
import type { Store } from '../store';
import type { PickerData } from '../types';
import { HallMap } from './HallMap';
import { MobileBar } from './MobileBar';
import { Summary } from './Summary';

const PollMs = 3000;

interface Props {
  store: Store;
  data: PickerData;
}

/**
 * The map with live availability is shown right away; the first chosen ticket starts the
 * reservation and the e-mail is asked for in the form at the end.
 */
export function App({ store, data }: Props) {
  const state = store.state.value;
  const message = store.message.value;
  const busy = store.busy.value;

  // Load the state once, then keep seat states fresh while the tab is visible.
  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === 'visible') {
        void store.refresh();
      }
    };
    void store.refresh();
    const timer = setInterval(refresh, PollMs);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [store]);

  // The sale was closed meanwhile: the server renders the page of the new stage.
  useEffect(() => {
    if (state && !state.sale_open) {
      window.location.reload();
    }
  }, [state?.sale_open]);

  if (!state) {
    return <p class="message">Načítám plánek sálu…</p>;
  }

  return (
    <>
      <p class="availability">
        Volných míst u stolů: <strong>{freeSeats(data.layout.seats.length, state)}</strong> · bez místenky:{' '}
        <strong>{state.limits.standing_left}</strong> · místenka {formatPrice(state.prices.seat)}, bez místenky{' '}
        {formatPrice(state.prices.standing)}
      </p>

      <p class={`message${message?.error ? ' message--error' : ''}`} role="status" aria-live="polite">
        {message?.text}
      </p>

      <div class={`picker${busy ? ' is-busy' : ''}`}>
        <div class="picker-map">
          <ul class="legend" aria-label="Legenda">
            <li><span class="swatch swatch--free" /> volné</li>
            <li><span class="swatch swatch--mine">✓</span> vybrané</li>
            <li><span class="swatch swatch--taken" /> obsazené</li>
          </ul>
          <HallMap layout={data.layout} state={state} prices={state.prices} disabled={busy} onToggle={(id) => void store.toggleSeat(id)} />
        </div>
        <Summary store={store} state={state} />
      </div>
      <MobileBar state={state} />
    </>
  );
}
