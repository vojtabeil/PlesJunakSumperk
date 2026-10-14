// Entry point of the entrance check (/admin/kontrola): mounts the check app into #check-app and
// registers the service worker of the installed app (works without a signal, check-sw.ts).

import { render } from 'preact';
import { createCheckApi } from './check/api';
import { App } from './check/components/App';
import { createOfflineStore } from './check/offline-store';
import type { CheckData } from './check/types';

const container = document.getElementById('check-app');
const dataElement = document.getElementById('check-data');

if (container && dataElement?.textContent) {
  const data = JSON.parse(dataElement.textContent) as CheckData;
  container.textContent = '';
  render(<App data={data} api={createCheckApi(data.api, data.csrf)} store={createOfflineStore()} />, container);
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(data.sw, { scope: data.scope }).catch(() => {
      // without the service worker the app works online, it just cannot open without a signal
    });
  }
}
