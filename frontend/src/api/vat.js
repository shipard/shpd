/**
 * API helpers pro podání DPH.
 *
 * Backend endpoint (viz `modules/economy/vat/src/VatFilingController.php`):
 *   POST /_vat/filing-compose              body {"filingId": N}
 *   POST /_vat/filing-files                body {"filingId": N}
 *   POST /_vat/filing-header-from-profile  body {"filingId": N}
 *   POST /_vat/report-period-lock          body {"periodId": N, "locked": bool}
 *   POST /_vat/filing-account              body {"filingId": N}
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

/**
 * Přepsat hlavičku podání ve stavu Sestaveno předvyplněním z podacích
 * údajů registrace (profil podatele) a z vlastní firmy. Ruční úpravy
 * hlavičky zaniknou — volající se před tím ptá. Vrací {filingId}; chybové
 * kódy BAD_REQUEST, NOT_FOUND, INVALID_DOC_STATE, FILING_HEADER_RESET_FAILED.
 */
export async function reloadFilingHeader(filingId) {
  return await post('/_vat/filing-header-from-profile', { filingId });
}

/**
 * Zamknout / odemknout instanci tvrzení (#55 D25). Uložení jde přes
 * Document instance (guardy, locked_at/by), vrací {periodId, locked,
 * lockedAt, lockedBy}; chybové kódy BAD_REQUEST, NOT_FOUND,
 * INVALID_DOC_STATE, VALIDATION_ERROR (`details` = chyby polí), DOMAIN_ERROR.
 * Idempotentní — stejný stav nic nezapíše.
 */
export async function lockReportPeriod(periodId, locked) {
  return await post('/_vat/report-period-lock', { periodId, locked });
}

/**
 * Zaúčtovat podané přiznání DPH (#55 D28–D31): založí účetní doklad
 * (cmnbkp, koncept) s vynulováním analytik 343 a saldo řádkem vůči správci
 * daně a naváže ho na podání. Vrací {filingId, docId, rows, warnings};
 * chybové kódy NOT_FOUND, INVALID_REPORT_TYPE, INVALID_DOC_STATE,
 * ALREADY_ACCOUNTED (živý doklad existuje — nejdřív storno), CONFIG_MISSING,
 * SERIES_MISSING, PLAN_FAILED / NOTHING_TO_ACCOUNT, SAVE_FAILED (`details`
 * = zprávy plánu a validace, např. zámek fiskálního měsíce).
 */
export async function accountFiling(filingId) {
  return await post('/_vat/filing-account', { filingId });
}
