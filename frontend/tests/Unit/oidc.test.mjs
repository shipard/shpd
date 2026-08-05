import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildOidcStartUrl, parseOidcRedirect, parseOpAuth } from '../../src/api/oidc.js';

test('buildOidcStartUrl composes start URL with encoded provider', () => {
  assert.equal(
    buildOidcStartUrl('/api/v1', 'entra'),
    '/api/v1/_auth/oidc/start?provider=entra',
  );
  assert.equal(
    buildOidcStartUrl('/4l3j-z0bz-kz39-echj/api/v1', 'my-idp'),
    '/4l3j-z0bz-kz39-echj/api/v1/_auth/oidc/start?provider=my-idp',
  );
  // encodeURIComponent na provider id (obrana, id je [a-z0-9-]+)
  assert.equal(
    buildOidcStartUrl('/api/v1', 'a b'),
    '/api/v1/_auth/oidc/start?provider=a%20b',
  );
});

test('parseOidcRedirect detects handoff code', () => {
  assert.deepEqual(
    parseOidcRedirect('?login=oidc&code=abc123'),
    { kind: 'handoff', code: 'abc123' },
  );
});

test('parseOidcRedirect detects login error', () => {
  assert.deepEqual(
    parseOidcRedirect('?login_error=oidc_no_account'),
    { kind: 'error', error: 'oidc_no_account' },
  );
});

test('parseOidcRedirect ignores unrelated queries', () => {
  assert.equal(parseOidcRedirect(''), null);
  assert.equal(parseOidcRedirect('?foo=bar'), null);
  // login=oidc bez kódu není handoff
  assert.equal(parseOidcRedirect('?login=oidc'), null);
  // jiný login parametr není OIDC návrat
  assert.equal(parseOidcRedirect('?login=local&code=x'), null);
});

test('parseOidcRedirect prefers handoff over error', () => {
  assert.deepEqual(
    parseOidcRedirect('?login=oidc&code=abc&login_error=oidc_denied'),
    { kind: 'handoff', code: 'abc' },
  );
});

test('parseOpAuth extracts transaction token', () => {
  assert.equal(parseOpAuth('?op_auth=abc-123_XYZ'), 'abc-123_XYZ');
  assert.equal(parseOpAuth('?foo=bar&op_auth=t1'), 't1');
});

test('parseOpAuth ignores missing or empty param', () => {
  assert.equal(parseOpAuth(''), null);
  assert.equal(parseOpAuth('?foo=bar'), null);
  assert.equal(parseOpAuth('?op_auth='), null);
});
