// API panelu dsSetup (docs/ds-setup.md D12/D14):
//   GET  /_setup/checklist   — živý checklist (setup checky) + hodnoty parametrů vrstvy C
//   POST /_setup/parameters  — zápis parametrů; odpověď má stejný tvar jako GET
//                              (+ warnings), panel nedělá druhý request
import { get, post } from './client.js';

export function fetchSetupChecklist() {
  return get('/_setup/checklist');
}

/** @param {Record<string, string|number|boolean|null>} values klíč vrstvy C → hodnota (null = vrátit na nerozhodnuto) */
export function saveSetupParameters(values) {
  return post('/_setup/parameters', { values });
}
