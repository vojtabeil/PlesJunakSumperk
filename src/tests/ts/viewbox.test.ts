import { describe, expect, test } from 'bun:test';
import { between, keepInside, MaxZoomIn, pad, panBy, union, unitsPerPixel, zoomAround } from '../../assets/ts/seat-picker/viewbox';

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

  test('pans but keeps the centre inside the hall', () => {
    const view = { x: 0, y: 0, width: 200, height: 160 };
    expect(panBy(view, 50, 20, hall)).toEqual({ x: 50, y: 20, width: 200, height: 160 });
    expect(panBy(view, -5000, 0, hall).x + 100).toBe(0);
    expect(keepInside({ x: 2000, y: 2000, width: 100, height: 100 }, hall)).toEqual({ x: 950, y: 750, width: 100, height: 100 });
  });

  test('animates between views and measures pixels', () => {
    expect(between({ x: 0, y: 0, width: 100, height: 100 }, { x: 100, y: 50, width: 200, height: 300 }, 0.5)).toEqual({ x: 50, y: 25, width: 150, height: 200 });
    expect(unitsPerPixel(hall, 500, 800)).toBe(2);
  });
});
