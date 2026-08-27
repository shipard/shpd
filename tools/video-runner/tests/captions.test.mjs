import assert from 'node:assert/strict';
import { test } from 'node:test';

import { buildCaptions, checkReadability, renderAss } from '../src/captions.mjs';

const timeline = (events, duration = 10) => ({ events, duration });

test('titulek trvá do svého null', () => {
  const captions = buildCaptions(timeline([
    { t: 1, type: 'caption', text: 'první' },
    { t: 4, type: 'caption', text: null },
  ]));
  assert.deepEqual(captions, [{ start: 1, end: 4, text: 'první' }]);
});

test('další titulek ten předchozí vystřídá', () => {
  const captions = buildCaptions(timeline([
    { t: 1, type: 'caption', text: 'první' },
    { t: 3, type: 'caption', text: 'druhý' },
  ]));
  assert.deepEqual(captions, [
    { start: 1, end: 3, text: 'první' },
    { start: 3, end: 10, text: 'druhý' },
  ]);
});

test('neuzavřený titulek dojede do konce záznamu', () => {
  const captions = buildCaptions(timeline([{ t: 2, type: 'caption', text: 'poslední' }], 7));
  assert.deepEqual(captions, [{ start: 2, end: 7, text: 'poslední' }]);
});

test('ostatní události se ignorují', () => {
  const captions = buildCaptions(timeline([
    { t: 1, type: 'hover', selector: '.x' },
    { t: 2, type: 'caption', text: 'a' },
    { t: 3, type: 'click', selector: '.y' },
    { t: 4, type: 'caption', text: null },
  ]));
  assert.equal(captions.length, 1);
});

test('varování u příliš krátkého titulku', () => {
  const warnings = checkReadability([{ start: 0, end: 0.5, text: 'krátce' }]);
  assert.equal(warnings.length, 1);
  assert.match(warnings[0], /je vidět 0\.5 s/);
});

test('dlouhý text potřebuje víc než minimum', () => {
  // 45 znaků → 3 s; dvě sekundy nestačí, čtyři ano.
  const text = 'a'.repeat(45);
  assert.equal(checkReadability([{ start: 0, end: 2, text }]).length, 1);
  assert.equal(checkReadability([{ start: 0, end: 4, text }]).length, 0);
});

test('varování nad 90 znaků', () => {
  const text = 'a'.repeat(91);
  const warnings = checkReadability([{ start: 0, end: 30, text }]);
  assert.equal(warnings.length, 1);
  assert.match(warnings[0], /91 znaků/);
});

test('ASS: formát času a rozlišení', () => {
  const ass = renderAss([{ start: 61.239, end: 65, text: 'ahoj' }], { w: 1280, h: 800 });
  assert.match(ass, /PlayResX: 1280/);
  assert.match(ass, /Dialogue: 0,0:01:01\.24,0:01:05\.00,Default,,0,0,0,,ahoj/);
});

test('ASS: složené závorky a konce řádků', () => {
  const ass = renderAss([{ start: 0, end: 1, text: 'a {b}\nc' }], { w: 1280, h: 800 });
  assert.match(ass, /,,a \(b\)\\Nc\n$/);
});
