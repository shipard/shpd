/**
 * API helpers pro bankovní modul.
 *
 * Import výpisu používá multipart/form-data (ne JSON) — přímý fetch s Bearer
 * tokenem, vzor api/attachments.js.
 */

import { API_BASE_URL } from './config.js';

const TOKEN_KEY = 'shpd_token';

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

/**
 * Naimportuje bankovní výpis ze souboru.
 * @param {File} file - soubor výpisu (CAMT/GPC/FIO)
 * @param {string|null} account - volitelný override účtu (kód nebo id)
 * @returns {Promise<object>} { success, data: { format, created, skipped, unmatchedPartner, statements } }
 */
export async function importStatement(file, account = null) {
  const formData = new FormData();
  formData.append('file', file);
  if (account) formData.append('account', String(account));

  const headers = {};
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_bank/import-statement`, {
    method: 'POST',
    headers,
    body: formData,
  });
  return res.json();
}
