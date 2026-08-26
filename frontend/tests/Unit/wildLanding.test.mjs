import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveLanding } from '../../src/utils/wildLanding.js';

test('resolveLanding: první vstup do sekce → AI záložka (D3)', () => {
  assert.equal(resolveLanding(null, 'accounting'), 'ai');
});

test('resolveLanding: záznam leafu → jeho id (D7)', () => {
  assert.equal(resolveLanding({ tab: 'viewer:mail.incoming' }, 'accounting'), 'viewer:mail.incoming');
});

test('resolveLanding: záznam ai → ai (D7)', () => {
  assert.equal(resolveLanding({ tab: 'ai' }, 'accounting'), 'ai');
});

test('resolveLanding: domeček bez záznamu → default (dashboard)', () => {
  assert.equal(resolveLanding(null, null), null);
});

test('resolveLanding: domeček s vadným záznamem ai → default, nikdy ai', () => {
  assert.equal(resolveLanding({ tab: 'ai' }, null), null);
});

test('resolveLanding: domeček se záznamem leafu → jeho id', () => {
  assert.equal(resolveLanding({ tab: 'dashboard' }, null), 'dashboard');
});
