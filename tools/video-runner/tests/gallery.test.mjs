import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';

import { GALLERY_NAME, generateGallery, renderGallery } from '../src/gallery.mjs';

const entry = (name, overrides = {}) => ({
  name,
  size: 2 * 1024 * 1024,
  mtime: new Date('2026-08-28T12:00:00'),
  ...overrides,
});

test('každé video má přehrávač a název bez přípony', () => {
  const html = renderGallery([entry('prvni-kroky.mp4'), entry('faktura.mp4')]);
  assert.match(html, /<video controls preload="metadata" src="prvni-kroky\.mp4">/);
  assert.match(html, /<h2>prvni-kroky<\/h2>/);
  assert.match(html, /<h2>faktura<\/h2>/);
});

test('nejnovější video je nahoře', () => {
  const html = renderGallery([
    entry('starsi.mp4', { mtime: new Date('2026-08-01T10:00:00') }),
    entry('novejsi.mp4', { mtime: new Date('2026-08-28T10:00:00') }),
  ]);
  assert.ok(html.indexOf('novejsi') < html.indexOf('starsi'));
});

test('název se escapuje v textu i v src', () => {
  const html = renderGallery([entry('a<b&c.mp4')]);
  assert.match(html, /<h2>a&lt;b&amp;c<\/h2>/);
  assert.match(html, /src="a%3Cb%26c\.mp4"/);
  assert.ok(!html.includes('<b&c'));
});

test('velikost v MB a čas vzniku', () => {
  const html = renderGallery([entry('demo.mp4', { size: 1.5 * 1024 * 1024 })]);
  assert.match(html, /1\.5 MB/);
  assert.match(html, /28\. 8\. 2026/);
});

test('prázdný adresář dá smysluplnou prázdnou stránku', () => {
  const html = renderGallery([]);
  assert.ok(!html.includes('<video'));
  assert.match(html, /Zatím žádná videa/);
  assert.match(html, /<html lang="cs">/);
});

test('generateGallery skenuje jen .mp4 a zapíše index.html', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'gallery-'));
  try {
    await writeFile(join(dir, 'demo.mp4'), 'x');
    await writeFile(join(dir, 'poznamky.txt'), 'x');
    await writeFile(join(dir, GALLERY_NAME), 'stará verze');

    const count = await generateGallery(dir);

    assert.equal(count, 1);
    const html = await readFile(join(dir, GALLERY_NAME), 'utf8');
    assert.match(html, /<h2>demo<\/h2>/);
    assert.ok(!html.includes('poznamky'));
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
});
