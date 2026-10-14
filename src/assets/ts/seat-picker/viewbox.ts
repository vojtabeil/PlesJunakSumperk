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

/** Moves the view by a distance in plan units; it never leaves the hall completely. */
export function panBy(view: Box, dx: number, dy: number, hall: Box): Box {
  return keepInside({ ...view, x: view.x + dx, y: view.y + dy }, hall);
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
