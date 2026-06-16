/**
 * API helpers pro bankovní modul.
 *
 * Import výpisu používá multipart/form-data (ne JSON) — přímý fetch s Bearer
 * tokenem, vzor api/attachments.js.
 */

import { API_BASE_URL } from './config.js';
import { post } from './client.js';

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

/**
 * Přeúčtovat bankovní transakci ve stavu 40 (po opravě rozvrhu / pohybu).
 * Vrací {accountingState, messages}; chybové kódy BAD_REQUEST, NOT_FOUND,
 * INVALID_DOC_STATE. Idempotentní — deník se vždy přegeneruje celý.
 */
export async function reaccountTransaction(transactionId) {
  return await post('/_bank/reaccount', { transactionId });
}
