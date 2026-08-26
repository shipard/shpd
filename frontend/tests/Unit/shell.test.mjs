import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  resolveShell,
  normalizeShellValue,
  normalizeDsShell,
  KNOWN_SHELLS,
  DEFAULT_SHELL,
} from '../../src/utils/shell.js';

// --- resolveShell ---

test('resolveShell: follow s DS defaultem vrací DS default', () => {
  assert.equal(resolveShell(true, 'sidebar', 'classic'), 'classic');
});

test('resolveShell: follow bez DS defaultu padá na sidebar', () => {
  assert.equal(resolveShell(true, 'classic', null), DEFAULT_SHELL);
});

test('resolveShell: override vyhrává nad DS defaultem', () => {
  assert.equal(resolveShell(false, 'classic', 'sidebar'), 'classic');
});

test('resolveShell: neznámé jméno (override i DS default) → sidebar', () => {
  assert.equal(resolveShell(false, 'wild', 'classic'), DEFAULT_SHELL);
  assert.equal(resolveShell(true, 'classic', 'wild'), DEFAULT_SHELL);
});

test('resolveShell: garbage vstupy → sidebar', () => {
  assert.equal(resolveShell(false, null, null), DEFAULT_SHELL);
  assert.equal(resolveShell(false, 42, null), DEFAULT_SHELL);
  assert.equal(resolveShell(true, null, { shell: 'classic' }), DEFAULT_SHELL);
  assert.equal(resolveShell(false, '', ''), DEFAULT_SHELL);
});

test('resolveShell: vlastní known seznam omezuje volbu', () => {
  assert.equal(resolveShell(false, 'classic', null, ['sidebar']), DEFAULT_SHELL);
});

// --- normalizeShellValue (serverový tvar account.shell) ---

test('normalizeShellValue: {follow:true} → follow bez jména', () => {
  assert.deepEqual(normalizeShellValue({ follow: true }), { follow: true, shell: null });
});

test('normalizeShellValue: override tvar nese jméno', () => {
  assert.deepEqual(
    normalizeShellValue({ follow: false, shell: 'classic', params: {} }),
    { follow: false, shell: 'classic' },
  );
});

test('normalizeShellValue: legacy tvar bez follow = override', () => {
  assert.deepEqual(normalizeShellValue({ shell: 'classic' }), { follow: false, shell: 'classic' });
});

test('normalizeShellValue: garbage → null', () => {
  assert.equal(normalizeShellValue(null), null);
  assert.equal(normalizeShellValue('classic'), null);
  assert.equal(normalizeShellValue({}), null);
  assert.equal(normalizeShellValue({ follow: false, shell: '' }), null);
  assert.equal(normalizeShellValue({ follow: false, shell: 42 }), null);
});

// --- normalizeDsShell (DS default z /_app/info) ---

test('normalizeDsShell: {shell, params} → jméno', () => {
  assert.equal(normalizeDsShell({ shell: 'classic', params: {} }), 'classic');
});

test('normalizeDsShell: neznámé jméno propouští (validuje resolveShell)', () => {
  assert.equal(normalizeDsShell({ shell: 'wild' }), 'wild');
});

test('normalizeDsShell: garbage → null', () => {
  assert.equal(normalizeDsShell(null), null);
  assert.equal(normalizeDsShell('classic'), null);
  assert.equal(normalizeDsShell({ shell: '' }), null);
});

// --- konzistence konstant ---

test('KNOWN_SHELLS obsahuje default', () => {
  assert.ok(KNOWN_SHELLS.includes(DEFAULT_SHELL));
});
