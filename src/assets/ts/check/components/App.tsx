import { useEffect, useRef, useState } from 'preact/hooks';
import type { CheckApi } from '../api';
import { keepScreenOn, signal, toggleFullscreen } from '../device';
import { extractCode, isLookupInput } from '../logic';
import { createScanner, type Camera, type Scanner } from '../scanner';
import type { CheckData, CheckResponse, CheckResult, Stats } from '../types';
import { ResultCard } from './ResultCard';

interface Props {
  data: CheckData;
  api: CheckApi;
}

interface InstallPrompt extends Event {
  prompt(): Promise<void>;
}

/** Ignore the same QR text for this long (the camera sees it in many frames). */
const RepeatMs = 3000;

/**
 * Entrance check: camera scanner behind, stats and round buttons on top, manual input at the
 * bottom, result card over everything. Scanning pauses while a result is shown.
 */
export function App({ data, api }: Props) {
  const cameraRef = useRef<HTMLDivElement>(null);
  const scanner = useRef<Scanner | null>(null);
  const lastText = useRef({ text: '', at: 0 });
  const showing = useRef(false);
  const busyRef = useRef(false);

  const [stats, setStats] = useState<Stats>(data.stats);
  const [result, setResult] = useState<CheckResult | null>(null);
  const [justUsed, setJustUsed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [input, setInput] = useState('');
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [cameras, setCameras] = useState<Camera[]>([]);
  const [torch, setTorch] = useState<boolean | null>(null);
  const [install, setInstall] = useState<InstallPrompt | null>(null);

  const showToast = (message: string) => {
    setToast(message);
    window.setTimeout(() => setToast((current) => (current === message ? null : current)), 4000);
  };

  const show = (response: CheckResponse, used = false): boolean => {
    if (!response.ok) {
      showToast(response.error);
      return false;
    }
    showing.current = true;
    setResult(response.result);
    setStats(response.stats);
    setJustUsed(used);
    signal(used ? 'ok' : response.result.status);
    return true;
  };

  const setWorking = (working: boolean) => {
    busyRef.current = working;
    setBusy(working);
  };

  const lookup = async (text: string) => {
    if (busyRef.current) {
      return;
    }
    setWorking(true);
    scanner.current?.pause();
    const shown = show(await api.lookup(text));
    setWorking(false);
    if (!shown) {
      void scanner.current?.resume();
    }
  };

  /** A change (entry recorded or taken back); used = show it as a recorded entry. */
  const act = async (call: () => Promise<CheckResponse>, used: boolean) => {
    setWorking(true);
    show(await call(), used);
    setWorking(false);
  };

  const close = () => {
    showing.current = false;
    setResult(null);
    setJustUsed(false);
    lastText.current = { text: '', at: Date.now() };
    void scanner.current?.resume();
  };

  useEffect(() => {
    const container = cameraRef.current;
    if (!container) {
      return;
    }
    const onText = (text: string) => {
      const now = Date.now();
      if (showing.current || (text === lastText.current.text && now - lastText.current.at < RepeatMs)) {
        return;
      }
      lastText.current = { text, at: now };
      const code = extractCode(text);
      if (code === null) {
        showToast('Tento QR kód není vstupenka na ples.');
        return;
      }
      void lookup(code);
    };
    const s = createScanner(container, onText);
    scanner.current = s;
    s.start()
      .then(async () => {
        setCameras(await s.cameras());
        setTorch((await s.hasTorch()) ? false : null);
      })
      .catch(() =>
        setCameraError('Kameru se nepodařilo spustit. Povolte prohlížeči přístup ke kameře, nebo zadejte kód ručně.'),
      );
    if (data.code) {
      void lookup(data.code);
    }
    const release = keepScreenOn();
    const onInstall = (event: Event) => {
      event.preventDefault();
      setInstall(event as InstallPrompt);
    };
    window.addEventListener('beforeinstallprompt', onInstall);
    return () => {
      window.removeEventListener('beforeinstallprompt', onInstall);
      release();
      s.destroy();
    };
  }, []);

  const nextCamera = async () => {
    const s = scanner.current;
    if (!s || cameras.length < 2) {
      return;
    }
    const current = cameras.findIndex((camera) => camera.id === s.camera());
    const next = cameras[(current + 1) % cameras.length];
    if (next) {
      await s.setCamera(next.id);
      showToast(next.label || 'Kamera přepnuta');
      setTorch((await s.hasTorch()) ? false : null);
    }
  };

  const onSubmit = (event: Event) => {
    event.preventDefault();
    if (!isLookupInput(input)) {
      showToast('Zadejte kód vstupenky (6 znaků 0–9, A–F) nebo číslo rezervace.');
      return;
    }
    void lookup(input.trim());
    setInput('');
  };

  return (
    <>
      <div ref={cameraRef} class="check-camera-view" aria-hidden="true" />

      <header class="check-top">
        <p class="check-stats" aria-live="polite">
          Na plese <strong>{stats.used}</strong> / {stats.total}
        </p>
        <nav class="check-buttons" aria-label="Ovládání">
          {cameras.length > 1 && (
            <button type="button" class="round" onClick={() => void nextCamera()} aria-label="Přepnout kameru" title="Přepnout kameru">
              <span aria-hidden="true">⟲</span>
            </button>
          )}
          {torch !== null && (
            <button
              type="button"
              class="round"
              aria-pressed={torch}
              onClick={() => void scanner.current?.toggleTorch().then(setTorch)}
              aria-label="Svítilna"
              title="Svítilna"
            >
              <span aria-hidden="true">{torch ? '◉' : '○'}</span>
            </button>
          )}
          <button type="button" class="round" onClick={toggleFullscreen} aria-label="Celá obrazovka" title="Celá obrazovka">
            <span aria-hidden="true">⛶</span>
          </button>
          {install && (
            <button
              type="button"
              class="round"
              onClick={() => void install.prompt().then(() => setInstall(null))}
              aria-label="Nainstalovat na plochu"
              title="Nainstalovat na plochu"
            >
              <span aria-hidden="true">⇩</span>
            </button>
          )}
          <a class="round" href={data.back} aria-label="Zpět do administrace" title="Zpět do administrace">
            <span aria-hidden="true">✕</span>
          </a>
        </nav>
      </header>

      {cameraError && <p class="check-note">{cameraError}</p>}
      {toast && (
        <p class="check-toast" role="alert" onClick={() => setToast(null)}>
          {toast}
        </p>
      )}

      <form class="check-manual" onSubmit={onSubmit}>
        <label for="check-input" class="visually-hidden">
          Kód vstupenky nebo číslo rezervace
        </label>
        <input
          id="check-input"
          value={input}
          onInput={(event) => setInput(event.currentTarget.value)}
          placeholder="Kód vstupenky nebo č. rezervace"
          autocomplete="off"
          autocapitalize="characters"
          spellcheck={false}
          maxLength={12}
          enterKeyHint="search"
        />
        <button type="submit" class="check-button" disabled={busy}>
          Ověřit
        </button>
      </form>

      {result && (
        <ResultCard
          result={result}
          justUsed={justUsed}
          busy={busy}
          onUse={(code) => void act(() => api.use(code), true)}
          onUseReservation={(id) => void act(() => api.useReservation(id), true)}
          onUndo={(code) => void act(() => api.undo(code), false)}
          onClose={close}
        />
      )}
    </>
  );
}
