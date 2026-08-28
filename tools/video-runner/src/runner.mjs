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
 * @param {number} [options.scale] Přebíje `capture.scale` ze scénáře — používá
 *   `check` při ladění s viditelným prohlížečem.
 */
export async function createSession(config, scenario, options = {}) {
  if (!existsSync(config.storageState)) {
    throw new UserError(
      'Uložená session neexistuje.',
      'Spusť nejdřív: video-runner login',
    );
  }

  // Geometrie je pro oba záznamy stejná a **nesmí** se dělat Playwrightním
  // `deviceScaleFactor`. Ten hustotu jen emuluje přes
  // `Emulation.setDeviceMetricsOverride` a CDP screencast pak posílá framy
  // ve velikosti emulovaného zařízení v DIP — tedy 1×, i když stránka hlásí
  // `devicePixelRatio: 2`. Skutečný raster ve dvojnásobné hustotě dělá
  // teprve `--force-device-scale-factor`, a rozměr okna musí přijít zvnějšku
  // (`viewport: null`), jinak ho Playwright přebíje zpátky na emulaci.
  const { width, height } = scenario.viewport;
  const scale = options.scale ?? scenario.capture.scale;

  const browser = await launchChromium({
    headless: options.headless ?? true,
    args: [
      `--window-size=${width},${height}`,
      '--window-position=0,0',
      `--force-device-scale-factor=${scale}`,
      // Headless scrollbary skrývá sám, headed ne. Bez tohohle by se
      // varianty záznamu lišily o šířku scrollbaru a vypadalo by to jako
      // rozdíl varianty, přitom je to rozdíl nastavení.
      '--hide-scrollbars',
      ...(options.args ?? []),
    ],
  });

  const context = await browser.newContext({
    storageState: config.storageState,
    viewport: null,
    // Jazyk aplikace se odvozuje z `navigator.language`, takže bez tohohle
    // by video vyšlo v angličtině (Playwright startuje v en-US).
    locale: scenario.locale,
    timezoneId: scenario.timezone,
  });

  await installOverlay(context);
  const page = await context.newPage();
  await calibrateViewport(context, page, width, height);

  return { browser, context, page, close: () => browser.close() };
}

/**
 * Dorovnání okna tak, aby viewport vyšel přesně na rozměr ze scénáře.
 *
 * Plné Chromium (`channel: 'chromium'`, viz browser.mjs) si z
 * `--window-size` ukrajuje výšku na okenní chrome: okno 1280×800 dá
 * viewport 1280×713. Headless shell nic neukrajoval, takže Z1 platilo
 * beze zbytku; spoléhat na konkrétní deltu ale nejde — mezi verzemi
 * Chromia se může hnout. Proto se viewport po startu změří a okno se
 * přes CDP `Browser.setWindowBounds` posune o rozdíl. Jednotky sedí:
 * `--window-size` i bounds jsou CSS px, hustotu řeší až
 * `--force-device-scale-factor`.
 *
 * @param {import('playwright').BrowserContext} context
 * @param {import('playwright').Page} page
 * @param {number} width  Cílový viewport v CSS px.
 * @param {number} height
 */
async function calibrateViewport(context, page, width, height) {
  const measure = () => page.evaluate(() => [window.innerWidth, window.innerHeight]);

  const cdp = await context.newCDPSession(page);
  try {
    for (let attempt = 0; attempt < 3; attempt++) {
      const [w, h] = await measure();
      if (w === width && h === height) return;

      const { windowId } = await cdp.send('Browser.getWindowForTarget');
      const { bounds } = await cdp.send('Browser.getWindowBounds', { windowId });
      await cdp.send('Browser.setWindowBounds', {
        windowId,
        bounds: {
          width: bounds.width + (width - w),
          height: bounds.height + (height - h),
        },
      });
      // Resize se do stránky propíše asynchronně; další měření počká,
      // až se skutečně stane, jinak by smyčka korigovala dvakrát totéž.
      await page
        .waitForFunction(
          ([tw, th]) => window.innerWidth === tw && window.innerHeight === th,
          [width, height],
          { timeout: 1000 },
        )
        .catch(() => {});
    }

    const [w, h] = await measure();
    if (w !== width || h !== height) {
      throw new UserError(
        `Viewport se nepodařilo dorovnat na ${width}×${height}, zůstal ${w}×${h}.`,
        'Změnilo se chování oken v nové verzi Chromia? Viz Z9 v tasks/video-runner-spike.md.',
      );
    }
  } finally {
    await cdp.detach().catch(() => {});
  }
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
