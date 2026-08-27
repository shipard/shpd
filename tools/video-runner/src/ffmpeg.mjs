/**
 * Tenký obal nad ffmpegem. Jediné, co přidává, je čitelné selhání:
 * chybějící binárku hlásí návodem a z pádu ukazuje konec stderru, kde
 * ffmpeg píše skutečný důvod.
 */

import { spawn } from 'node:child_process';

import { UserError } from './errors.mjs';

/** Kolik posledních řádků stderru se ukáže při pádu. */
const STDERR_TAIL = 12;

/**
 * @param {string[]} args
 * @param {object} [options]
 * @param {string} [options.bin] Binárka (ffmpeg / ffprobe).
 * @param {(line: string) => void} [options.log]
 * @returns {Promise<string>} Celý stderr — ffmpeg tam píše i běžný výstup.
 */
export function run(args, { bin = 'ffmpeg', log } = {}) {
  return new Promise((resolve, reject) => {
    log?.(`  ${bin} ${args.join(' ')}`);

    const child = spawn(bin, args, { stdio: ['ignore', 'ignore', 'pipe'] });
    let stderr = '';

    child.stderr.on('data', (chunk) => { stderr += chunk; });

    child.on('error', (error) => {
      if (error.code === 'ENOENT') {
        reject(new UserError(
          `${bin} není nainstalovaný.`,
          'sudo apt install ffmpeg   (podrobnosti v INSTALL.md)',
        ));
        return;
      }
      reject(error);
    });

    child.on('close', (code) => {
      if (code === 0) { resolve(stderr); return; }
      const tail = stderr.trimEnd().split('\n').slice(-STDERR_TAIL).join('\n');
      reject(new UserError(`${bin} skončil s kódem ${code}:\n${tail}`));
    });
  });
}

/** Ověří dostupnost binárky dřív, než se natočí něco, co se nedá složit. */
export async function assertAvailable(bin = 'ffmpeg') {
  await run(['-version'], { bin });
}

/**
 * Dlouho běžící ffmpeg, který se ukončuje zvenčí (x11grab).
 *
 * Přidává dvě věci, které jinde nejsou potřeba:
 *
 * - `recorded()` — kolik sekund už je zaznamenaných. Čte se z `-progress`,
 *   takže se offset časové osy proti začátku videa **měří**, místo aby se
 *   hádal z toho, jak dlouho se ffmpeg nejspíš rozjíždí.
 * - ukončení klávesou `q` místo signálu — po SIGKILL by v souboru chyběl
 *   index a video by nešlo přehrát.
 *
 * @param {string[]} args Bez `-progress`, ten se doplní.
 * @param {object} [options]
 * @param {string} [options.bin]
 * @param {(line: string) => void} [options.log]
 */
export function start(args, { bin = 'ffmpeg', log } = {}) {
  const full = ['-progress', 'pipe:1', '-stats_period', '0.1', ...args];
  log?.(`  ${bin} ${full.join(' ')}`);

  const child = spawn(bin, full, { stdio: ['pipe', 'pipe', 'pipe'] });

  let stderr = '';
  let recordedUs = 0;
  let onProgress;
  const firstProgress = new Promise((resolve) => { onProgress = resolve; });

  child.stderr.on('data', (chunk) => { stderr += chunk; });

  let buffer = '';
  child.stdout.on('data', (chunk) => {
    buffer += chunk;
    const lines = buffer.split('\n');
    buffer = lines.pop() ?? '';
    for (const line of lines) {
      const match = /^out_time_us=(\d+)/.exec(line);
      if (match) {
        recordedUs = Number(match[1]);
        onProgress();
      }
    }
  });

  const done = new Promise((resolve, reject) => {
    child.on('error', (error) => {
      reject(error.code === 'ENOENT'
        ? new UserError(`${bin} není nainstalovaný.`, 'sudo apt install ffmpeg')
        : error);
    });
    child.on('close', (code) => {
      // `q` ukončí ffmpeg čistě s kódem 0; 255 vrací při přerušení, což
      // po `q` znamená, že se nestihl dopsat index.
      if (code === 0) { resolve(stderr); return; }
      const tail = stderr.trimEnd().split('\n').slice(-STDERR_TAIL).join('\n');
      reject(new UserError(`${bin} skončil s kódem ${code}:\n${tail}`));
    });
  });

  return {
    /** Rozjel se a nahrává? */
    started: firstProgress,
    /** Sekundy už zaznamenaného videa. */
    recorded: () => recordedUs / 1e6,
    /** Čisté ukončení — bez něj by v souboru chyběl index. */
    async stop() {
      child.stdin.write('q');
      child.stdin.end();
      await done;
    },
    kill() {
      child.kill('SIGKILL');
    },
  };
}
