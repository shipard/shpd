// Shell resolution — čisté funkce bez runes (unit-testovatelné přes
// node --test, viz tests/Unit/shell.test.mjs).
//
// „Shell" = komponenta chrome aplikace (sidebar | classic | wild).
// Efektivní shell = follow ? (DS default ?? 'sidebar') : override. Neznámé
// jméno padá na 'sidebar' — serverový allowlist (SettingsController) je
// první pojistka, tohle druhá (stale localStorage / config po odebrání
// shellu).

// Jediný zdroj pravdy jmen shellů na klientu — registry komponent
// (components/shells/index.js) musí mapovat přesně tato jména; store
// komponenty importovat nesmí (kruhový import), proto seznam žije tady.
export const KNOWN_SHELLS = ['sidebar', 'classic', 'wild'];

export const DEFAULT_SHELL = 'sidebar';

/**
 * Efektivní shell z follow/override/DS defaultu.
 *
 * @param {boolean} follow      sleduje uživatel DS default?
 * @param {string|null} override  jméno shellu z account.shell (platí když !follow)
 * @param {string|null} dsDefault jméno shellu z app.shell (DS default)
 * @param {string[]} known      povolená jména (default KNOWN_SHELLS)
 * @returns {string} jméno shellu, vždy validní (fallback DEFAULT_SHELL)
 */
export function resolveShell(follow, override, dsDefault, known = KNOWN_SHELLS) {
  const pick = follow ? dsDefault : override;
  return (typeof pick === 'string' && known.includes(pick)) ? pick : DEFAULT_SHELL;
}

/**
 * Serverová hodnota account.shell → {follow, shell} | null.
 * Tvary: {follow:true} | {follow:false, shell, params} | legacy {shell}
 * (bez follow → override, stejná konvence jako theme). Garbage → null
 * (volající hodnotu ignoruje a drží stávající stav).
 */
export function normalizeShellValue(value) {
  if (!value || typeof value !== 'object') return null;
  if (value.follow === true) return { follow: true, shell: null };
  if (typeof value.shell !== 'string' || value.shell === '') return null;
  return { follow: false, shell: value.shell };
}

/**
 * DS default z /_app/info ({shell, params} | null) → jméno shellu | null.
 * Neznámá jména propouští — validaci dělá resolveShell (a server allowlist).
 */
export function normalizeDsShell(value) {
  if (!value || typeof value !== 'object') return null;
  return (typeof value.shell === 'string' && value.shell !== '') ? value.shell : null;
}
