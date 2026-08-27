import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, test } from 'node:test';

import { loadScenario } from '../src/scenario.mjs';

const dir = await mkdtemp(join(tmpdir(), 'video-runner-'));
after(() => rm(dir, { recursive: true, force: true }));

let counter = 0;

/** Zapíše scénář do dočasného souboru a načte ho. */
async function load(body) {
  const path = join(dir, `s${counter++}.jsonc`);
  await writeFile(path, JSON.stringify({ id: 'test', ...body }), 'utf8');
  return loadScenario(path);
}

function message(promise) {
  return promise.then(() => null, (error) => error.message);
}

test('výchozí capture a odvozený viewport', async () => {
  const scenario = await load({ steps: [{ goto: '/app/' }] });
  assert.deepEqual(scenario.capture, { w: 2560, h: 1600, scale: 2 });
  assert.deepEqual(scenario.viewport, { width: 1280, height: 800 });
});

test('capture nedělitelné scale je chyba', async () => {
  const text = await message(load({ capture: { w: 1001, h: 800, scale: 2 }, steps: [{ goto: '/app/' }] }));
  assert.match(text, /dělitelné scale/);
});

test('verb se odvodí a pause funguje jako modifikátor', async () => {
  const scenario = await load({ steps: [{ hover: '.x', pause: 1 }, { pause: 2 }] });
  assert.equal(scenario.steps[0].verb, 'hover');
  assert.equal(scenario.steps[1].verb, 'pause');
});

test('dva verby v jednom kroku', async () => {
  const text = await message(load({ steps: [{ hover: '.x', click: '.y' }] }));
  assert.match(text, /krok #1: víc verbů/);
});

test('překlep v názvu verbu', async () => {
  const text = await message(load({ steps: [{ hovr: '.x' }] }));
  assert.match(text, /krok #1: neznámý klíč hovr/);
});

test('goto bez lomítka', async () => {
  const text = await message(load({ steps: [{ goto: 'https://example.dev/app/' }] }));
  assert.match(text, /krok #1: goto chce cestu/);
});

test('caption smí být null, jinak jen text', async () => {
  const scenario = await load({ steps: [{ caption: null }] });
  assert.equal(scenario.steps[0].caption, null);

  const text = await message(load({ steps: [{ caption: 7 }] }));
  assert.match(text, /caption chce text nebo null/);
});

test('for jen u highlight, travel jen u přejezdu', async () => {
  assert.match(await message(load({ steps: [{ hover: '.x', for: 1 }] })), /for dává smysl jen u highlight/);
  assert.match(await message(load({ steps: [{ waitFor: '.x', travel: 1 }] })), /travel dává smysl jen u hover a click/);
});

test('prázdný selektor', async () => {
  assert.match(await message(load({ steps: [{ click: '  ' }] })), /neprázdný selektor/);
});

test('scénář bez kroků', async () => {
  assert.match(await message(load({ steps: [] })), /nemá žádné kroky/);
});

test('id musí být slug', async () => {
  const path = join(dir, 'bad-id.jsonc');
  await writeFile(path, JSON.stringify({ id: 'Test Scénář', steps: [{ pause: 1 }] }), 'utf8');
  assert.match(await message(loadScenario(path)), /id z malých písmen/);
});
