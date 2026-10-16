// Stoly v prodeji (Admin:Tables): live preview of the choice. Unticked tables grey out in the inline plan,
// a click on a table in the plan ticks or unticks it, and the summary counts what stays for sale.
// Without JavaScript the checkboxes and "Uložit" work the same, only the preview does not follow.

export interface TableChoice {
  available: boolean;
  seats: number;
  sold: number;
}

/** "V prodeji 25 stolů (278 míst) · mimo prodej 2 (12 míst)" */
export function availabilitySummary(tables: TableChoice[]): string {
  const on = tables.filter((t) => t.available);
  const off = tables.filter((t) => !t.available);
  const seats = (list: TableChoice[]) => list.reduce((sum, t) => sum + t.seats, 0);
  const text = `V prodeji ${on.length} ${tablesWord(on.length)} (${seats(on)} míst)`;
  return off.length ? `${text} · mimo prodej ${off.length} (${seats(off)} míst)` : text;
}

/** Some table out of sale already has sold seats (their reservations stay). */
export function soldOutOfSale(tables: TableChoice[]): boolean {
  return tables.some((t) => !t.available && t.sold > 0);
}

function tablesWord(count: number): string {
  return count === 1 ? 'stůl' : count >= 2 && count <= 4 ? 'stoly' : 'stolů';
}

export function initTableAvailability(root: ParentNode = document): void {
  const form = root.querySelector<HTMLFormElement>('form[data-table-availability]');
  if (!form) {
    return;
  }
  const boxes = [...form.querySelectorAll<HTMLInputElement>('input[data-table]')];
  const byLabel = new Map(boxes.map((box) => [box.dataset['table'] ?? '', box]));
  const summary = form.querySelector<HTMLElement>('[data-summary]');
  const warning = form.querySelector<HTMLElement>('[data-sold-warning]');

  const update = () => {
    const choices = boxes.map((box) => ({
      available: box.checked,
      seats: Number(box.dataset['seats'] ?? 0),
      sold: Number(box.dataset['sold'] ?? 0),
    }));
    for (const box of boxes) {
      const group = form.querySelector(`[id="${CSS.escape(`table-${box.dataset['table'] ?? ''}`)}"]`);
      group?.classList.toggle('is-unavailable', !box.checked);
      group?.setAttribute('aria-pressed', String(box.checked));
    }
    if (summary) {
      summary.textContent = availabilitySummary(choices);
    }
    if (warning) {
      warning.hidden = !soldOutOfSale(choices);
    }
  };

  // Tables in the plan act like the checkboxes.
  for (const [label, box] of byLabel) {
    const group = form.querySelector<SVGElement>(`[id="${CSS.escape(`table-${label}`)}"]`);
    if (!group) {
      continue;
    }
    group.classList.add('is-choosable');
    group.setAttribute('role', 'button');
    group.setAttribute('tabindex', '0');
    group.setAttribute('aria-label', `Stůl ${label}`);
    const toggle = () => {
      box.checked = !box.checked;
      update();
    };
    group.addEventListener('click', toggle);
    group.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggle();
      }
    });
  }

  form.addEventListener('change', update);
  form.querySelectorAll<HTMLButtonElement>('button[data-select]').forEach((button) => {
    button.addEventListener('click', () => {
      const value = button.dataset['select'] === 'all';
      boxes.forEach((box) => (box.checked = value));
      update();
    });
  });
  update();
}
