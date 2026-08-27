/**
 * Jediné místo, kde runner ví, že natáčí zrovna Shipard.
 *
 * Všechno ostatní je generické — scénáře si selektory nosí s sebou. Tady
 * bydlí jen to, co runner musí umět sám: přihlásit se a poznat, že je
 * přihlášený.
 *
 * Selektory jsou zatím CSS (`data-testid` v aplikaci nejsou, viz README →
 * Známé dluhy). Přihlašovací pole mají naštěstí stabilní `id` a formulář
 * odesílá Enter, takže se nemusí klikat na tlačítko, jehož popisek se mění
 * s jazykem.
 */

/** Cesta k SPA relativně k `SHPD_BASE_URL`. */
export const APP_PATH = '/app/';

export const SELECTORS = {
  /** frontend/src/components/auth/LoginScreen.svelte */
  loginName: '#login-name',
  loginPassword: '#login-password',
  loginError: '.shpd-login__error',
  /** frontend/src/components/shells/SidebarShell.svelte — kořen přihlášené aplikace. */
  appShell: '.shpd-shell',
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
