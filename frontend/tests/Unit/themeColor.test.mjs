import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  hexToOklch,
  deriveSidebarTokens,
  SIDEBAR_TOKEN_NAMES,
} from '../../src/utils/themeColor.js';

test('hexToOklch — známé hodnoty lightness', () => {
  assert.ok(Math.abs(hexToOklch('#ffffff').l - 1.0) < 0.01, 'bílá L ≈ 1');
  assert.ok(Math.abs(hexToOklch('#000000').l - 0.0) < 0.01, 'černá L ≈ 0');
  assert.ok(Math.abs(hexToOklch('#00345C').l - 0.30) < 0.03, 'Shipard modrá L ≈ 0.30');
});

test('hexToOklch — chroma a hue v rozumných mezích', () => {
  const white = hexToOklch('#ffffff');
  assert.ok(white.c < 0.01, 'bílá je achromatická');

  const blue = hexToOklch('#00345C');
  assert.ok(blue.c > 0.05, 'brand modrá má nenulovou chromu');
  assert.ok(blue.h >= 0 && blue.h < 360, 'hue normalizovaná do [0, 360)');
});

test('deriveSidebarTokens — tmavá barva dostane světlý text', () => {
  const tokens = deriveSidebarTokens('#6D1F2C'); // vínová
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('255 255 255'));
  assert.ok(tokens['--shpd-color-sidebar-active-bg'].includes('255 255 255'));
});

test('deriveSidebarTokens — světlá barva dostane tmavý text', () => {
  const tokens = deriveSidebarTokens('#E3D5B8'); // písková
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('15 23 42'));
  assert.ok(tokens['--shpd-color-sidebar-active-bg'].includes('0 0 0'));
});

test('deriveSidebarTokens — bg token vrací vstupní hex beze změny', () => {
  assert.equal(deriveSidebarTokens('#6D1F2C')['--shpd-color-bg-sidebar'], '#6D1F2C');
});

test('deriveSidebarTokens — elevated je oklch() se zvýšenou lightness', () => {
  const elevated = deriveSidebarTokens('#00345C')['--shpd-color-bg-sidebar-elevated'];
  assert.match(elevated, /^oklch\([\d.]+ [\d.]+ [\d.]+\)$/);
  const l = parseFloat(elevated.match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(Math.abs(l - (hexToOklch('#00345C').l + 0.06)) < 0.002, 'L = L_base + 0.06');
});

test('deriveSidebarTokens — elevated lightness je capnutá na 0.98', () => {
  const elevated = deriveSidebarTokens('#ffffff')['--shpd-color-bg-sidebar-elevated'];
  const l = parseFloat(elevated.match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(l <= 0.98);
});

test('deriveSidebarTokens — vrací přesně klíče ze SIDEBAR_TOKEN_NAMES', () => {
  const tokens = deriveSidebarTokens('#2F343D');
  assert.deepEqual(
    Object.keys(tokens).sort(),
    [...SIDEBAR_TOKEN_NAMES].sort(),
  );
});
