/**
 * API helpers pro podání DPH.
 *
 * Backend endpoint (viz `modules/economy/vat/src/VatFilingController.php`):
 *   POST /_vat/filing-compose  body {"filingId": N}
 *
 * Podání samotná se čtou jako jakákoliv jiná tabulka přes /_ui/viewer.
 */

import { post } from './client.js';

/**
 * Přepočítat snapshot podání ve stavu Sestaveno z aktuálních dokladů
 * instance. Vrací {filingId, items, rows, isEmpty}; chybové kódy
 * BAD_REQUEST, NOT_FOUND, INVALID_DOC_STATE, FILING_COMPOSE_FAILED
 * (např. kód DPH bez mapování — z podání nesmí nic tiše vypadnout).
 * Idempotentní — snapshot se vždy přegeneruje celý.
 */
export async function recomposeFiling(filingId) {
  return await post('/_vat/filing-compose', { filingId });
}
