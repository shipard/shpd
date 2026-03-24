/**
 * Auth API functions — use raw fetch to avoid circular dependency with client.js
 * (client.js calls tryRefresh internally, so auth endpoints must bypass the wrapper).
 */

import { API_BASE_URL } from './config.js';

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
