/**
 * API helpers pro /_app/* endpointy — veřejné app info a branding obrázky.
 *
 * GET endpointy jsou veřejné (fungují i bez tokenu — login obrazovka,
 * favicon). Upload jde multipart/form-data vlastním fetch (apiRequest
 * posílá JSON), DELETE přes sdílený client.
 */

import { API_BASE_URL } from './config.js';
import { get } from './client.js';

const TOKEN_KEY = 'shpd_token';

/**
 * Načte veřejné info o aplikaci.
 * @returns {Promise<object|null>} { name, shortName, icon, companyLogo }
 */
export function getAppInfo() {
  return get('/_app/info');
}

/**
 * Nahraje obrázek do branding slotu.
 * @param {string} slot - 'icon' | 'companyLogo'
 * @param {File} file
 * @returns {Promise<object>}
 */
export async function uploadBranding(slot, file) {
  const formData = new FormData();
  formData.append('file', file);

  // Bez Content-Type — FormData si nastaví multipart boundary sama.
  const headers = {};
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_app/branding/${encodeURIComponent(slot)}`, {
    method: 'POST',
    headers,
    body: formData,
  });
  return res.json();
}

/**
 * Smaže obrázek branding slotu (soubor i metadata).
 * Vlastní fetch — sdílený client volá res.json(), které by na 204 padlo.
 * @param {string} slot
 * @returns {Promise<boolean>} true při úspěchu (204)
 */
export async function deleteBranding(slot) {
  const headers = {};
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_app/branding/${encodeURIComponent(slot)}`, {
    method: 'DELETE',
    headers,
  });
  return res.status === 204;
}

/**
 * URL pro zobrazení branding slotu. Hash slouží jako cache-buster —
 * server posílá immutable cache hlavičky.
 * @param {string} slot
 * @param {string} hash
 * @returns {string}
 */
export function brandingUrl(slot, hash) {
  return `${API_BASE_URL}/_app/branding/${encodeURIComponent(slot)}?h=${encodeURIComponent(hash ?? '')}`;
}

// --- Avatar přihlášeného uživatele -----------------------------------------
// Per-user, celé za auth (i GET). Endpoint nenese {userId} — uživatel se bere
// z tokenu. Metadata žijí v core_system_user_settings (account.avatar).

/**
 * Nahraje avatar přihlášeného uživatele. Server obrázek downscaluje.
 * @param {File} file
 * @returns {Promise<object>} { success, data: { url, hash, filename, mime } }
 */
export async function uploadAvatar(file) {
  const formData = new FormData();
  formData.append('file', file);

  const headers = {};
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_app/avatar`, {
    method: 'POST',
    headers,
    body: formData,
  });
  return res.json();
}

/**
 * Smaže avatar přihlášeného uživatele (soubor i metadata).
 * @returns {Promise<boolean>} true při úspěchu (204)
 */
export async function deleteAvatar() {
  const headers = {};
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_app/avatar`, {
    method: 'DELETE',
    headers,
  });
  return res.status === 204;
}

/**
 * URL pro zobrazení avataru přihlášeného uživatele. GET vyžaduje auth, takže
 * <img src> sám token neposílá — endpoint čte ?h={hash} pro cache-busting a
 * spoléhá na to, že prohlížeč u stejného originu pošle session. Pro Bearer
 * režim používáme blob fetch v avatar storu; tato URL je pro cache-buster klíč.
 * @param {string} hash
 * @returns {string}
 */
export function avatarUrl(hash) {
  return `${API_BASE_URL}/_app/avatar?h=${encodeURIComponent(hash ?? '')}`;
}
