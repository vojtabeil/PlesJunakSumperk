import { expect, test } from 'bun:test';
import { hitLabel } from '../../assets/ts/admin/reservation-search';

test('hitLabel shows what is left to pay, or the status', () => {
  const hit = { id: 12, name: 'Jana Nováková', email: 'jana@example.com', status: 'Potvrzená', total: 700, remaining: 700 };
  expect(hitLabel(hit)).toBe('č. 12 Jana Nováková · jana@example.com · zbývá 700 Kč');
  expect(hitLabel({ ...hit, status: 'Zaplacená', remaining: 0 })).toBe('č. 12 Jana Nováková · jana@example.com · zaplacená');
});
