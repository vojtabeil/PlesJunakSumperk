// Entry point of the public pages: mounts the seat picker island into #seat-picker and
// enables the "kopírovat" buttons of the payment details.

import { render } from 'preact';
import { initCopyButtons } from './copy';
import { createApi } from './seat-picker/api';
import { App } from './seat-picker/components/App';
import { createStore } from './seat-picker/store';
import type { PickerData } from './seat-picker/types';

const container = document.getElementById('seat-picker');
const dataElement = document.getElementById('seat-picker-data');

if (container && dataElement?.textContent) {
  const data = JSON.parse(dataElement.textContent) as PickerData;
  const store = createStore(createApi(data.api, data.csrf), (url) => {
    window.location.href = url;
  });
  // Replace the server-rendered static preview of the hall plan.
  container.textContent = '';
  render(<App store={store} data={data} />, container);
}

initCopyButtons();
