/**
 * Detect the data source ID and API base URL from the current page URL.
 *
 * In development mode the app is served at:
 *   http://{ip}/{ds-id}/app/
 * and the API lives at:
 *   http://{ip}/{ds-id}/api/v1/
 *
 * In production mode the app is served at:
 *   https://{subdomain}.shipard.cz/app/
 * and the API lives at:
 *   https://{subdomain}.shipard.cz/api/v1/
 *
 * We detect the DS ID by checking whether the URL path starts with a
 * segment matching the DS ID format (xxxx-xxxx-xxxx-xxxx).
 */

// Pozor: stejný regex duplikuje theme bootstrap v index.html (per-DS
// prefix localStorage klíčů musí běžet před načtením modulů). Změna
// formátu DS ID = upravit obě místa; viz komentáře tam
// a v stores/theme.svelte.js (čtyři synchronizovaná místa localStorage
// klíčů/formátů: theme.svelte.js, index.html bootstrap, tento regex
// a DS default cache klíče shpd_ds_theme* sdílené store↔bootstrap).
const DS_ID_PATTERN = /^\/([a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})\//;

function detectDsId() {
  const match = window.location.pathname.match(DS_ID_PATTERN);
  return match ? match[1] : null;
}

const dsId = detectDsId();

/** DS ID prefix for API requests, e.g. '/abcd-efgh-ijkl-mnop' or '' */
export const DS_PREFIX = dsId ? `/${dsId}` : '';

/** Full base URL for API requests, e.g. '/abcd-efgh-ijkl-mnop/api/v1' or '/api/v1' */
export const API_BASE_URL = `${DS_PREFIX}/api/v1`;

/** The detected data source ID, or null in production mode */
export const DATA_SOURCE_ID = dsId;
