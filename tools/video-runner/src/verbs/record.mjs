/**
 * Verb `record` — ① interpret + ② overlay → `raw.mp4` a `timeline.json`.
 *
 * Obě varianty záznamu končí stejnými dvěma artefakty; `compose` pak
 * nemusí vědět, kterou cestou vznikly.
 */

import { mkdir, rm } from 'node:fs/promises';
import { join, relative } from 'node:path';

import captureCdp from '../capture-cdp.mjs';
import captureX11 from '../capture-x11.mjs';
import { PROJECT_ROOT } from '../config.mjs';
import * as ffmpeg from '../ffmpeg.mjs';
import { loadScenario } from '../scenario.mjs';
import { Timeline } from '../timeline.mjs';

const DRIVERS = { cdp: captureCdp, x11: captureX11 };

/**
 * Sdílené s verbem `build`, aby se scénář nenačítal dvakrát.
 *
 * @param {import('./../config.mjs').Config} config
 * @param {import('./../scenario.mjs').Scenario} scenario
 * @param {'cdp'|'x11'} capture
 * @returns {Promise<{dir: string, rawPath: string, timelinePath: string}>}
 */
export async function recordScenario(config, scenario, capture) {
  await ffmpeg.assertAvailable();

  const dir = join(config.workDir, scenario.id);
  // Zbytky z minulého běhu by při pádu uprostřed vypadaly jako platný
  // výsledek — a `compose` by beze slova složil staré video.
  await rm(dir, { recursive: true, force: true });
  await mkdir(dir, { recursive: true });

  const timeline = new Timeline(scenario, capture);
  const log = (line) => console.log(line);

  console.log(`record ${scenario.id} --capture=${capture}`);
  const rawPath = await DRIVERS[capture]({ config, scenario, timeline, dir, log });

  const timelinePath = join(dir, 'timeline.json');
  await timeline.write(timelinePath);

  console.log(
    `Záznam ${timeline.duration().toFixed(1)} s → ${relative(PROJECT_ROOT, dir)}/`
    + ` (raw.mp4, timeline.json)`,
  );

  return { dir, rawPath, timelinePath };
}

export default async function record({ config, scenarioPath, capture }) {
  const scenario = await loadScenario(scenarioPath);
  await recordScenario(config, scenario, capture);
}
