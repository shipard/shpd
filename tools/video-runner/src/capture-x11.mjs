/**
 * Varianta záznamu B — headed Chromium ve Xvfb, obraz bere
 * `ffmpeg -f x11grab`.
 *
 * Proti variantě `cdp` má konstantní FPS nativně a zachytí CSS transitions
 * tak, jak je vidí člověk. Platí se za to Xvfb a tím, že se časová osa
 * musí srovnat s během ffmpegu.
 *
 * Srovnání se **měří, nehádá**: ve chvíli, kdy začíná scénář, se ffmpegu
 * přečte přes `-progress`, kolik sekund už má nahráno, a ta hodnota jde do
 * `timeline.rawOffset`. `compose` ji odřízne.
 */

import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { UserError } from './errors.mjs';
import * as ffmpeg from './ffmpeg.mjs';
import { interpret } from './interpret.mjs';
import { assertSession, createSession } from './runner.mjs';

/** Rozsah čísel displejů, ve kterém se hledá volné. */
const DISPLAY_RANGE = [99, 120];

const XVFB_TIMEOUT_MS = 5000;
const FFMPEG_TIMEOUT_MS = 10_000;

/**
 * Pauza navíc po rozjetí ffmpegu. Progress hlásí, že běží, ale prvních pár
 * framů bývá z rozjezdu X serveru ještě neusazených.
 */
const SETTLE_MS = 500;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function socketPath(display) {
  return `/tmp/.X11-unix/X${display}`;
}

function pickDisplay() {
  // Bez tohohle adresáře Xvfb socket nevytvoří (a nesmí si ho založit sám,
  // pokud neběží jako root) — čekání na socket by pak jen doběhlo do
  // timeoutu s hláškou, která neřekne nic užitečného.
  if (!existsSync('/tmp/.X11-unix')) {
    throw new UserError(
      'Adresář /tmp/.X11-unix neexistuje, Xvfb v něm nevytvoří socket.',
      'sudo mkdir -p /tmp/.X11-unix && sudo chmod 1777 /tmp/.X11-unix',
    );
  }

  for (let n = DISPLAY_RANGE[0]; n <= DISPLAY_RANGE[1]; n++) {
    if (!existsSync(socketPath(n))) return n;
  }
  throw new UserError(
    `Ve rozsahu :${DISPLAY_RANGE[0]}–:${DISPLAY_RANGE[1]} není volný X displej.`,
    'Běží ti tam zapomenuté Xvfb? Zkontroluj: ls /tmp/.X11-unix/',
  );
}

/** @returns {Promise<{display: string, stop: () => void}>} */
async function startXvfb(width, height, log) {
  const number = pickDisplay();
  const display = `:${number}`;

  log?.(`  Xvfb ${display} -screen 0 ${width}x${height}x24`);
  const child = spawn('Xvfb', [display, '-screen', '0', `${width}x${height}x24`, '-nolisten', 'tcp'], {
    stdio: ['ignore', 'ignore', 'pipe'],
  });

  let stderr = '';
  child.stderr.on('data', (chunk) => { stderr += chunk; });

  let failed = null;
  child.on('error', (error) => {
    failed = error.code === 'ENOENT'
      ? new UserError('Xvfb není nainstalovaný.', 'sudo apt install xvfb   (nebo použij --capture=cdp)')
      : error;
  });
  child.on('exit', (code) => {
    if (failed === null && code !== 0) {
      failed = new UserError(`Xvfb skončil s kódem ${code}:\n${stderr.trimEnd()}`);
    }
  });

  const deadline = Date.now() + XVFB_TIMEOUT_MS;
  while (!existsSync(socketPath(number))) {
    if (failed) throw failed;
    if (Date.now() > deadline) {
      child.kill('SIGKILL');
      throw new UserError(`Xvfb se na ${display} nerozběhl do ${XVFB_TIMEOUT_MS / 1000} s.`);
    }
    await sleep(50);
  }

  return { display, stop: () => child.kill('SIGTERM') };
}

/**
 * @param {object} params
 * @param {import('./config.mjs').Config} params.config
 * @param {import('./scenario.mjs').Scenario} params.scenario
 * @param {import('./timeline.mjs').Timeline} params.timeline
 * @param {string} params.dir
 * @param {(line: string) => void} [params.log]
 * @returns {Promise<string>} Cesta k raw.mp4.
 */
export default async function captureX11({ config, scenario, timeline, dir, log }) {
  const { w, h, scale } = scenario.capture;
  const rawPath = join(dir, 'raw.mp4');

  const xvfb = await startXvfb(w, h, log);
  let recorder = null;
  let session = null;

  try {
    session = await createSession(config, scenario, {
      headless: false,
      // Kiosk vyplní celou obrazovku, takže se nemusí odečítat výška
      // prohlížečového chromu — grabuje se rovnou celý displej. Rozměr
      // okna určuje displej, ne Playwright, proto `windowSized`.
      windowSized: true,
      args: [
        '--kiosk',
        '--window-position=0,0',
        `--force-device-scale-factor=${scale}`,
      ],
      env: { ...process.env, DISPLAY: xvfb.display },
    });

    await assertSession(session.page, config);

    recorder = ffmpeg.start([
      '-y',
      '-f', 'x11grab',
      '-draw_mouse', '0',
      '-framerate', String(scenario.output.fps),
      '-video_size', `${w}x${h}`,
      '-i', `${xvfb.display}.0`,
      '-c:v', 'libx264', '-crf', '16', '-preset', 'veryfast',
      '-pix_fmt', 'yuv420p',
      rawPath,
    ], { log });

    await Promise.race([
      recorder.started,
      sleep(FFMPEG_TIMEOUT_MS).then(() => {
        throw new UserError(`ffmpeg se do ${FFMPEG_TIMEOUT_MS / 1000} s nerozjel.`);
      }),
    ]);
    await sleep(SETTLE_MS);

    // Kolik rawu předchází nule scénáře — změřeno, ne odhadnuto.
    timeline.setRawOffset(recorder.recorded());
    timeline.start();
    log?.(`  ffmpeg nahrává, offset osy ${timeline.rawOffset.toFixed(2)} s`);

    await interpret({ page: session.page, config, scenario, timeline, log });
    timeline.finish();

    await recorder.stop();
    recorder = null;
  } finally {
    // Pořadí je podstatné: ffmpeg musí skončit dřív, než zmizí displej,
    // ze kterého čte.
    recorder?.kill();
    await session?.close();
    xvfb.stop();
  }

  return rawPath;
}
