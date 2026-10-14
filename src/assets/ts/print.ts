// "Vytisknout" buttons (printable ticket): open the print dialog.

export function initPrintButtons(root: ParentNode = document): void {
  root.querySelectorAll<HTMLButtonElement>('button[data-print]').forEach((button) => {
    button.addEventListener('click', () => window.print());
  });
}
