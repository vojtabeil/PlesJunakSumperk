// Entry point of the static demo (dev\demo.cmd): the same pages as front.tsx, but the seat picker talks
// to the in-browser demo API (seat-picker/demo-api.ts) instead of the server.

import { render } from 'preact';
import { initCopyButtons } from './copy';
import { initPrintButtons } from './print';
import { initScrollLinks } from './scroll';
import { App } from './seat-picker/components/App';
import { createDemoApi, type DemoConfig } from './seat-picker/demo-api';
import { createStore } from './seat-picker/store';
import type { PickerData } from './seat-picker/types';

const container = document.getElementById('seat-picker');
const dataElement = document.getElementById('seat-picker-data');
const demoElement = document.getElementById('seat-picker-demo');

if (container && dataElement?.textContent && demoElement?.textContent) {
  const data = JSON.parse(dataElement.textContent) as PickerData;
  const demo = JSON.parse(demoElement.textContent) as DemoConfig;
  let storage: Storage | null = null;
  try {
    storage = window.localStorage;
  } catch {
    // storage blocked: the demo keeps the choice in memory only
  }
  const store = createStore(createDemoApi(data.layout.seats, demo, storage), (url) => {
    window.location.href = url;
  });
  container.textContent = '';
  render(<App store={store} data={data} />, container);
}

initCopyButtons();
initPrintButtons();
initScrollLinks();
