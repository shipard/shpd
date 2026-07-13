/**
 * Čisté parsování startup auth akcí z URL (?auth_action=…&token=…) — mailové
 * linky set-password (pozvánka i reset hesla). Bez importů a bez window,
 * aby šlo testovat v node:test (vzor api/oidc.js).
 */

/**
 * @param {string} search window.location.search (včetně '?')
 * @returns {{kind: 'set-password', token: string} | null}
 */
export function parseAuthAction(search) {
  const params = new URLSearchParams(search);

  if (params.get('auth_action') === 'set-password') {
    const token = params.get('token');
    if (token) {
      return { kind: 'set-password', token };
    }
  }

  return null;
}
