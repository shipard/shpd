// API panelu dsSetup (docs/ds-setup.md D12/D14, Fáze 4 §5.4):
//   GET  /_setup/checklist                — živý checklist (setup checky) + hodnoty parametrů vrstvy C
//   POST /_setup/parameters               — zápis parametrů; odpověď má stejný tvar jako GET
//                                           (+ warnings), panel nedělá druhý request
//   GET  /_setup/vat-registration-prefill — návrh hodnot Registrace DPH z vlastní Osoby
//   GET  /_setup/bank-account-candidates  — bankovní spojení vlastní Osoby k překlopení
//   POST /_setup/bank-accounts            — překlop vybraných spojení do číselníku
import { get, post } from './client.js';

export function fetchSetupChecklist() {
  return get('/_setup/checklist');
}

/** @param {Record<string, string|number|boolean|null>} values klíč vrstvy C → hodnota (null = vrátit na nerozhodnuto) */
export function saveSetupParameters(values) {
  return post('/_setup/parameters', { values });
}

/**
 * Předvyplnění Registrace DPH z vlastní Osoby + vrstvy A. Uložení pak jde
 * přes generický POST /_ui/form/economy_codebooks_vat_registrations/save,
 * aby se chytil hook na VatPeriodsProvisioner.
 *
 * @returns {Promise<{success: boolean, data?: {values: object,
 *   periodKindOptions: Array<{value: number, label: string}>}, error?: object}>}
 */
export function fetchVatRegistrationPrefill() {
  return get('/_setup/vat-registration-prefill');
}

/**
 * @returns {Promise<{success: boolean, data?: {candidates: Array<{
 *   id: number, name: string, accountNumber: string, iban: string,
 *   bic: string, currency: string, source: number,
 *   validFrom: string|null, validTo: string|null, existsInCodebook: boolean,
 * }>}, error?: object}>}
 */
export function fetchBankAccountCandidates() {
  return get('/_setup/bank-account-candidates');
}

/**
 * @param {number[]} personBankAccountIds
 * @param {number|null} defaultId id spojení, které bude v číselníku výchozí
 */
export function bridgeBankAccounts(personBankAccountIds, defaultId) {
  return post('/_setup/bank-accounts', { personBankAccountIds, defaultId });
}
