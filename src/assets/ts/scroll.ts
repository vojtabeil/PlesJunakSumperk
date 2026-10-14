// In-page navigation without #fragments in the URL: the page is a single view, the address stays clean.

/** Smoothly scrolls an element into view (instantly when the visitor prefers reduced motion). */
export function scrollToElement(id: string): void {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
}

/** Links with data-scroll-to="<id>" (e.g. "Koupit lístky") scroll to the element instead of changing the URL. */
export function initScrollLinks(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-scroll-to]').forEach((link) => {
    link.addEventListener('click', (event) => {
      const id = link.dataset['scrollTo'];
      if (id && document.getElementById(id)) {
        event.preventDefault();
        scrollToElement(id);
      }
    });
  });
}
