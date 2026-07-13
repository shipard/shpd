/**
 * Zabezpečení účtu — autentizované endpointy fáze 0b: změna hesla, správa
 * vlastních relací, admin pozvánka. Jde přes client.js (Bearer token,
 * refresh-retry) na rozdíl od public flow v api/auth.js.
 */

import { get, post, del } from './client.js';

/** @returns {Promise<object|null>} envelope; null = odhlášeno */
export function changePassword(currentPassword, newPassword) {
  return post('/_auth/password/change', { currentPassword, newPassword });
}

/** @returns {Promise<object|null>} envelope s data.sessions[] */
export function listSessions() {
  return get('/_auth/sessions');
}

/** @returns {Promise<object|null>} */
export function deleteSession(id) {
  return del(`/_auth/sessions/${id}`);
}

/** @returns {Promise<object|null>} envelope s data.revoked_sessions */
export function revokeOtherSessions() {
  return post('/_auth/sessions/revoke-others', {});
}

/** Admin: pošle (nebo přepošle) pozvánkový mail uživateli. */
export function inviteUser(userId) {
  return post(`/_users/${userId}/invite`, {});
}
