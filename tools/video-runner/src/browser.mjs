/**
 * Spouštění Chromia. Sdílené mezi verbem `login` a interpretem scénáře,
 * aby se prohlížeč choval v obou případech stejně.
 */

import { chromium } from 'playwright';

import { UserError } from './errors.mjs';

/** Výchozí timeout čekání na prvek. Delší nemá smysl — scénář má být rychlý. */
export const DEFAULT_TIMEOUT = 15_000;

/**
 * Argumenty pro deterministické vykreslování. Bez nich se headless (varianta
 * záznamu `cdp`) a headed (`x11`) rozejdou v hintingu písma a v barevném
 * profilu — a pak nejde poznat, jestli je rozdíl ve videu způsobený
 * variantou záznamu, nebo jen jiným rendererem.
 */
const RENDER_ARGS = [
  '--force-color-profile=srgb',
  '--font-render-hinting=none',
];

/**
 * @param {object} [options]
 * @param {boolean} [options.headless]
 * @param {string[]} [options.args] Argumenty navíc (kiosk režim pro Xvfb).
 * @param {Record<string,string>} [options.env] Prostředí procesu (DISPLAY pro Xvfb).
 */
export async function launchChromium({ headless = true, args = [], env } = {}) {
  try {
    return await chromium.launch({ headless, args: [...RENDER_ARGS, ...args], env });
  } catch (error) {
    if (/Executable doesn't exist|please run the following command/i.test(error.message)) {
      throw new UserError(
        'Chromium pro Playwright není nainstalované.',
        'Spusť z tools/video-runner/: npx playwright install chromium',
      );
    }
    throw error;
  }
}
