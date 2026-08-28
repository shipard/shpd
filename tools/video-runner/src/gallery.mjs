/**
 * Galerie hotových videí — `out/index.html`.
 *
 * Odvozenina obsahu `out/`: generuje se ze skenu adresáře, ne z paměti
 * běhu, takže ruční smazání souboru galerii při dalším `compose` srovná.
 * Čistá statická stránka bez JS — servíruje ji nginx s basic auth (D15).
 */

import { readdir, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

export const GALLERY_NAME = 'index.html';

const escapeHtml = (text) => text
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

const formatSize = (bytes) => `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

const formatTime = new Intl.DateTimeFormat('cs-CZ', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

/**
 * @typedef {object} GalleryEntry
 * @property {string} name Název souboru včetně `.mp4`.
 * @property {number} size V bajtech.
 * @property {Date} mtime Čas vzniku.
 */

/**
 * Čistá šablona — bez filesystému, aby šla testovat bez ffmpegu.
 *
 * @param {GalleryEntry[]} entries
 * @returns {string} HTML.
 */
export function renderGallery(entries) {
  const sorted = [...entries].sort((a, b) => b.mtime - a.mtime);

  const items = sorted.map((entry) => `    <section class="video">
      <h2>${escapeHtml(entry.name.replace(/\.mp4$/i, ''))}</h2>
      <video controls preload="metadata" src="${encodeURIComponent(entry.name)}"></video>
      <p class="meta">${formatSize(entry.size)} · ${escapeHtml(formatTime.format(entry.mtime))}</p>
    </section>`);

  const body = items.length > 0
    ? items.join('\n')
    : '    <p class="empty">Zatím žádná videa — vzniknou příkazem <code>video-runner build</code>.</p>';

  return `<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Videa — Shipard</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 60rem; margin: 2rem auto; padding: 0 1rem; background: #fafafa; color: #222; }
    h1 { font-size: 1.4rem; }
    h2 { font-size: 1.1rem; margin: 0 0 .5rem; }
    .video { margin: 2rem 0; }
    video { width: 100%; background: #000; border-radius: 4px; }
    .meta { color: #666; font-size: .85rem; margin: .3rem 0 0; }
    .empty { color: #666; }
  </style>
</head>
<body>
  <h1>Videa — Shipard</h1>
${body}
</body>
</html>
`;
}

/**
 * Přegeneruje `index.html` ze skenu adresáře s videi.
 *
 * @param {string} outDir
 * @returns {Promise<number>} Počet videí v galerii.
 */
export async function generateGallery(outDir) {
  const names = (await readdir(outDir)).filter((name) => /\.mp4$/i.test(name));

  const entries = await Promise.all(names.map(async (name) => {
    const { size, mtime } = await stat(join(outDir, name));
    return { name, size, mtime };
  }));

  await writeFile(join(outDir, GALLERY_NAME), renderGallery(entries), 'utf8');
  return entries.length;
}
