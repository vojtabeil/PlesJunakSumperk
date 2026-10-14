// Phone features of the entrance check: vibration and sound for a result, keeping the screen on.

import type { CheckStatus } from './types';

let audio: AudioContext | null = null;

/** Short signal: one high beep = go, two low beeps = stop (also vibration where supported). */
export function signal(status: CheckStatus): void {
  const good = status === 'ok';
  navigator.vibrate?.(good ? 120 : [200, 100, 200]);
  try {
    audio ??= new AudioContext();
    const beeps = good ? [0] : [0, 0.25];
    for (const start of beeps) {
      const oscillator = audio.createOscillator();
      const gain = audio.createGain();
      oscillator.frequency.value = good ? 880 : status === 'unpaid' ? 520 : 300;
      gain.gain.value = 0.15;
      oscillator.connect(gain).connect(audio.destination);
      oscillator.start(audio.currentTime + start);
      oscillator.stop(audio.currentTime + start + 0.15);
    }
  } catch {
    // Sound is only a bonus (e.g. blocked before the first tap).
  }
}

/** Keeps the screen on while the check is open (re-acquired when the page becomes visible again). */
export function keepScreenOn(): () => void {
  let lock: WakeLockSentinel | null = null;
  const acquire = async () => {
    try {
      if (document.visibilityState === 'visible' && 'wakeLock' in navigator) {
        lock = await navigator.wakeLock.request('screen');
      }
    } catch {
      lock = null;
    }
  };
  const onVisibility = () => void acquire();
  void acquire();
  document.addEventListener('visibilitychange', onVisibility);
  return () => {
    document.removeEventListener('visibilitychange', onVisibility);
    void lock?.release();
  };
}

export function toggleFullscreen(): void {
  if (document.fullscreenElement) {
    void document.exitFullscreen();
  } else {
    void document.documentElement.requestFullscreen?.();
  }
}
