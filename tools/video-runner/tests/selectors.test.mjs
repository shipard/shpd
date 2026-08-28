import assert from 'node:assert/strict';
import { test } from 'node:test';

import { resolveSelector, TESTID_SELECTOR_RE } from '../src/selectors.mjs';

test('@name se překládá na atributový selektor', () => {
  assert.equal(resolveSelector('@login-name'), '[data-testid="login-name"]');
  assert.equal(
    resolveSelector('@nav-viewer:core.mail.incoming'),
    '[data-testid="nav-viewer:core.mail.incoming"]',
  );
});

test('CSS selektor projde beze změny', () => {
  assert.equal(resolveSelector('.shpd-shell'), '.shpd-shell');
  assert.equal(resolveSelector('#login-name'), '#login-name');
});

test('regex povoluje tečky, dvojtečky a podtržítka, ne mezery a uvozovky', () => {
  assert.ok(TESTID_SELECTOR_RE.test('@nav-viewer:core.mail.incoming'));
  assert.ok(TESTID_SELECTOR_RE.test('@viewer_rows-2'));
  assert.ok(!TESTID_SELECTOR_RE.test('@ne platný!'));
  assert.ok(!TESTID_SELECTOR_RE.test('@x"]'));
  assert.ok(!TESTID_SELECTOR_RE.test('@'));
});
