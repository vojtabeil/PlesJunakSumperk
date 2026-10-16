import { expect, test } from 'bun:test';
import { hitLabel } from '../../assets/ts/admin/reservation-search';
import { availabilitySummary, soldOutOfSale } from '../../assets/ts/admin/table-availability';

test('hitLabel shows what is left to pay, or the status', () => {
  const hit = { id: 12, name: 'Jana Nováková', email: 'jana@example.com', status: 'Potvrzená', total: 700, remaining: 700 };
  expect(hitLabel(hit)).toBe('č. 12 Jana Nováková · jana@example.com · zbývá 700 Kč');
  expect(hitLabel({ ...hit, status: 'Zaplacená', remaining: 0 })).toBe('č. 12 Jana Nováková · jana@example.com · zaplacená');
});

test('availabilitySummary counts the tables and seats for sale and out of sale', () => {
  const a1 = { available: true, seats: 16, sold: 0 };
  const a2 = { available: true, seats: 12, sold: 2 };
  expect(availabilitySummary([a1, a2])).toBe('V prodeji 2 stoly (28 míst)');
  expect(availabilitySummary([a1, { ...a2, available: false }])).toBe('V prodeji 1 stůl (16 míst) · mimo prodej 1 (12 míst)');
  expect(soldOutOfSale([a1, a2])).toBe(false);
  expect(soldOutOfSale([a1, { ...a2, available: false }])).toBe(true);
});