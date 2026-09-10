/**
 * API helpers pro podání DPH.
 *
 * Backend endpoint (viz `modules/economy/vat/src/VatFilingController.php`):
 *   POST /_vat/filing-compose  body {"filingId": N}
 *   POST /_vat/filing-files    body {"filingId": N}
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

/**
 * Vyrobit soubory pro daňový portál (XML, PDF opis) a uložit je jako
 * přílohy podání. Vrací {filingId, files: [{kind, name}], warnings};
 * FILING_XML_INVALID (422) nese v `details` chyby polí hlavičky —
 * podání nejde vygenerovat, dokud se nedoplní. Ve stavu Sestaveno se dá
 * opakovat, u podaného podání jen doplní, co chybí.
 */
export async function generateFilingFiles(filingId) {
  return await post('/_vat/filing-files', { filingId });
}
