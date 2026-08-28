/**
 * Selektorová zkratka `@` (D13, #48).
 *
 * `@name` znamená `[data-testid="name"]` — nic víc. Jen přesná shoda,
 * žádný Playwrightí dialekt; plné CSS zůstává povolené pro okrajové
 * případy, na které testid (zatím) není.
 */

/**
 * Co smí být za zavináčem. Tečka a dvojtečka kvůli odvozeným testidům
 * navigace (`nav-viewer:core.mail.incoming`).
 */
export const TESTID_SELECTOR_RE = /^@[A-Za-z0-9_.:-]+$/;

/**
 * @param {string} selector Selektor ze scénáře (`@name` nebo CSS).
 * @returns {string} Selektor pro `page.locator` / `querySelector`.
 */
export function resolveSelector(selector) {
  return selector.startsWith('@')
    ? `[data-testid="${selector.slice(1)}"]`
    : selector;
}
