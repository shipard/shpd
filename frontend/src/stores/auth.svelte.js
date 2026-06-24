import { avatarStore } from './avatar.svelte.js';

const TOKEN_KEY = 'shpd_token';
const USER_KEY = 'shpd_user';

let token = $state(localStorage.getItem(TOKEN_KEY));
let user = $state(JSON.parse(localStorage.getItem(USER_KEY) || 'null'));

let isAuthenticated = $derived(token !== null);

/**
 * Store a successful login response — persist token and user to state and localStorage.
 * @param {{ data: { token: string, user: object, expires_at: string } }} loginResponse
 */
function setAuth(loginResponse) {
  token = loginResponse.data.token;
  user = loginResponse.data.user;
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}

/**
 * Clear all auth state and remove from localStorage.
 */
function clearAuth() {
  token = null;
  user = null;
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
  // Zahodit avatar blob přihlášeného uživatele (object URL revoke).
  avatarStore.clear();
}

/**
 * Update token only — called after a successful token refresh.
 * @param {string} newToken
 */
function updateToken(newToken) {
  token = newToken;
  localStorage.setItem(TOKEN_KEY, newToken);
}

function getToken() {
  return token;
}

function getUser() {
  return user;
}

export const authStore = {
  get token() { return token; },
  get user() { return user; },
  get isAuthenticated() { return isAuthenticated; },
  setAuth,
  clearAuth,
  updateToken,
  getToken,
  getUser,
};
