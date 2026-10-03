import { useEffect, useRef, useState } from 'preact/hooks';
import { formatCountdown } from '../logic';

interface Props {
  /** Seconds left according to the latest server state. */
  seconds: number;
  onExpire: () => void;
  onExtend: () => void;
}

/** From this many seconds on, the countdown warns and offers to extend. */
const WarnAt = 60;
/** Screen readers hear the time only at these moments, not every second. */
const AnnounceAt = [60, 20];

/** Remaining time of the seat hold; re-synchronised with every server state. */
export function Countdown({ seconds, onExpire, onExtend }: Props) {
  const deadline = useRef(Date.now() + seconds * 1000);
  const [left, setLeft] = useState(seconds);
  const [announcement, setAnnouncement] = useState('');
  const expired = useRef(false);

  useEffect(() => {
    deadline.current = Date.now() + seconds * 1000;
    expired.current = false;
    setLeft(seconds);
  }, [seconds]);

  useEffect(() => {
    const timer = setInterval(() => {
      const remaining = Math.max(0, Math.round((deadline.current - Date.now()) / 1000));
      setLeft(remaining);
      if (AnnounceAt.includes(remaining)) {
        setAnnouncement(`Vybraná místa držíme ještě ${remaining} sekund. Můžete čas prodloužit.`);
      }
      if (remaining === 0 && !expired.current) {
        expired.current = true;
        onExpire();
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [onExpire]);

  const urgent = left <= WarnAt;
  return (
    <div class={`countdown${urgent ? ' countdown--urgent' : ''}`}>
      <span>
        Místa držíme ještě <strong>{formatCountdown(left)}</strong>
      </span>
      {urgent && (
        <button type="button" class="button button--small" onClick={onExtend}>
          Prodloužit
        </button>
      )}
      <span class="visually-hidden" role="status">
        {announcement}
      </span>
    </div>
  );
}
