import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { nearestSeat, seatDescription, seatStatus, type MapPoint } from '../logic';
import type { ApiState, Direction, HallLayout, Prices } from '../types';
import { between, clampView, isZoomed, pad, panWithin, toViewBox, union, unitsPerPixel, zoomAround, type Box } from '../viewbox';

interface Props {
  layout: HallLayout;
  state: ApiState;
  prices: Prices;
  disabled: boolean;
  onToggle: (seatId: number) => void;
  /** Whether the map takes the wheel and one-finger swipes (see below); owned by the parent. */
  awake: boolean;
  onAwake: (awake: boolean) => void;
}

const Keys: Record<string, Direction> = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down' };
/** A seat at least this big on the screen (px) is chosen by a tap; a smaller one zooms to its table first. */
const DirectSeatPx = 22;
const DragPx = 6;
const AnimationMs = 250;
const ZoomStep = 1.6;
/** The map falls asleep once less than this share of it is on the screen. */
const SleepVisibility = 0.3;
/** A finger dragging the map this far (px) past its edge hands the gesture over to the page. */
const HandOffPx = 50;
/** How much the map follows the finger past its edge before the hand-off (rubber band). */
const Stretch = 0.4;
/** Momentum after a swipe: speed kept per 16 ms frame, and the speed (px/ms) where it stops. */
const Friction = 0.94;
const StopSpeed = 0.02;
/** A finger resting this long (ms) before it lifts leaves no momentum. */
const RestMs = 80;

/**
 * Hall plan: the SVG drawing (HallLayout.svg) inlined into the page. Seats are its shapes (found by
 * svg_id), tables the groups table-A1 around them.
 *
 * The map sleeps until the visitor works with it, like maps embedded in other pages: asleep, the wheel and
 * swipes scroll the page and the map moves with it. A click or tap (which also does its action), two fingers,
 * a double click, the middle button or a control wakes it: then the wheel zooms and dragging pans.
 * It falls asleep again on Escape, a click outside, scrolling it off the screen or the order button.
 * A finger dragging the awake map past its edge stretches it a little and then scrolls the page instead
 * (the map then falls asleep); swipes keep their momentum.
 * Ctrl + wheel (and a touchpad pinch) zooms always. Zoom and position are kept while asleep.
 *
 * A tap on a table (or on a seat too small to hit) zooms to that table, a tap on a seat big enough chooses it.
 * Seats are buttons for clicks, keyboard (one tab stop, arrow keys) and screen readers.
 */
export function HallMap({ layout, state, prices, disabled, onToggle, awake, onAwake }: Props) {
  const [svg, setSvg] = useState<SVGSVGElement | null>(null);
  const [failed, setFailed] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const holder = useRef<HTMLDivElement>(null);
  const hall = useRef<Box | null>(null);
  const view = useRef<Box | null>(null);
  /** Label of the table the map shows ("A1"), null = the hall or a view moved by hand. */
  const table = useRef<string | null>(null);
  const animation = useRef(0);
  const focusId = useRef<number | null>(null);
  // Event handlers are attached once per plan; they read the current values from here.
  const current = useRef({ state, disabled, onToggle, awake, onAwake });
  current.current = { state, disabled, onToggle, awake, onAwake };

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

  /** Shape of each seat in the drawing (and its number label label-<svg id>, e.g. label-seat-A1-5, if the drawing has it). */
  const shapes = useMemo(() => {
    const map = new Map<number, { shape: SVGElement; label: SVGElement | null }>();
    for (const seat of layout.seats) {
      const shape = find(svg, seat.svg_id);
      if (shape) {
        shape.dataset['seat'] = String(seat.id);
        const label = find(svg, `label-${seat.svg_id}`);
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

  // Paint the state into the drawing. A request in progress (disabled) does not repaint the seats.
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
      setAttributes(shape, {
        class: `seat seat--${status}`,
        role: 'button',
        'aria-label': seatDescription(seat, tables.get(seat.table_id ?? 0), status, prices.seat),
        'aria-pressed': String(status === 'mine'),
        'aria-disabled': String(status === 'taken'),
        tabindex: seat.id === tabStop?.id ? '0' : '-1',
      });
      if (label) {
        setAttributes(label, { class: `${label.dataset['classes'] ?? ''} seat-label seat-label--${status}`.trim() });
      }
    }
  }, [shapes, state, prices, layout, tables]);

  // Asleep again once the map is mostly off the screen.
  useEffect(() => {
    const element = root.current;
    if (!element || typeof IntersectionObserver === 'undefined') {
      return;
    }
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry && entry.intersectionRatio < SleepVisibility) {
          current.current.onAwake(false);
        }
      },
      { threshold: [SleepVisibility] },
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  // Asleep again after a click or tap outside the map.
  useEffect(() => {
    if (!awake) {
      return;
    }
    const onDown = (event: PointerEvent) => {
      if (event.target instanceof Node && !root.current?.contains(event.target)) {
        onAwake(false);
      }
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [awake]);

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
    table.current = null;
    if (hall.current) {
      animateTo(hall.current);
    }
  };

  const showTable = (label: string) => {
    const box = svg ? tableBox(svg, label) : null;
    if (box) {
      table.current = label;
      animateTo(box);
    }
  };

  /** A view kept within the hall's edges on this screen. */
  const fit = (element: SVGSVGElement, box: Box, hallBox: Box) => clampView(box, hallBox, element.clientWidth, element.clientHeight);

  const zoomBy = (factor: number) => {
    if (svg && view.current && hall.current) {
      const v = view.current;
      table.current = null;
      animateTo(fit(svg, zoomAround(v, factor, v.x + v.width / 2, v.y + v.height / 2, hall.current), hall.current));
    }
  };

  /** A control of the map: wakes it and does its action. */
  const control = (action: () => void) => () => {
    onAwake(true);
    action();
  };

  // Clicks, keys and gestures on the drawing (attached once per loaded plan).
  useEffect(() => {
    if (!svg) {
      return;
    }
    const pointers = new Map<number, { x: number; y: number; type: string }>();
    let travelled = 0;
    /** Finger drag past the map's edge (px, + = the page would scroll down) and whether the page took over. */
    let overscroll = 0;
    let handedOff = false;
    /** Speed of the dragged content (px/ms, + = content moves up/left) for the momentum. */
    let speed = { x: 0, y: 0, time: 0 };
    let glide = 0;

    const wake = () => {
      if (!current.current.awake) {
        current.current.onAwake(true);
      }
    };
    const toggle = (id: number) => {
      const { state, disabled, onToggle } = current.current;
      if (!disabled && seatStatus(id, state) !== 'taken') {
        onToggle(id);
      }
    };
    const seatPx = (shape: Element) => shape.getBoundingClientRect().width;
    /** The visitor moved the map by hand: it no longer shows "the table". */
    const moved = (box: Box) => {
      table.current = null;
      show(svg, box);
    };

    /** Keeps getting the pointer's moves outside the map; a pointer that is already gone cannot be captured. */
    const capture = (pointerId: number) => {
      try {
        svg.setPointerCapture(pointerId);
      } catch {
        // the gesture goes on without the capture
      }
    };

    /** The rubber band: the drawing follows the finger a little past the map's edge. */
    const stretch = (px: number, release = false) => {
      const style = holder.current?.style;
      if (style) {
        style.transition = release ? 'transform 0.25s ease-out' : '';
        style.transform = px ? `translateY(${(-px * Stretch).toFixed(1)}px)` : '';
      }
    };

    /** Content moved by the finger or the mouse (px); a finger past the edge goes on to the page. */
    const drag = (moveX: number, moveY: number, touch: boolean) => {
      if (!view.current || !hall.current) {
        return;
      }
      if (handedOff) {
        window.scrollBy(0, moveY);
        return;
      }
      let restY = moveY;
      // Back from past the edge: first undo the stretch.
      if (overscroll !== 0 && Math.sign(moveY) !== Math.sign(overscroll)) {
        const back = Math.sign(moveY) * Math.min(Math.abs(moveY), Math.abs(overscroll));
        overscroll += back;
        restY -= back;
        stretch(overscroll);
      }
      const scale = unitsPerPixel(view.current, svg.clientWidth, svg.clientHeight);
      const pan = panWithin(view.current, moveX * scale, restY * scale, hall.current, svg.clientWidth, svg.clientHeight);
      moved(pan.view);
      // Rounding leaves tiny rests far from the edge.
      const pastEdge = Math.abs(pan.restY / scale) < 0.5 ? 0 : pan.restY / scale;
      if (!touch || pastEdge === 0) {
        return;
      }
      overscroll += pastEdge;
      if (Math.abs(overscroll) > HandOffPx) {
        handedOff = true;
        window.scrollBy(0, overscroll - Math.sign(overscroll) * HandOffPx);
        overscroll = 0;
        stretch(0, true);
      } else {
        stretch(overscroll);
      }
    };

    /** Momentum after a swipe; step returns false once nothing moves any more. */
    const coast = (step: (x: number, y: number) => boolean) => {
      let { x, y } = speed;
      if (performance.now() - speed.time > RestMs || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }
      let last = performance.now();
      const frame = (now: number) => {
        const dt = Math.min(32, now - last);
        last = now;
        const keep = Friction ** (dt / 16);
        x *= keep;
        y *= keep;
        if (Math.hypot(x, y) >= StopSpeed && step(x * dt, y * dt)) {
          glide = requestAnimationFrame(frame);
        }
      };
      glide = requestAnimationFrame(frame);
    };
    const coastMap = () =>
      coast((x, y) => {
        if (!view.current || !hall.current) {
          return false;
        }
        const scale = unitsPerPixel(view.current, svg.clientWidth, svg.clientHeight);
        const pan = panWithin(view.current, x * scale, y * scale, hall.current, svg.clientWidth, svg.clientHeight);
        moved(pan.view);
        return Math.abs(pan.restX) < Math.abs(x * scale) || Math.abs(pan.restY) < Math.abs(y * scale);
      });
    const coastPage = () =>
      coast((_, y) => {
        const before = window.scrollY;
        window.scrollBy(0, y);
        return window.scrollY !== before;
      });

    const onClick = (event: MouseEvent) => {
      if (travelled > DragPx) {
        travelled = 0;
        return; // the end of a drag, not a tap
      }
      if (event.detail > 1) {
        return; // the second click of a double click: it zooms (onDoubleClick)
      }
      wake();
      const target = event.target instanceof Element ? event.target : null;
      const shape = target?.closest<SVGElement>('[data-seat]');
      const tableLabel = target ? tableOf(target) : null;
      if (shape && seatPx(shape) >= DirectSeatPx) {
        toggle(Number(shape.dataset['seat']));
      } else if (tableLabel !== null && tableLabel !== table.current) {
        showTable(tableLabel);
      }
    };

    const onDoubleClick = (event: MouseEvent) => {
      if (view.current && hall.current) {
        wake();
        const point = clientToPlan(svg, event.clientX, event.clientY);
        table.current = null;
        animateTo(fit(svg, zoomAround(view.current, 2, point.x, point.y, hall.current), hall.current));
      }
    };

    const onFocus = (event: FocusEvent) => {
      const shape = event.target instanceof Element ? event.target.closest<SVGElement>('[data-seat]') : null;
      if (!shape) {
        return;
      }
      focusId.current = Number(shape.dataset['seat']);
      // Moving through small seats with the keyboard: show the table of the focused seat.
      const tableLabel = tableOf(shape);
      if (seatPx(shape) < DirectSeatPx && tableLabel !== null) {
        showTable(tableLabel);
      }
    };

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        current.current.onAwake(false);
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

    // Dragging pans (a finger only once awake: asleep, the browser scrolls the page, CSS touch-action);
    // two fingers wake the map and zoom; the mouse drags with the left or the middle button (which also wakes it).
    const onPointerDown = (event: PointerEvent) => {
      if (event.pointerType === 'mouse') {
        if (event.button > 1) {
          return;
        }
        if (event.button === 1) {
          wake();
        }
      }
      cancelAnimationFrame(glide);
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY, type: event.pointerType });
      travelled = 0;
      overscroll = 0;
      handedOff = false;
      speed = { x: 0, y: 0, time: 0 };
      if (pointers.size === 2) {
        wake();
      }
    };
    const onPointerMove = (event: PointerEvent) => {
      const last = pointers.get(event.pointerId);
      if (!last || !view.current || !hall.current) {
        return;
      }
      if (pointers.size === 1) {
        const dx = event.clientX - last.x;
        const dy = event.clientY - last.y;
        travelled += Math.abs(dx) + Math.abs(dy);
        // Asleep, a finger belongs to the page; the mouse may drag the map (it wakes it).
        if (travelled > DragPx && (current.current.awake || last.type === 'mouse')) {
          wake();
          capture(event.pointerId);
          drag(-dx, -dy, last.type !== 'mouse');
          const now = performance.now();
          const dt = Math.max(1, now - (speed.time || now - 16));
          speed = { x: 0.2 * speed.x + 0.8 * (-dx / dt), y: 0.2 * speed.y + 0.8 * (-dy / dt), time: now };
        }
      } else if (pointers.size === 2) {
        const [a, b] = [...pointers.entries()].map(([pid, p]) => (pid === event.pointerId ? { x: event.clientX, y: event.clientY } : p));
        const [oldA, oldB] = [...pointers.values()];
        if (a && b && oldA && oldB) {
          const factor = Math.hypot(a.x - b.x, a.y - b.y) / Math.max(1, Math.hypot(oldA.x - oldB.x, oldA.y - oldB.y));
          const middle = clientToPlan(svg, (a.x + b.x) / 2, (a.y + b.y) / 2);
          travelled += DragPx + 1;
          moved(fit(svg, zoomAround(view.current, factor, middle.x, middle.y, hall.current), hall.current));
        }
      }
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY, type: last.type });
    };
    const onPointerUp = (event: PointerEvent) => {
      const single = pointers.size === 1 && pointers.has(event.pointerId);
      pointers.delete(event.pointerId);
      if (!single || travelled <= DragPx) {
        return;
      }
      const lifted = event.type === 'pointerup';
      if (handedOff) {
        // The page took the gesture over: the map sleeps, the page keeps the swipe's momentum.
        current.current.onAwake(false);
        if (lifted) {
          coastPage();
        }
      } else if (overscroll !== 0) {
        stretch(0, true);
      } else if (lifted) {
        coastMap();
      }
      overscroll = 0;
      handedOff = false;
    };
    // Asleep, the wheel scrolls the page; awake (or with Ctrl, which a touchpad pinch sends too) it zooms.
    const onWheel = (event: WheelEvent) => {
      if ((!current.current.awake && !event.ctrlKey) || !view.current || !hall.current) {
        return;
      }
      event.preventDefault();
      const point = clientToPlan(svg, event.clientX, event.clientY);
      const rate = event.deltaMode === WheelEvent.DOM_DELTA_LINE ? 0.05 : event.ctrlKey ? 0.01 : 0.002;
      cancelAnimationFrame(glide);
      moved(fit(svg, zoomAround(view.current, Math.exp(-event.deltaY * rate), point.x, point.y, hall.current), hall.current));
    };
    // The middle button drags the map: no autoscroll of the browser (it starts on mousedown).
    const onMouseDown = (event: MouseEvent) => {
      if (event.button === 1) {
        event.preventDefault();
      }
    };

    svg.addEventListener('click', onClick);
    svg.addEventListener('mousedown', onMouseDown);
    svg.addEventListener('dblclick', onDoubleClick);
    svg.addEventListener('focusin', onFocus);
    svg.addEventListener('keydown', onKeyDown);
    svg.addEventListener('pointerdown', onPointerDown);
    svg.addEventListener('pointermove', onPointerMove);
    svg.addEventListener('pointerup', onPointerUp);
    svg.addEventListener('pointercancel', onPointerUp);
    svg.addEventListener('wheel', onWheel, { passive: false });
    return () => {
      svg.removeEventListener('click', onClick);
      svg.removeEventListener('mousedown', onMouseDown);
      svg.removeEventListener('dblclick', onDoubleClick);
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
    <div ref={root} class={`hall${awake ? ' hall--awake' : ''}`}>
      {failed && <p class="message message--error">Plánek sálu se nepodařilo načíst. Zkuste prosím obnovit stránku.</p>}
      <div class="map-view hall-plan" ref={holder} />

      <div class="map-controls" role="group" aria-label="Ovládání plánku">
        <button type="button" class="map-control" onClick={control(() => zoomBy(ZoomStep))} aria-label="Přiblížit" title="Přiblížit">+</button>
        <button type="button" class="map-control" onClick={control(() => zoomBy(1 / ZoomStep))} disabled={!zoomed} aria-label="Oddálit" title="Oddálit">−</button>
        <button type="button" class="map-control map-control--fit" onClick={control(showHall)} disabled={!zoomed} aria-label="Celý sál" title="Celý sál">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" /></svg>
        </button>
      </div>
    </div>
  );
}

/** Sets attributes only when they differ, so repainting the same state touches nothing in the drawing. */
function setAttributes(element: Element, attributes: Record<string, string>): void {
  for (const [name, value] of Object.entries(attributes)) {
    if (element.getAttribute(name) !== value) {
      element.setAttribute(name, value);
    }
  }
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

/** Label of the table an element belongs to: inside a group table-A1, or its texts table-A1-name / label-table-A1. */
function tableOf(element: Element): string | null {
  for (let node: Element | null = element; node && !(node instanceof SVGSVGElement); node = node.parentElement) {
    const match = /^(?:label-)?table-([A-Z]{1,3}\d{1,3})(?:-name)?$/.exec(node.id);
    if (match?.[1]) {
      return match[1];
    }
  }
  return null;
}

/** The table with its seats and its name, with a margin, in plan units. */
function tableBox(svg: SVGSVGElement, label: string): Box | null {
  const parts = [find(svg, `table-${label}`), find(svg, `table-${label}-name`)]
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
