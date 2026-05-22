/**
 * API helpers for the persons registry wizard ("Přidat firmu z registru").
 *
 * Backend endpoints:
 *   GET  /api/v1/persons/registry?q=...          — search (existsInDb per row)
 *   GET  /api/v1/persons/registry/{country}/{id} — canonical shpd.persons.person
 *   POST /api/v1/_exchange/persons/person/preview — enriched canonical (_resolve)
 *   POST /api/v1/_exchange/persons/person/apply   — persist; returns savedPersonId
 *
 * The wizard intentionally goes through preview/apply (not the headless
 * /import endpoint) so the user sees the canonical we're about to save and
 * can bail out before commit.
 */

import { get, post } from './client.js';

/**
 * Search registry by query string. Empty query returns an empty results
 * envelope without hitting the network.
 *
 * @param {string} query
 * @returns {Promise<{success: boolean, data?: {results: Array<{
 *   country: string, companyId: string, fullName: string,
 *   vatId: string|null, isValid: boolean,
 *   validFrom: string|null, validTo: string|null,
 *   primaryAddressText: string|null, existsInDb: boolean,
 * }>}, error?: object}>}
 */
export async function searchRegistry(query) {
  const q = (query ?? '').trim();
  if (q === '') {
    return { success: true, data: { results: [] } };
  }
  return await get(`/persons/registry?q=${encodeURIComponent(q)}`);
}

/**
 * Fetch the canonical `shpd.persons.person` payload for a given
 * country + companyId pair. Returns the canonical directly as `data`.
 *
 * @param {string} country   ISO 3166-1 alpha-2 (lower-case)
 * @param {string} companyId
 */
export async function fetchRegistryPerson(country, companyId) {
  const c = encodeURIComponent(country);
  const id = encodeURIComponent(companyId);
  return await get(`/persons/registry/${c}/${id}`);
}

/**
 * Run preview against the canonical to enrich it with `_resolve`. The
 * wizard renders a static summary, so we use preview only to surface
 * applier-level validation issues before commit (and to forward the
 * enriched canonical to apply unchanged).
 *
 * @param {object} canonical  shpd.persons.person payload
 */
export async function previewRegistryPerson(canonical) {
  return await post('/_exchange/persons/person/preview', canonical);
}

/**
 * Apply the (enriched) canonical. Wizard always uses `createOnly` +
 * `targetDocState = 40` — UI gates selection so we never apply on an
 * existing record. Caller is responsible for setting `applyOptions`
 * before calling.
 *
 * @param {object} canonical
 * @returns {Promise<{success: boolean, data?: {canonical: object, savedPersonId: number}, error?: object}>}
 */
export async function applyRegistryPerson(canonical) {
  return await post('/_exchange/persons/person/apply', canonical);
}
