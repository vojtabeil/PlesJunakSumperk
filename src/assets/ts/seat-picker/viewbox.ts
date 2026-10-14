// The visible part of the SVG plan (its viewBox): fitting, zooming and panning. Pure functions,
// unit-tested in tests/ts/viewbox.test.ts.

export interface Box {
  x: number;
  y: number;
  width: number;
  height: number;
}

/** How far in and out the free map navigation may zoom, relative to the whole hall. */
export const MinZoomOut = 1.25;
export const MaxZoomIn = 14;

/** The box grown by a margin (a share of its larger side) on every side. */
export function pad(box: Box, share: number): Box {
  const margin = Math.max(box.width, box.height) * share;
  return { x: box.x - margin, y: box.y - margin, width: box.width + 2 * margin, height: box.height + 2 * margin };
}

/** Smallest box containing all given boxes. */
export function union(boxes: Box[]): Box | null {
  if (boxes.length === 0) {
    return null;
  }
  const left = Math.min(...boxes.map((b) => b.x));
  const top = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.width));
  const bottom = Math.max(...boxes.map((b) => b.y + b.height));
  return { x: left, y: top, width: right - left, height: bottom - top };
}

/**
 * Zooms by a factor (> 1 = closer) keeping the point (in plan units) at the same place on the screen.
 * The size stays between MaxZoomIn and MinZoomOut of the hall.
 */
export function zoomAround(view: Box, factor: number, pointX: number, pointY: number, hall: Box): Box {
  const minWidth = hall.width / MaxZoomIn;
  const maxWidth = hall.width * MinZoomOut;
  const width = Math.min(maxWidth, Math.max(minWidth, view.width / factor));
  const scale = width / view.width;
  const zoomed = {
    x: pointX - (pointX - view.x) * scale,
    y: pointY - (pointY - view.y) * scale,
    width,
    height: view.height * scale,
  };
  return keepInside(zoomed, hall);
}

/** The centre of the view stays within the hall, so the plan cannot get lost off the screen. */
export function keepInside(view: Box, hall: Box): Box {
  const centerX = Math.min(hall.x + hall.width, Math.max(hall.x, view.x + view.width / 2));
  const centerY = Math.min(hall.y + hall.height, Math.max(hall.y, view.y + view.height / 2));
  return { ...view, x: centerX - view.width / 2, y: centerY - view.height / 2 };
}

/** Linear interpolation between two views (animation), t from 0 to 1. */
export function between(from: Box, to: Box, t: number): Box {
  const mix = (a: number, b: number) => a + (b - a) * t;
  return { x: mix(from.x, to.x), y: mix(from.y, to.y), width: mix(from.width, to.width), height: mix(from.height, to.height) };
}

/** Plan units per screen pixel when a view is shown in an element of the given size (viewBox "meet"). */
export function unitsPerPixel(view: Box, elementWidth: number, elementHeight: number): number {
  return Math.max(view.width / Math.max(1, elementWidth), view.height / Math.max(1, elementHeight));
}

export function toViewBox(view: Box): string {
  return `${view.x} ${view.y} ${view.width} ${view.height}`;
}

/** Whether the view shows noticeably less than the whole hall (zoomed in). */
export function isZoomed(view: Box, hall: Box): boolean {
  return view.width < hall.width * 0.95 && view.height < hall.height * 0.95;
}

/** Result of panWithin: the moved view and the part of the move (plan units) the hall had no room for. */
export interface Pan {
  view: Box;
  restX: number;
  restY: number;
}

/**
 * Moves the view by dx, dy (plan units) only as far as the edges of the hall reach the edges of the
 * element showing it (width x height px, viewBox "meet" shows more along one axis). A hall smaller than
 * the element along an axis stays centred on it. The rest of the move is returned, e.g. for the page.
 */
export function panWithin(view: Box, dx: number, dy: number, hall: Box, width: number, height: number): Pan {
  const scale = unitsPerPixel(view, width, height);
  const centerX = view.x + view.width / 2;
  const centerY = view.y + view.height / 2;
  const x = clampAxis(centerX, dx, width * scale, hall.x, hall.width);
  const y = clampAxis(centerY, dy, height * scale, hall.y, hall.height);
  return {
    view: { ...view, x: x.center - view.width / 2, y: y.center - view.height / 2 },
    restX: x.rest,
    restY: y.rest,
  };
}

/** The view kept within the hall's edges (see panWithin). */
export function clampView(view: Box, hall: Box, width: number, height: number): Box {
  return panWithin(view, 0, 0, hall, width, height).view;
}

/** One axis of panWithin: the new centre and the rest of the move in its direction. */
function clampAxis(center: number, move: number, visible: number, start: number, size: number): { center: number; rest: number } {
  const lowest = visible >= size ? start + size / 2 : start + visible / 2;
  const highest = visible >= size ? lowest : start + size - visible / 2;
  const next = Math.min(highest, Math.max(lowest, center + move));
  const moved = next - Math.min(highest, Math.max(lowest, center));
  const rest = move - moved;
  return { center: next, rest: Math.sign(rest) === Math.sign(move) ? rest : 0 };
}
