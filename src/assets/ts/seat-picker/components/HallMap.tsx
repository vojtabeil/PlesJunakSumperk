import { useRef, useState } from 'preact/hooks';
import { formatPrice, nearestSeat, percent, seatStatus, splitSeatLabel, tableZones } from '../logic';
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
const StatusText = { free: 'volné', mine: 'vybrané', taken: 'obsazené' };

/**
 * Hall plan (SVG) with one button per seat positioned over it. The map scrolls inside its own
 * box (never the page) and can be zoomed; on a phone it starts zoomed so seats are easy to tap.
 * The whole map is one tab stop, arrow keys move between seats.
 */
export function HallMap({ layout, state, prices, disabled, onToggle }: Props) {
  const [zoom, setZoom] = useState(() => (window.matchMedia('(max-width: 900px)').matches ? 1 : 0));
  const [focusId, setFocusId] = useState<number | null>(null);
  const seatRefs = useRef(new Map<number, HTMLButtonElement>());

  const selectable = layout.seats.filter((seat) => seatStatus(seat.id, state) !== 'taken');
  const current = selectable.find((seat) => seat.id === focusId) ?? selectable[0];

  const onKeyDown = (event: KeyboardEvent) => {
    const direction = Keys[event.key];
    if (!direction || !current) {
      return;
    }
    event.preventDefault();
    const next = nearestSeat(current, selectable, direction);
    if (next) {
      setFocusId(next.id);
      seatRefs.current.get(next.id)?.focus();
    }
  };

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
      <div class="map-scroll" tabIndex={-1}>
        <div class="map" style={{ aspectRatio: `${layout.width} / ${layout.height}`, width: Zooms[zoom] }} onKeyDown={onKeyDown}>
          <svg class="map-plan" viewBox={`0 0 ${layout.width} ${layout.height}`} aria-hidden="true">
            {layout.areas.map((area) => (
              <g key={area.kind}>
                <rect class={`plan-${area.kind}`} x={area.x} y={area.y} width={area.width} height={area.height} rx={8} />
                <text class={`plan-label plan-label--${area.kind}`} x={area.x + area.width / 2} y={area.y + area.height / 2}>
                  {area.label}
                </text>
              </g>
            ))}
            {tableZones(layout.seats, 14).map((zone) => (
              <rect key={zone.tableId} class="plan-zone" x={zone.x} y={zone.y} width={zone.width} height={zone.height} rx={14} />
            ))}
            {layout.tables.map((table) => (
              <g key={table.id}>
                <rect class="plan-table" x={table.x} y={table.y} width={table.width} height={table.height} rx={4} />
                <text class="plan-table-label" x={table.x + table.width / 2} y={table.y + table.height / 2}>
                  Stůl {table.label}
                </text>
              </g>
            ))}
          </svg>
          {layout.seats.map((seat) => {
            const status = seatStatus(seat.id, state);
            const { table, seat: number } = splitSeatLabel(seat.label);
            const price = status === 'taken' ? '' : `, ${formatPrice(prices.seat)}`;
            return (
              <button
                key={seat.id}
                ref={(el) => {
                  if (el) {
                    seatRefs.current.set(seat.id, el);
                  } else {
                    seatRefs.current.delete(seat.id);
                  }
                }}
                type="button"
                class={`seat seat--${status}`}
                style={{ left: percent(seat.x, layout.width), top: percent(seat.y, layout.height) }}
                aria-label={`Stůl ${table}, místo ${number}, ${StatusText[status]}${price}`}
                aria-pressed={status === 'mine'}
                tabIndex={seat.id === current?.id ? 0 : -1}
                disabled={disabled || status === 'taken'}
                onFocus={() => setFocusId(seat.id)}
                onClick={() => onToggle(seat.id)}
              >
                <span aria-hidden="true">{status === 'mine' ? '✓' : number}</span>
              </button>
            );
          })}
        </div>
      </div>
      <p class="hint">Plánkem lze posouvat prstem do stran. Na klávesnici se mezi místy pohybujete šipkami.</p>
    </div>
  );
}
