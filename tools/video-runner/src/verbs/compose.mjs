/**
 * ③ Kompozitor — z `raw.mp4` + `timeline.json` hotové video.
 *
 * Pointa celé čtyřvrstvé pipeline (D2): tenhle krok se dá pustit samostatně
 * nad už natočeným záznamem, takže přepsání titulku stojí sekundy místo
 * přetáčení.
 */

import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { join, relative } from 'node:path';

import { buildCaptions, checkReadability, renderAss } from '../captions.mjs';
import { PROJECT_ROOT } from '../config.mjs';
import { UserError } from '../errors.mjs';
import * as ffmpeg from '../ffmpeg.mjs';
import { loadScenario } from '../scenario.mjs';

/** libavfilter dělí volby dvojtečkou, takže cesta se musí escapovat. */
function filterPath(path) {
  return path.replace(/\\/g, '\\\\').replace(/:/g, '\\:').replace(/'/g, "\\'");
}

/**
 * Sdílené s verbem `build`.
 *
 * @param {import('./../config.mjs').Config} config
 * @param {import('./../scenario.mjs').Scenario} scenario
 * @returns {Promise<string>} Cesta k výslednému videu.
 */
export async function composeScenario(config, scenario) {
  await ffmpeg.assertAvailable();

  const dir = join(config.workDir, scenario.id);
  const rawPath = join(dir, 'raw.mp4');
  const timelinePath = join(dir, 'timeline.json');

  for (const [path, what] of [[rawPath, 'záznam'], [timelinePath, 'časová osa']]) {
    if (!existsSync(path)) {
      throw new UserError(
        `Chybí ${what}: ${relative(PROJECT_ROOT, path)}`,
        `Natoč nejdřív: video-runner record ${relative(process.cwd(), scenario.path)}`,
      );
    }
  }

  const timeline = JSON.parse(await readFile(timelinePath, 'utf8'));
  const captions = buildCaptions(timeline);

  for (const warning of checkReadability(captions)) {
    console.warn(`  varování: ${warning}`);
  }

  const assPath = join(dir, 'captions.ass');
  await writeFile(assPath, renderAss(captions, scenario.output), 'utf8');

  await mkdir(config.outDir, { recursive: true });
  const outPath = join(config.outDir, `${scenario.id}.mp4`);

  const filters = [
    `scale=${scenario.output.w}:${scenario.output.h}:flags=lanczos`,
    // Titulky až za škálováním — vypálené před ním by se downscalem
    // rozmazaly stejně jako zbytek obrazu.
    `subtitles=${filterPath(assPath)}`,
  ].join(',');

  await ffmpeg.run([
    '-y',
    '-i', rawPath,
    '-vf', filters,
    '-c:v', 'libx264', '-crf', '20', '-preset', 'medium',
    '-pix_fmt', 'yuv420p',
    '-movflags', '+faststart',
    '-an',
    outPath,
  ], { log: (line) => console.log(line) });

  console.log(
    `Hotovo: ${relative(PROJECT_ROOT, outPath)} `
    + `(${scenario.output.w}×${scenario.output.h}, ${captions.length} titulků)`,
  );

  return outPath;
}

export default async function compose({ config, scenarioPath }) {
  const scenario = await loadScenario(scenarioPath);
  await composeScenario(config, scenario);
}
