import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { nearestSeat, seatDescription, seatStatus, type MapPoint } from '../logic';
import type { ApiState, Direction, HallLayout, HallTable, Prices } from '../types';
import { between, isZoomed, panBy, pad, toViewBox, union, unitsPerPixel, zoomAround, type Box } from '../viewbox';

interface Props {
  layout: HallLayout;
  state: ApiState;
  prices: Prices;
  disabled: boolean;
  onToggle: (seatId: number) => void;
}

const Keys: Record<string, Direction> = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down' };
/** A seat at least this big on the screen (px) is chosen by a tap; a smaller one zooms to its table first. */
const DirectSeatPx = 22;
const DragPx = 6;
const AnimationMs = 250;
const ZoomStep = 1.6;

/**
 * Hall plan: the SVG drawing (HallLayout.svg) inlined into the page. Seats are its shapes (found by
 * svg_id), tables the groups table-N around them. One simple model:
 *   - it starts with the whole hall fitted to the screen,
 *   - a tap on a table (or on a seat too small to hit) zooms to that table, a tap on a seat big
 *     enough chooses it,
 *   - "+", "−" and "Celý sál" are always on the map; dragging, two fingers and Ctrl + wheel move
 *     and zoom it as well,
 *   - while the whole hall is shown, a swipe scrolls the page; once zoomed in, it moves the map.
 * Seats are buttons for clicks, keyboard (one tab stop, arrow keys, Escape = whole hall) and screen readers.
 */
export function HallMap({ layout, state, prices, disabled, onToggle }: Props) {
  const [svg, setSvg] = useState<SVGSVGElement | null>(null);
  const [failed, setFailed] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const [table, setTable] = useState<HallTable | null>(null);
  const holder = useRef<HTMLDivElement>(null);
  const hall = useRef<Box | null>(null);
  const view = useRef<Box | null>(null);
  const animation = useRef(0);
  const focusId = useRef<number | null>(null);
  // Event handlers are attached once per plan; they read the current values from here.
  const current = useRef({ state, disabled, onToggle, table });
  current.current = { state, disabled, onToggle, table };

  const tables = useMemo(() => new Map(layout.tables.map((t) => [t.id, t])), [layout.tables]);

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
        // The drawing may have wide empty margins: show only what is drawn.
        const content = pad(element.getBBox(), 0.02);
        hall.current = content;
        show(element, content);
        setSvg(element);
      })
      .catch(() => !cancelled && setFailed(true));
    return () => {
      cancelled = true;
    };
  }, [layout.svg]);

  /** Shape of each seat in the drawing (and its number label, if the drawing has label-seat-N). */
  const shapes = useMemo(() => {
    const map = new Map<number, { shape: SVGElement; label: SVGElement | null }>();
    for (const seat of layout.seats) {
      const shape = find(svg, seat.svg_id);
      if (shape) {
        shape.dataset['seat'] = String(seat.id);
        const label = find(svg, `label-seat-${seat.id}`);
        if (label) {
          label.setAttribute('aria-hidden', 'true');
          // Keep the drawing's own classes (font size, colour); the state class is added to them.
          label.dataset['classes'] ??= label.getAttribute('class') ?? '';
          fitLabel(shape, label);
        }
        map.set(seat.id, { shape, label });
      }
    }
    return map;
  }, [svg, layout.seats]);

  // Paint the state into the drawing.
  useEffect(() => {
    const selectable = layout.seats.filter((seat) => seatStatus(seat.id, state) !== 'taken' && shapes.has(seat.id));
    const tabStop = selectable.find((seat) => seat.id === focusId.current) ?? selectable[0];
    for (const seat of layout.seats) {
      const found = shapes.get(seat.id);
      if (!found) {
        continue;
      }
      const status = seatStatus(seat.id, state);
      const { shape, label } = found;
      shape.setAttribute('class', `seat seat--${status}`);
      label?.setAttribute('class', `${label.dataset['classes'] ?? ''} seat-label seat-label--${status}`.trim());
      shape.setAttribute('role', 'button');
      shape.setAttribute('aria-label', seatDescription(seat, tables.get(seat.table_id ?? 0), status, prices.seat));
      shape.setAttribute('aria-pressed', String(status === 'mine'));
      shape.setAttribute('aria-disabled', String(disabled || status === 'taken'));
      shape.setAttribute('tabindex', seat.id === tabStop?.id ? '0' : '-1');
    }
  }, [shapes, state, disabled, prices, layout, tables]);

  const show = (element: SVGSVGElement, box: Box) => {
    view.current = box;
    element.setAttribute('viewBox', toViewBox(box));
    if (hall.current) {
      setZoomed(isZoomed(box, hall.current));
    }
  };

  /** Shows a part of the plan, animated unless the visitor prefers reduced motion. */
  const animateTo = (target: Box) => {
    const element = svg;
    const from = view.current;
    cancelAnimationFrame(animation.current);
    if (!element || !from || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      if (element) {
        show(element, target);
      }
      return;
    }
    const start = performance.now();
    const step = (now: number) => {
      const t = Math.min(1, (now - start) / AnimationMs);
      show(element, between(from, target, 1 - (1 - t) ** 3));
      if (t < 1) {
        animation.current = requestAnimationFrame(step);
      }
    };
    animation.current = requestAnimationFrame(step);
  };

  const showHall = () => {
    setTable(null);
    if (hall.current) {
      animateTo(hall.current);
    }
  };

  const showTable = (number: number) => {
    const box = svg ? tableBox(svg, number) : null;
    if (!box) {
      return;
    }
    setTable(tables.get(number) ?? { id: number, label: String(number), name: null });
    animateTo(box);
  };

  const zoomBy = (factor: number) => {
    if (svg && view.current && hall.current) {
      const v = view.current;
      setTable(null);
      animateTo(zoomAround(v, factor, v.x + v.width / 2, v.y + v.height / 2, hall.current));
    }
  };

  // Clicks, keys and gestures on the drawing (attached once per loaded plan).
  useEffect(() => {
    if (!svg) {
      return;
    }
    const pointers = new Map<number, { x: number; y: number }>();
    let travelled = 0;

    const toggle = (id: number) => {
      const { state, disabled, onToggle } = current.current;
      if (!disabled && seatStatus(id, state) !== 'taken') {
        onToggle(id);
      }
    };
    const seatPx = (shape: Element) => shape.getBoundingClientRect().width;
    /** The visitor moved the map by hand: it no longer shows "the table". */
    const moved = (box: Box) => {
      setTable(null);
      show(svg, box);
    };

    const onClick = (event: MouseEvent) => {
      if (travelled > DragPx) {
        travelled = 0;
        return; // the end of a drag, not a tap
      }
      const target = event.target instanceof Element ? event.target : null;
      const shape = target?.closest<SVGElement>('[data-seat]');
      const tableNumber = target ? tableOf(target) : null;
      if (shape && seatPx(shape) >= DirectSeatPx) {
        toggle(Number(shape.dataset['seat']));
      } else if (tableNumber !== null && tableNumber !== current.current.table?.id) {
        showTable(tableNumber);
      }
    };

    const onFocus = (event: FocusEvent) => {
      const shape = event.target instanceof Element ? event.target.closest<SVGElement>('[data-seat]') : null;
      if (!shape) {
        return;
      }
      focusId.current = Number(shape.dataset['seat']);
      // Moving through small seats with the keyboard: show the table of the focused seat.
      const tableNumber = tableOf(shape);
      if (seatPx(shape) < DirectSeatPx && tableNumber !== null) {
        showTable(tableNumber);
      }
    };

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        showHall();
        return;
      }
      const shape = event.target instanceof Element ? event.target.closest<SVGElement>('[data-seat]') : null;
      if (!shape) {
        return;
      }
      const id = Number(shape.dataset['seat']);
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
      for (const [seatId, { shape: s }] of shapes) {
        if (seatId === id || seatStatus(seatId, current.current.state) !== 'taken') {
          const box = s.getBoundingClientRect();
          points.push({ id: seatId, x: box.left + box.width / 2, y: box.top + box.height / 2 });
        }
      }
      const from = points.find((point) => point.id === id);
      const next = from ? nearestSeat(from, points, direction) : null;
      const target = next ? shapes.get(next.id)?.shape : undefined;
      if (next && target) {
        shape.setAttribute('tabindex', '-1');
        target.setAttribute('tabindex', '0');
        target.focus();
      }
    };

    // Drag to pan, two fingers to zoom. Touch swipes over the whole hall scroll the page (CSS touch-action).
    const onPointerDown = (event: PointerEvent) => {
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      travelled = 0;
    };
    const onPointerMove = (event: PointerEvent) => {
      const last = pointers.get(event.pointerId);
      if (!last || !view.current || !hall.current) {
        return;
      }
      if (pointers.size === 1) {
        const scale = unitsPerPixel(view.current, svg.clientWidth, svg.clientHeight);
        const dx = event.clientX - last.x;
        const dy = event.clientY - last.y;
        travelled += Math.abs(dx) + Math.abs(dy);
        if (travelled > DragPx) {
          svg.setPointerCapture(event.pointerId);
          moved(panBy(view.current, -dx * scale, -dy * scale, hall.current));
        }
      } else if (pointers.size === 2) {
        const [a, b] = [...pointers.entries()].map(([pid, p]) => (pid === event.pointerId ? { x: event.clientX, y: event.clientY } : p));
        const [oldA, oldB] = [...pointers.values()];
        if (a && b && oldA && oldB) {
          const factor = Math.hypot(a.x - b.x, a.y - b.y) / Math.max(1, Math.hypot(oldA.x - oldB.x, oldA.y - oldB.y));
          const middle = clientToPlan(svg, (a.x + b.x) / 2, (a.y + b.y) / 2);
          travelled += DragPx + 1;
          moved(zoomAround(view.current, factor, middle.x, middle.y, hall.current));
        }
      }
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    };
    const onPointerUp = (event: PointerEvent) => {
      pointers.delete(event.pointerId);
    };
    // The wheel scrolls the page; Ctrl + wheel (and a touchpad pinch, which browsers send the same way) zooms.
    const onWheel = (event: WheelEvent) => {
      if (!event.ctrlKey || !view.current || !hall.current) {
        return;
      }
      event.preventDefault();
      const point = clientToPlan(svg, event.clientX, event.clientY);
      moved(zoomAround(view.current, Math.exp(-event.deltaY * 0.01), point.x, point.y, hall.current));
    };

    svg.addEventListener('click', onClick);
    svg.addEventListener('focusin', onFocus);
    svg.addEventListener('keydown', onKeyDown);
    svg.addEventListener('pointerdown', onPointerDown);
    svg.addEventListener('pointermove', onPointerMove);
    svg.addEventListener('pointerup', onPointerUp);
    svg.addEventListener('pointercancel', onPointerUp);
    svg.addEventListener('wheel', onWheel, { passive: false });
    return () => {
      svg.removeEventListener('click', onClick);
      svg.removeEventListener('focusin', onFocus);
      svg.removeEventListener('keydown', onKeyDown);
      svg.removeEventListener('pointerdown', onPointerDown);
      svg.removeEventListener('pointermove', onPointerMove);
      svg.removeEventListener('pointerup', onPointerUp);
      svg.removeEventListener('pointercancel', onPointerUp);
      svg.removeEventListener('wheel', onWheel);
    };
  }, [svg, shapes]);

  return (
    <div class={`hall${zoomed ? ' hall--zoomed' : ''}`}>
      {failed && <p class="message message--error">Plánek sálu se nepodařilo načíst. Zkuste prosím obnovit stránku.</p>}
      <div class="map-view hall-plan" ref={holder} />

      <div class="map-top">
        {zoomed ? (
          <nav class="map-crumbs" aria-label="Kde v plánku jste">
            <button type="button" class="map-chip map-chip--back" onClick={showHall}>
              ‹ Celý sál
            </button>
            {table && (
              <span class="map-chip map-chip--current" aria-current="location">
                Stůl {table.label}
                {table.name ? ` – ${table.name}` : ''}
              </span>
            )}
          </nav>
        ) : (
          <p class="map-chip map-chip--hint">Klepněte na stůl nebo místo</p>
        )}
      </div>

      <div class="map-controls" role="group" aria-label="Ovládání plánku">
        <button type="button" class="map-control" onClick={() => zoomBy(ZoomStep)} aria-label="Přiblížit" title="Přiblížit">+</button>
        <button type="button" class="map-control" onClick={() => zoomBy(1 / ZoomStep)} disabled={!zoomed} aria-label="Oddálit" title="Oddálit">−</button>
        <button type="button" class="map-control map-control--fit" onClick={showHall} disabled={!zoomed} aria-label="Celý sál" title="Celý sál">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" /></svg>
        </button>
      </div>
    </div>
  );
}

/** Drawings have small seat numbers: scale a number to about half the height of its seat. */
function fitLabel(shape: SVGElement, label: SVGElement): void {
  if (!(shape instanceof SVGGraphicsElement) || !(label instanceof SVGGraphicsElement)) {
    return;
  }
  const seat = shape.getBBox();
  const text = label.getBBox();
  if (text.height > 0 && seat.height > 0) {
    const scale = Math.min(4, Math.max(1, (seat.height * 0.55) / text.height, (seat.width * 0.6) / Math.max(text.width, text.height)));
    label.style.transform = `scale(${scale.toFixed(2)})`;
  }
}

function find(svg: SVGSVGElement | null, id: string): SVGElement | null {
  return svg?.querySelector<SVGElement>(`[id="${CSS.escape(id)}"]`) ?? null;
}

/** Number of the table an element belongs to: inside a group table-N, or its texts table-N-name / label-table-N. */
function tableOf(element: Element): number | null {
  for (let node: Element | null = element; node && !(node instanceof SVGSVGElement); node = node.parentElement) {
    const match = /^(?:label-)?table-(\d+)(?:-name)?$/.exec(node.id);
    if (match?.[1]) {
      return Number(match[1]);
    }
  }
  return null;
}

/** The table with its seats and its name, with a margin, in plan units. */
function tableBox(svg: SVGSVGElement, number: number): Box | null {
  const parts = [find(svg, `table-${number}`), find(svg, `table-${number}-name`)]
    .filter((element): element is SVGElement => element !== null)
    .map((element) => elementBox(svg, element));
  const box = union(parts);
  return box ? pad(box, 0.25) : null;
}

/** Bounding box of an element in plan units (whatever transforms the drawing uses). */
function elementBox(svg: SVGSVGElement, element: Element): Box {
  const rect = element.getBoundingClientRect();
  const a = clientToPlan(svg, rect.left, rect.top);
  const b = clientToPlan(svg, rect.right, rect.bottom);
  return { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), width: Math.abs(b.x - a.x), height: Math.abs(b.y - a.y) };
}

function clientToPlan(svg: SVGSVGElement, x: number, y: number): { x: number; y: number } {
  const matrix = svg.getScreenCTM();
  if (!matrix) {
    return { x, y };
  }
  const point = new DOMPoint(x, y).matrixTransform(matrix.inverse());
  return { x: point.x, y: point.y };
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
  root.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  root.setAttribute('role', 'group');
  root.setAttribute('aria-label', 'Plánek sálu');
  return document.importNode(root, true);
}
