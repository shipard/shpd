/**
 * API helpers for the accounting subsystem.
 *
 * Backend endpoint (see `modules/economy/accounting/src/AccountingController.php`):
 *   POST /_accounting/reaccount  body {"docId": N}
 *
 * Deník samotný se čte jako jakákoliv jiná tabulka přes /_ui/viewer.
 */

import { post } from './client.js';

/**
 * Přeúčtovat doklad ve stavu 40 (po opravě rozvrhu / položky). Vrací
 * {accountingState, messages}; chybové kódy BAD_REQUEST, NOT_FOUND,
 * INVALID_DOC_STATE. Idempotentní — deník se vždy přegeneruje celý.
 */
export async function reaccountDocument(docId) {
  return await post('/_accounting/reaccount', { docId });
}
