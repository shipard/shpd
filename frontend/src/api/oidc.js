/**
 * Pure OIDC helpery — bez importů a bez přístupu k window, aby šly
 * testovat v node:test (config.js čte window.location při importu).
 */

/**
 * URL start endpointu pro plnou navigaci (ne fetch) — prohlížeč následuje
 * 302 na authorize URL providera.
 * @param {string} apiBaseUrl např. '/api/v1' nebo '/{ds-id}/api/v1'
 * @param {string} providerId
 * @returns {string}
 */
export function buildOidcStartUrl(apiBaseUrl, providerId) {
  return `${apiBaseUrl}/_auth/oidc/start?provider=${encodeURIComponent(providerId)}`;
}

/**
 * Rozpozná OIDC návrat v query stringu SPA:
 *   ?login=oidc&code=…   → { kind: 'handoff', code }
 *   ?login_error=…       → { kind: 'error', error }
 *   jinak                → null
 * @param {string} search window.location.search (včetně '?')
 * @returns {{kind: 'handoff', code: string} | {kind: 'error', error: string} | null}
 */
export function parseOidcRedirect(search) {
  const params = new URLSearchParams(search);

  if (params.get('login') === 'oidc') {
    const code = params.get('code');
    if (code) {
      return { kind: 'handoff', code };
    }
  }

  const error = params.get('login_error');
  if (error) {
    return { kind: 'error', error };
  }

  return null;
}

/**
 * Rozpozná příchod od OIDC OP hostingu (session bridge D10):
 *   ?op_auth={txn} → txn string, jinak null.
 * @param {string} search window.location.search (včetně '?')
 * @returns {string|null}
 */
export function parseOpAuth(search) {
  const txn = new URLSearchParams(search).get('op_auth');
  return txn || null;
}
