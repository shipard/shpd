import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  hexToOklch,
  hexToOklab,
  mixOklab,
  oklabToCss,
  deriveSidebarTokens,
  SIDEBAR_TOKEN_NAMES,
} from '../../src/utils/themeColor.js';

const solid = (color) => ({ type: 'solid', color });
const gradient = (stops) => ({ type: 'gradient', stops });

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

test('mixOklab — střed černá/bílá má L ≈ 0.5', () => {
  assert.ok(Math.abs(mixOklab('#000000', '#ffffff', 0.5).L - 0.5) < 0.05);
});

test('mixOklab — krajní t vrací vstupní barvy', () => {
  const a = '#6D1F2C';
  const b = '#00345C';
  for (const key of ['L', 'a', 'b']) {
    assert.ok(Math.abs(mixOklab(a, b, 1)[key] - hexToOklab(b)[key]) < 1e-9, `t=1 → b (${key})`);
    assert.ok(Math.abs(mixOklab(a, b, 0)[key] - hexToOklab(a)[key]) < 1e-9, `t=0 → a (${key})`);
  }
});

test('oklabToCss — formát oklch() stringu', () => {
  assert.match(oklabToCss(hexToOklab('#00345C')), /^oklch\([\d.]+ [\d.]+ [\d.]+\)$/);
});

test('solid, opacity 100 — vizuálně identické s Fází 1', () => {
  const tokens = deriveSidebarTokens(solid('#6D1F2C'), 'light', 100);
  // bg odpovídá vínové (oklch ekvivalent — mix s t=1 je identita)
  const l = parseFloat(tokens['--shpd-color-bg-sidebar'].match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(Math.abs(l - hexToOklch('#6D1F2C').l) < 0.002, 'L odpovídá vínové');
  // tmavý sidebar → světlý text
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('255 255 255'));
  assert.ok(tokens['--shpd-color-sidebar-active-bg'].includes('255 255 255'));
  // solid nemá gradient image token
  assert.ok(!('--shpd-sidebar-bg-image' in tokens));
});

test('solid, světlá barva — tmavý text', () => {
  const tokens = deriveSidebarTokens(solid('#E3D5B8'), 'light', 100);
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('15 23 42'));
  assert.ok(tokens['--shpd-color-sidebar-active-bg'].includes('0 0 0'));
});

test('gradient — bg-image je vertikální linear-gradient, text světlý', () => {
  const tokens = deriveSidebarTokens(gradient(['#00345C', '#0E4F5C']), 'light', 100);
  assert.match(
    tokens['--shpd-sidebar-bg-image'],
    /^linear-gradient\(180deg, oklch\([\d. ]+\), oklch\([\d. ]+\)\)$/,
  );
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('255 255 255'));
});

test('gradient — světlé stopy dají tmavý text (efektivní barva)', () => {
  const tokens = deriveSidebarTokens(gradient(['#DBE4EE', '#E3D5B8']), 'light', 100);
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('15 23 42'));
});

test('opacity 0, light báze — bg ≈ bílá, tmavý text', () => {
  const tokens = deriveSidebarTokens(solid('#6D1F2C'), 'light', 0);
  const l = parseFloat(tokens['--shpd-color-bg-sidebar'].match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(l > 0.95, 'bg lightness ≈ bílá');
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('15 23 42'));
});

test('opacity 0, dark báze — bg ≈ tmavé pozadí, světlý text', () => {
  const tokens = deriveSidebarTokens(solid('#E3D5B8'), 'dark', 0);
  const l = parseFloat(tokens['--shpd-color-bg-sidebar'].match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(Math.abs(l - hexToOklch('#232730').l) < 0.01, 'bg lightness ≈ dark bg');
  assert.ok(tokens['--shpd-color-text-sidebar'].includes('255 255 255'));
});

test('elevated je oklch() se zvýšenou lightness, cap 0.98', () => {
  const elevated = deriveSidebarTokens(solid('#00345C'), 'light', 100)['--shpd-color-bg-sidebar-elevated'];
  const l = parseFloat(elevated.match(/^oklch\(([\d.]+)/)[1]);
  assert.ok(Math.abs(l - (hexToOklch('#00345C').l + 0.06)) < 0.002, 'L = L_base + 0.06');

  const capped = deriveSidebarTokens(solid('#ffffff'), 'light', 100)['--shpd-color-bg-sidebar-elevated'];
  assert.ok(parseFloat(capped.match(/^oklch\(([\d.]+)/)[1]) <= 0.98);
});

test('vrácené klíče jsou podmnožinou SIDEBAR_TOKEN_NAMES', () => {
  for (const tokens of [
    deriveSidebarTokens(solid('#2F343D'), 'light', 100),
    deriveSidebarTokens(gradient(['#00345C', '#0E4F5C']), 'dark', 85),
  ]) {
    for (const key of Object.keys(tokens)) {
      assert.ok(SIDEBAR_TOKEN_NAMES.includes(key), `${key} je v SIDEBAR_TOKEN_NAMES`);
    }
  }
});

test('gradient má všech 9 tokenů, solid 8 (bez bg-image)', () => {
  assert.equal(Object.keys(deriveSidebarTokens(gradient(['#00345C', '#0E4F5C']), 'light', 100)).length, 9);
  assert.equal(Object.keys(deriveSidebarTokens(solid('#00345C'), 'light', 100)).length, 8);
});
