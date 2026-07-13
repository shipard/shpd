/**
 * Auth API functions — use raw fetch to avoid circular dependency with client.js
 * (client.js calls tryRefresh internally, so auth endpoints must bypass the wrapper).
 */

import { API_BASE_URL } from './config.js';
import { buildOidcStartUrl } from './oidc.js';

const TOKEN_KEY = 'shpd_token';

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

/**
 * Log in with login name and password.
 * @param {string} loginName
 * @param {string} password
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function login(loginName, password) {
  const response = await fetch(`${API_BASE_URL}/_auth/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
    },
    body: JSON.stringify({ login: loginName, password }),
  });

  return response.json();
}

/**
 * Refresh the current token.
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function refresh() {
  const token = getToken();

  const response = await fetch(`${API_BASE_URL}/_auth/refresh`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
      ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    },
  });

  return response.json();
}

/**
 * URL OIDC start endpointu pro daného providera — plná navigace prohlížeče
 * (window.location.href), ne fetch; server odpoví 302 na IdP.
 * @param {string} providerId
 * @returns {string}
 */
export function oidcStartUrl(providerId) {
  return buildOidcStartUrl(API_BASE_URL, providerId);
}

/**
 * Vymění jednorázový handoff kód z OIDC callbacku za session token.
 * Vrací stejný envelope jako login ({token, expires_at, user}).
 * @param {string} code
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function exchangeOidc(code) {
  const response = await fetch(`${API_BASE_URL}/_auth/oidc/exchange`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
    },
    body: JSON.stringify({ code }),
  });

  return response.json();
}

/**
 * Zapomenuté heslo — {identifier} je login nebo e-mail. Server vrací vždy
 * 200 (anti-enumerace), odpověď nic neprozrazuje.
 * @param {string} identifier
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function forgotPassword(identifier) {
  const response = await fetch(`${API_BASE_URL}/_auth/password/forgot`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
    },
    body: JSON.stringify({ identifier }),
  });

  return response.json();
}

/**
 * Nastavení hesla přes jednorázový token z mailu (pozvánka i reset).
 * @param {string} token
 * @param {string} password
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function resetPassword(token, password) {
  const response = await fetch(`${API_BASE_URL}/_auth/password/reset`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
    },
    body: JSON.stringify({ token, password }),
  });

  return response.json();
}

/**
 * Invalidate the current token (logout).
 * @returns {Promise<object>} API envelope {success, data, error?}
 */
export async function logout() {
  const token = getToken();

  const response = await fetch(`${API_BASE_URL}/_auth/logout`, {
    method: 'DELETE',
    headers: {
      'Content-Type': 'application/json',
      'Accept-Language': 'cs',
      ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    },
  });

  return response.json();
}
