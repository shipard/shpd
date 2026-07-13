import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseAuthAction } from '../../src/api/authActions.js';

test('parses set-password action with token', () => {
  assert.deepEqual(
    parseAuthAction('?auth_action=set-password&token=shpd_pt_abc-123_XY'),
    { kind: 'set-password', token: 'shpd_pt_abc-123_XY' },
  );
});

test('returns null without token', () => {
  assert.equal(parseAuthAction('?auth_action=set-password'), null);
  assert.equal(parseAuthAction('?auth_action=set-password&token='), null);
});

test('returns null for unknown action', () => {
  assert.equal(parseAuthAction('?auth_action=frobnicate&token=x'), null);
});

test('returns null for empty or unrelated query', () => {
  assert.equal(parseAuthAction(''), null);
  assert.equal(parseAuthAction('?login=oidc&code=abc'), null);
});

test('ignores OIDC params when auth action present (boot checks auth action first)', () => {
  assert.deepEqual(
    parseAuthAction('?auth_action=set-password&token=tok&login=oidc&code=abc'),
    { kind: 'set-password', token: 'tok' },
  );
});
