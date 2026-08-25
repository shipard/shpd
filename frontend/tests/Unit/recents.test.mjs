import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

// Mock localStorage před importem modulu (modul na něj sahá až při volání).
const data = new Map();
globalThis.localStorage = {
  getItem: (k) => (data.has(k) ? data.get(k) : null),
  setItem: (k, v) => data.set(k, String(v)),
  removeItem: (k) => data.delete(k),
};

const { loadRecents, recordRecent, clearRecents } = await import('../../src/utils/recents.js');

beforeEach(() => data.clear());

const entry = (id, label = id) => ({ id, label, icon: 'table', type: 'viewer' });

test('recordRecent + loadRecents: nejnovější první, ukládá id/label/icon/type/ts', () => {
  recordRecent(1, entry('a'));
  recordRecent(1, entry('b'));
  const list = loadRecents(1);
  assert.deepEqual(list.map((e) => e.id), ['b', 'a']);
  assert.equal(list[0].label, 'b');
  assert.equal(list[0].icon, 'table');
  assert.equal(list[0].type, 'viewer');
  assert.equal(typeof list[0].ts, 'number');
});

test('cap 7: osmý záznam vytlačí nejstarší', () => {
  for (let i = 0; i < 8; i++) recordRecent(1, entry(`i${i}`));
  const list = loadRecents(1);
  assert.equal(list.length, 7);
  assert.equal(list[0].id, 'i7');
  assert.ok(!list.some((e) => e.id === 'i0'));
});

test('dedup: opakovaná návštěva přesune položku nahoru, bez duplicity', () => {
  recordRecent(1, entry('a'));
  recordRecent(1, entry('b'));
  recordRecent(1, entry('a'));
  const list = loadRecents(1);
  assert.deepEqual(list.map((e) => e.id), ['a', 'b']);
});

test('corrupted JSON v localStorage nepadá → []', () => {
  data.set('shpd_recents_1', '{not json');
  assert.deepEqual(loadRecents(1), []);
  // a zápis přes poškozený stav funguje (přepíše ho)
  recordRecent(1, entry('a'));
  assert.equal(loadRecents(1).length, 1);
});

test('ne-pole / položky bez id se zahodí', () => {
  data.set('shpd_recents_1', JSON.stringify({ foo: 'bar' }));
  assert.deepEqual(loadRecents(1), []);
  data.set('shpd_recents_1', JSON.stringify([{ id: 'a' }, { label: 'bez id' }, null]));
  assert.deepEqual(loadRecents(1).map((e) => e.id), ['a']);
});

test('izolace per userId', () => {
  recordRecent(1, entry('a'));
  recordRecent(2, entry('b'));
  assert.deepEqual(loadRecents(1).map((e) => e.id), ['a']);
  assert.deepEqual(loadRecents(2).map((e) => e.id), ['b']);
});

test('null userId / entry bez id → no-op', () => {
  recordRecent(null, entry('a'));
  recordRecent(1, { label: 'bez id' });
  assert.deepEqual(loadRecents(null), []);
  assert.deepEqual(loadRecents(1), []);
});

test('clearRecents maže jen daného uživatele', () => {
  recordRecent(1, entry('a'));
  recordRecent(2, entry('b'));
  clearRecents(1);
  assert.deepEqual(loadRecents(1), []);
  assert.deepEqual(loadRecents(2).map((e) => e.id), ['b']);
});
