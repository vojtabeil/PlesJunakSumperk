import { useEffect, useState } from 'preact/hooks';
import { totals } from '../logic';
import type { Store } from '../store';
import type { PickerData } from '../types';
import { HallMap } from './HallMap';
import { OrderButton } from './OrderButton';
import { Summary } from './Summary';
import { Tickets } from './Tickets';

const PollMs = 3000;

interface Props {
  store: Store;
  data: PickerData;
}

/**
 * One screen with everything needed to choose: both kinds of tickets above, the whole map (live seat
 * states) below them. The summary with the form follows below; the order button floats over the screen until it is seen.
 */
export function App({ store, data }: Props) {
  const state = store.state.value;
  const message = store.message.value;
  const busy = store.busy.value;
  const [awake, setAwake] = useState(false);

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
        <Tickets store={store} state={state} seatCount={hasPlan ? data.layout.seats.length : 0} />
        <p class={`message picker-message${message?.error ? ' message--error' : ''}`} role="status" aria-live="polite">
          {message?.text}
        </p>
        {hasPlan ? (
          <HallMap
            layout={data.layout}
            state={state}
            prices={state.prices}
            disabled={busy}
            onToggle={(id) => void store.toggleSeat(id)}
            awake={awake}
            onAwake={setAwake}
          />
        ) : (
          <p class="stage-note">Plánek sálu připravujeme. Zatím lze koupit jen lístky bez místenky.</p>
        )}
      </div>
      <OrderButton state={state} onOrder={() => setAwake(false)} />
      {totals(state.reservation, state.prices).tickets > 0 && <Summary store={store} state={state} />}
    </div>
  );
}
