import { useEffect } from 'preact/hooks';
import type { Store } from '../store';
import type { PickerData } from '../types';
import { EmailForm } from './EmailForm';
import { HallMap } from './HallMap';
import { Summary } from './Summary';

const PollMs = 3000;

interface Props {
  store: Store;
  data: PickerData;
}

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

  // The sale was closed meanwhile: the server renders the "closed" page.
  useEffect(() => {
    if (state && !state.sale_open) {
      window.location.reload();
    }
  }, [state?.sale_open]);

  if (!state) {
    return <p class="message">Načítám…</p>;
  }

  const reservation = state.reservation;
  return (
    <>
      {!reservation && <EmailForm store={store} />}

      <p class={`message${message?.error ? ' message--error' : ''}`} role="status" aria-live="polite">
        {message?.text}
      </p>

      {reservation && (
        <div class={`picker${busy ? ' is-busy' : ''}`}>
          <div class="picker-map">
            <ul class="legend" aria-label="Legenda">
              <li><span class="swatch swatch--free" /> volné</li>
              <li><span class="swatch swatch--mine" /> vaše</li>
              <li><span class="swatch swatch--taken" /> obsazené</li>
            </ul>
            <HallMap layout={data.layout} state={state} disabled={busy} onToggle={(id) => void store.toggleSeat(id)} />
            <p class="hint">Na telefonu lze plánkem posouvat do stran.</p>
          </div>
          <Summary store={store} state={state} reservation={reservation} />
        </div>
      )}
    </>
  );
}
