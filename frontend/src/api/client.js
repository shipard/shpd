const BASE_URL = '/api/v1';
const TOKEN_KEY = 'shpd_token';

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

function clearToken() {
  localStorage.removeItem(TOKEN_KEY);
}

/**
 * Attempt to refresh the current token.
 * Returns true if refresh succeeded and new token was stored.
 */
async function tryRefresh() {
  const token = getToken();
  if (!token) return false;

  try {
    const response = await fetch(`${BASE_URL}/_auth/refresh`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept-Language': 'cs',
        'Authorization': `Bearer ${token}`,
      },
    });

    if (!response.ok) return false;

    const data = await response.json();
    if (data.success && data.data?.token) {
      setToken(data.data.token);
      return true;
    }

    return false;
  } catch {
    return false;
  }
}

/**
 * Core request function. Retries once after a successful token refresh on 401.
 * @param {string} method
 * @param {string} path
 * @param {object|null} body
 * @param {boolean} isRetry - true when this call is already a retry after refresh
 * @returns {Promise<object|null>}
 */
async function apiRequest(method, path, body = null, isRetry = false) {
  const headers = {
    'Content-Type': 'application/json',
    'Accept-Language': 'cs',
  };

  const token = getToken();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  let response;
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      headers,
      body: body !== null ? JSON.stringify(body) : null,
    });
  } catch {
    return { success: false, error: { code: 'NETWORK_ERROR', message: 'Network error' } };
  }

  if (response.status === 401 && !isRetry) {
    const refreshed = await tryRefresh();
    if (refreshed) {
      return apiRequest(method, path, body, true);
    }
    clearToken();
    return null;
  }

  return response.json();
}

export function get(path) {
  return apiRequest('GET', path);
}

export function post(path, body) {
  return apiRequest('POST', path, body);
}

export function put(path, body) {
  return apiRequest('PUT', path, body);
}

export function patch(path, body) {
  return apiRequest('PATCH', path, body);
}

export function del(path) {
  return apiRequest('DELETE', path);
}
