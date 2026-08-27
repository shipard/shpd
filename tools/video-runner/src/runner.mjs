/**
 * Sestavení prohlížeče pro průchod scénářem. Sdílené mezi `check`
 * a oběma variantami záznamu, aby se aplikace ve všech třech případech
 * vykreslila stejně.
 */

import { existsSync } from 'node:fs';

import { DEFAULT_TIMEOUT, launchChromium } from './browser.mjs';
import { pageUrl } from './config.mjs';
import { UserError } from './errors.mjs';
import { installOverlay } from './overlay.mjs';
import { APP_PATH, isLoginScreen, SELECTORS } from './shipard.mjs';

/**
 * @param {import('./config.mjs').Config} config
 * @param {import('./scenario.mjs').Scenario} scenario
 * @param {object} [options]
 * @param {boolean} [options.headless]
 * @param {string[]} [options.args]
 * @param {Record<string,string>} [options.env]
 * @param {boolean} [options.windowSized] Rozměr určuje okno, ne viewport
 *   (kiosk režim ve Xvfb — tam by Playwright viewport okno zmenšil).
 */
export async function createSession(config, scenario, options = {}) {
  if (!existsSync(config.storageState)) {
    throw new UserError(
      'Uložená session neexistuje.',
      'Spusť nejdřív: video-runner login',
    );
  }

  const browser = await launchChromium({
    headless: options.headless ?? true,
    args: options.args,
    env: options.env,
  });

  const context = await browser.newContext({
    storageState: config.storageState,
    ...(options.windowSized
      ? { viewport: null }
      : {
        viewport: { width: scenario.viewport.width, height: scenario.viewport.height },
        deviceScaleFactor: scenario.capture.scale,
      }),
  });

  await installOverlay(context);
  const page = await context.newPage();

  return { browser, context, page, close: () => browser.close() };
}

/**
 * Ověří, že uložená session pořád platí — **dřív, než se začne nahrávat**.
 * Z vypršelé session nikdy nesmí vzniknout video přihlašovací stránky.
 *
 * Vedlejší efekt je užitečný: tahle první navigace zahřeje cache bundlu,
 * takže vlastní záznam nezačíná načítáním SPA.
 *
 * @param {import('playwright').Page} page
 * @param {import('./config.mjs').Config} config
 */
export async function assertSession(page, config) {
  const url = pageUrl(config, APP_PATH);

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: DEFAULT_TIMEOUT });

  try {
    await page.waitForSelector(`${SELECTORS.appShell}, ${SELECTORS.loginName}`, {
      timeout: DEFAULT_TIMEOUT,
    });
  } catch {
    throw new UserError(
      `Na ${url} se nenačetla ani aplikace, ani přihlašovací formulář.`,
      'Běží instance? Sedí SHPD_BASE_URL včetně ID datového zdroje?',
    );
  }

  if (await isLoginScreen(page)) {
    throw new UserError(
      'Session neplatná — aplikace ukazuje přihlašovací formulář.',
      'Spusť: video-runner login',
    );
  }
}
