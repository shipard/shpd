/**
 * Jediné místo, kde runner ví, že natáčí zrovna Shipard.
 *
 * Všechno ostatní je generické — scénáře si selektory nosí s sebou. Tady
 * bydlí jen to, co runner musí umět sám: přihlásit se a poznat, že je
 * přihlášený. Formulář odesílá Enter, takže se nemusí klikat na tlačítko,
 * jehož popisek se mění s jazykem.
 */

import { resolveSelector } from './selectors.mjs';

/** Cesta k SPA relativně k `SHPD_BASE_URL`. */
export const APP_PATH = '/app/';

// Hodnoty jsou přeložené už tady — call sites (login.mjs, runner.mjs) je
// dávají rovnou do Playwrightu.
export const SELECTORS = {
  /** frontend/src/components/auth/LoginScreen.svelte */
  loginName: resolveSelector('@login-name'),
  loginPassword: resolveSelector('@login-password'),
  loginError: resolveSelector('@login-error'),
  /** frontend/src/components/shells/*.svelte — kořen přihlášené aplikace, mají ho všechny shelly. */
  appShell: resolveSelector('@app-shell'),
};

/**
 * Je na stránce přihlašovací formulář? Používá `record` dřív, než zapne
 * záznam — z vypršelé session nikdy nesmí vzniknout video přihlašovací
 * stránky.
 *
 * @param {import('playwright').Page} page
 */
export async function isLoginScreen(page) {
  return (await page.locator(SELECTORS.loginName).count()) > 0;
}
