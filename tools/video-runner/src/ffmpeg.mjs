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
