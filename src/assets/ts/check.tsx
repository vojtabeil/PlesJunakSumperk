// Entry point of the entrance check (/admin/kontrola): mounts the check app into #check-app.

import { render } from 'preact';
import { createCheckApi } from './check/api';
import { App } from './check/components/App';
import type { CheckData } from './check/types';

const container = document.getElementById('check-app');
const dataElement = document.getElementById('check-data');

if (container && dataElement?.textContent) {
  const data = JSON.parse(dataElement.textContent) as CheckData;
  container.textContent = '';
  render(<App data={data} api={createCheckApi(data.api, data.csrf)} />, container);
}
