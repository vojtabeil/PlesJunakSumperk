import { percent, seatStatus, splitSeatLabel } from '../logic';
import type { ApiState, HallLayout } from '../types';

interface Props {
  layout: HallLayout;
  state: ApiState;
  disabled: boolean;
  onToggle: (seatId: number) => void;
}

/** Hall plan (SVG) with one button per seat positioned over it. */
export function HallMap({ layout, state, disabled, onToggle }: Props) {
  return (
    <div class="map-scroll">
      <div class="map" style={{ aspectRatio: `${layout.width} / ${layout.height}` }}>
        <svg class="map-plan" viewBox={`0 0 ${layout.width} ${layout.height}`} aria-hidden="true">
          {layout.areas.map((area) => (
            <g key={area.kind}>
              <rect class={`plan-${area.kind}`} x={area.x} y={area.y} width={area.width} height={area.height} rx={8} />
              <text class={`plan-label plan-label--${area.kind}`} x={area.x + area.width / 2} y={area.y + area.height / 2}>
                {area.label}
              </text>
            </g>
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
          return (
            <button
              key={seat.id}
              type="button"
              class={`seat seat--${status}`}
              style={{ left: percent(seat.x, layout.width), top: percent(seat.y, layout.height) }}
              aria-label={`Stůl ${table}, místo ${number}`}
              aria-pressed={status === 'mine'}
              disabled={disabled || status === 'taken'}
              onClick={() => onToggle(seat.id)}
            >
              {number}
            </button>
          );
        })}
      </div>
    </div>
  );
}
