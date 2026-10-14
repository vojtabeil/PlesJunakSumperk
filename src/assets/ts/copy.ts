// "kopírovat" buttons (payment details): copy data-copy into the clipboard and say so.

export function initCopyButtons(root: ParentNode = document): void {
  root.querySelectorAll<HTMLButtonElement>('button[data-copy]').forEach((button) => {
    const label = button.textContent ?? '';
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy ?? '');
        button.textContent = 'zkopírováno';
      } catch {
        button.textContent = 'nelze kopírovat';
      }
      window.setTimeout(() => (button.textContent = label), 2000);
    });
  });
}
