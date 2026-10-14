import { useEffect } from 'preact/hooks';
import { formatPrice, freeSeats, totals } from '../logic';
import type { Store } from '../store';
import type { PickerData } from '../types';
import { HallMap } from './HallMap';
import { OrderBar } from './OrderBar';
import { StandingTickets } from './StandingTickets';
import { Summary } from './Summary';

const PollMs = 3000;

interface Props {
  store: Store;
  data: PickerData;
}

/**
 * One screen with everything needed to choose: availability, the whole map (live seat states),
 * tickets without a seat and the bar with the choice and "Pokračovat k údajům". The form follows below.
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

  const hasPlan = data.layout.svg !== null;
  return (
    <div class={`picker${busy ? ' is-busy' : ''}`}>
      <div class={`picker-stage${hasPlan ? '' : ' picker-stage--empty'}`}>
        {hasPlan && (
          <div class="stage-head">
            <p class="availability">
              Volná místa u stolů: <strong>{freeSeats(data.layout.seats.length, state)}</strong>
              <span class="availability-price"> · místenka {formatPrice(state.prices.seat)}</span>
            </p>
            <ul class="legend" aria-label="Legenda">
              <li><span class="swatch swatch--free" /> volné</li>
              <li><span class="swatch swatch--mine" /> vybrané</li>
              <li><span class="swatch swatch--taken" /> obsazené</li>
            </ul>
          </div>
        )}
        <p class={`message picker-message${message?.error ? ' message--error' : ''}`} role="status" aria-live="polite">
          {message?.text}
        </p>
        {hasPlan ? (
          <HallMap layout={data.layout} state={state} prices={state.prices} disabled={busy} onToggle={(id) => void store.toggleSeat(id)} />
        ) : (
          <p class="stage-note">Plánek sálu připravujeme. Zatím lze koupit jen lístky bez místenky.</p>
        )}
        <StandingTickets store={store} state={state} />
        <OrderBar state={state} />
      </div>
      {totals(state.reservation, state.prices).tickets > 0 && <Summary store={store} state={state} />}
    </div>
  );
}
