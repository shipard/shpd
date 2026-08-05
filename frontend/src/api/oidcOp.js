import { post } from './client.js';

/**
 * Schválení OIDC OP transakce (session bridge D10): SPA se session pošle
 * txn z ?op_auth=, server naváže uživatele, vydá autorizační kód a vrátí
 * redirect URL zpět na klientský DS.
 *
 * Vrací celý envelope (ne jen data) — volající rozlišuje OIDC_TXN_INVALID
 * od síťové chyby. null = 401 po neúspěšném refreshi (client.js).
 * @param {string} txn
 * @returns {Promise<{success: boolean, data?: {redirect: string}, error?: object}|null>}
 */
export function approveOpAuth(txn) {
  return post('/_hosting/oidc/approve', { txn });
}
