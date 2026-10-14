import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { nearestSeat, seatDescription, seatStatus, type MapPoint } from '../logic';
import type { ApiState, Direction, HallLayout, Prices } from '../types';

interface Props {
  layout: HallLayout;
  state: ApiState;
  prices: Prices;
  disabled: boolean;
  onToggle: (seatId: number) => void;
}

/** Widths of the map (CSS) for the zoom buttons; "fit" = the whole hall in the box. */
const Zooms = ['100%', '720px', '1000px'] as const;
const Keys: Record<string, Direction> = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down' };

/**
 * Hall plan: the SVG drawing (HallLayout.svg) inlined into the page. The seats are its shapes
 * (found by svg_id): their class shows the state, and they are buttons for clicks, keyboard
 * (one tab stop, arrow keys move between seats) and screen readers. The map scrolls inside its own
 * box and can be zoomed; on a phone it starts zoomed so seats are easy to tap.
 */
export function HallMap({ layout, state, prices, disabled, onToggle }: Props) {
  const [zoom, setZoom] = useState(() => (window.matchMedia('(max-width: 900px)').matches ? 1 : 0));
  const [svg, setSvg] = useState<SVGSVGElement | null>(null);
  const [failed, setFailed] = useState(false);
  const holder = useRef<HTMLDivElement>(null);
  const focusId = useRef<number | null>(null);
  // Event handlers are attached once; they read the current props from here.
  const current = useRef({ state, disabled, onToggle });
  current.current = { state, disabled, onToggle };

  useEffect(() => {
    if (!layout.svg) {
      return;
    }
    let cancelled = false;
    fetch(layout.svg)
      .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
      .then((text) => {
        const element = parsePlan(text);
        if (cancelled || !holder.current) {
          return;
        }
        if (!element) {
          setFailed(true);
          return;
        }
        holder.current.replaceChildren(element);
        setSvg(element);
      })
      .catch(() => !cancelled && setFailed(true));
    return () => {
      cancelled = true;
    };
  }, [layout.svg]);

  /** Shape of each seat in the drawing. */
  const shapes = useMemo(() => {
    const map = new Map<number, SVGElement>();
    for (const seat of layout.seats) {
      const shape = svg?.querySelector<SVGElement>(`[id="${CSS.escape(seat.svg_id)}"]`);
      if (shape) {
        shape.dataset['seat'] = String(seat.id);
        map.set(seat.id, shape);
      }
    }
    return map;
  }, [svg, layout.seats]);

  // Paint the state into the drawing.
  useEffect(() => {
    const tables = new Map(layout.tables.map((table) => [table.id, table]));
    const selectable = layout.seats.filter((seat) => seatStatus(seat.id, state) !== 'taken' && shapes.has(seat.id));
    const tabStop = selectable.find((seat) => seat.id === focusId.current) ?? selectable[0];
    for (const seat of layout.seats) {
      const shape = shapes.get(seat.id);
      if (!shape) {
        continue;
      }
      const status = seatStatus(seat.id, state);
      shape.setAttribute('class', `seat seat--${status}`);
      shape.setAttribute('role', 'button');
      shape.setAttribute('aria-label', seatDescription(seat, tables.get(seat.table_id ?? 0), status, prices.seat));
      shape.setAttribute('aria-pressed', String(status === 'mine'));
      shape.setAttribute('aria-disabled', String(disabled || status === 'taken'));
      shape.setAttribute('tabindex', seat.id === tabStop?.id ? '0' : '-1');
    }
  }, [shapes, state, disabled, prices, layout]);

  // Clicks and keys on the drawing (attached once per loaded plan).
  useEffect(() => {
    if (!svg) {
      return;
    }
    const seatOf = (target: EventTarget | null): number | null => {
      const shape = target instanceof Element ? target.closest<SVGElement>('[data-seat]') : null;
      return shape ? Number(shape.dataset['seat']) : null;
    };
    const toggle = (id: number) => {
      const { state, disabled, onToggle } = current.current;
      if (!disabled && seatStatus(id, state) !== 'taken') {
        onToggle(id);
      }
    };
    const onClick = (event: MouseEvent) => {
      const id = seatOf(event.target);
      if (id !== null) {
        toggle(id);
      }
    };
    const onFocus = (event: FocusEvent) => {
      focusId.current = seatOf(event.target) ?? focusId.current;
    };
    const onKeyDown = (event: KeyboardEvent) => {
      const id = seatOf(event.target);
      if (id === null) {
        return;
      }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggle(id);
        return;
      }
      const direction = Keys[event.key];
      if (!direction) {
        return;
      }
      event.preventDefault();
      const points: MapPoint[] = [];
      for (const [seatId, shape] of shapes) {
        if (seatId === id || seatStatus(seatId, current.current.state) !== 'taken') {
          const box = shape.getBoundingClientRect();
          points.push({ id: seatId, x: box.left + box.width / 2, y: box.top + box.height / 2 });
        }
      }
      const from = points.find((point) => point.id === id);
      const next = from ? nearestSeat(from, points, direction) : null;
      const shape = next ? shapes.get(next.id) : undefined;
      if (next && shape) {
        shapes.get(id)?.setAttribute('tabindex', '-1');
        shape.setAttribute('tabindex', '0');
        focusId.current = next.id;
        shape.focus();
      }
    };
    svg.addEventListener('click', onClick);
    svg.addEventListener('focusin', onFocus);
    svg.addEventListener('keydown', onKeyDown);
    return () => {
      svg.removeEventListener('click', onClick);
      svg.removeEventListener('focusin', onFocus);
      svg.removeEventListener('keydown', onKeyDown);
    };
  }, [svg, shapes]);

  if (!layout.svg) {
    return <p class="message">Plánek sálu připravujeme. Zatím lze koupit jen lístky bez místenky.</p>;
  }

  return (
    <div class="hall">
      <div class="zoom" role="group" aria-label="Velikost plánku">
        <button type="button" class="zoom-button" disabled={zoom === 0} onClick={() => setZoom(zoom - 1)} aria-label="Oddálit plánek">
          −
        </button>
        <button type="button" class="zoom-button" disabled={zoom === Zooms.length - 1} onClick={() => setZoom(zoom + 1)} aria-label="Přiblížit plánek">
          +
        </button>
        <button type="button" class="link-button" disabled={zoom === 0} onClick={() => setZoom(0)}>
          celý sál
        </button>
      </div>
      {failed && <p class="message message--error">Plánek sálu se nepodařilo načíst. Zkuste prosím obnovit stránku.</p>}
      <div class="map-scroll" tabIndex={-1}>
        <div class="map hall-plan" style={{ width: Zooms[zoom] }} ref={holder} />
      </div>
      <p class="hint">Plánkem lze posouvat prstem do stran. Na klávesnici se mezi místy pohybujete šipkami.</p>
    </div>
  );
}

/**
 * The SVG element of the plan. The server sanitizes the plan on upload; this is a second line of
 * defence before it goes into the page.
 */
function parsePlan(text: string): SVGSVGElement | null {
  const doc = new DOMParser().parseFromString(text, 'image/svg+xml');
  const root = doc.documentElement;
  if (!(root instanceof SVGSVGElement)) {
    return null;
  }
  root.querySelectorAll('script, foreignObject').forEach((element) => element.remove());
  for (const element of [root, ...root.querySelectorAll('*')]) {
    for (const attribute of [...element.attributes]) {
      if (attribute.name.toLowerCase().startsWith('on')) {
        element.removeAttribute(attribute.name);
      }
    }
  }
  root.removeAttribute('width');
  root.removeAttribute('height');
  root.setAttribute('role', 'group');
  root.setAttribute('aria-label', 'Plánek sálu');
  return document.importNode(root, true);
}
