import { describe, expect, test } from 'bun:test';
import { between, clampView, isZoomed, keepInside, MaxZoomIn, pad, panWithin, union, unitsPerPixel, zoomAround } from '../../assets/ts/seat-picker/viewbox';

const hall = { x: 0, y: 0, width: 1000, height: 800 };

describe('viewBox', () => {
  test('pads and unites boxes', () => {
    expect(pad({ x: 10, y: 10, width: 100, height: 50 }, 0.1)).toEqual({ x: 0, y: 0, width: 120, height: 70 });
    expect(union([{ x: 0, y: 10, width: 10, height: 10 }, { x: 50, y: 0, width: 5, height: 5 }])).toEqual({ x: 0, y: 0, width: 55, height: 20 });
    expect(union([])).toBeNull();
  });

  test('zooms around a point that stays in place', () => {
    const view = zoomAround(hall, 2, 250, 200, hall);
    expect(view).toEqual({ x: 125, y: 100, width: 500, height: 400 });
    // The point keeps its relative position: (250 - 125) / 500 = 250 / 1000.
    expect((250 - view.x) / view.width).toBe(250 / hall.width);
  });

  test('limits the zoom', () => {
    expect(zoomAround(hall, 1000, 500, 400, hall).width).toBe(hall.width / MaxZoomIn);
    expect(zoomAround(hall, 0.001, 500, 400, hall).width).toBe(1250);
  });

  test('keeps the centre of a zoomed view inside the hall', () => {
    expect(keepInside({ x: 2000, y: 2000, width: 100, height: 100 }, hall)).toEqual({ x: 950, y: 750, width: 100, height: 100 });
  });

  test('animates between views and measures pixels', () => {
    expect(between({ x: 0, y: 0, width: 100, height: 100 }, { x: 100, y: 50, width: 200, height: 300 }, 0.5)).toEqual({ x: 50, y: 25, width: 150, height: 200 });
    expect(unitsPerPixel(hall, 500, 800)).toBe(2);
  });

  test('tells a zoomed view from the whole hall', () => {
    expect(isZoomed(hall, hall)).toBe(false);
    expect(isZoomed({ ...hall, x: 30 }, hall)).toBe(false);
    expect(isZoomed(zoomAround(hall, 2, 500, 400, hall), hall)).toBe(true);
  });

  test('pans only until the hall edge reaches the screen edge and returns the rest', () => {
    // A quarter of the hall in a 250 x 200 px element: 1 unit per px.
    const view = { x: 0, y: 0, width: 250, height: 200 };
    const inside = panWithin(view, 100, 50, hall, 250, 200);
    expect(inside).toEqual({ view: { x: 100, y: 50, width: 250, height: 200 }, restX: 0, restY: 0 });
    const edge = panWithin({ ...view, y: 550 }, 0, 120, hall, 250, 200);
    expect(edge.view.y).toBe(600);
    expect(edge.restY).toBe(70);
    expect(panWithin(view, -30, -40, hall, 250, 200)).toEqual({ view, restX: -30, restY: -40 });
  });

  test('keeps a hall smaller than the screen centred: the whole move is the rest', () => {
    const fitted = panWithin(hall, 0, 50, hall, 500, 2000);
    expect(fitted.view.y).toBe(0);
    expect(fitted.restY).toBe(50);
    expect(clampView({ ...hall, y: 300 }, hall, 500, 400)).toEqual(hall);
  });
});
