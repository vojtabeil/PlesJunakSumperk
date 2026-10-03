import { useEffect, useRef, useState } from 'preact/hooks';
import { formatCountdown } from '../logic';

interface Props {
  /** Seconds left according to the latest server state. */
  seconds: number;
  onExpire: () => void;
}

/** Remaining time of the seat hold; re-synchronised with every server state. */
export function Countdown({ seconds, onExpire }: Props) {
  const deadline = useRef(Date.now() + seconds * 1000);
  const [left, setLeft] = useState(seconds);
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
      if (remaining === 0 && !expired.current) {
        expired.current = true;
        onExpire();
      }
    }, 1000);
    return () => clearInterval(timer);
  }, [onExpire]);

  return (
    <p class={`countdown${left <= 30 ? ' countdown--urgent' : ''}`}>
      Vybraná místa držíme ještě {formatCountdown(left)}. Každá změna čas obnoví.
    </p>
  );
}
