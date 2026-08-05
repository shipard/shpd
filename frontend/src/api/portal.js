import { get } from './client.js';

/**
 * Fetch seznamu „moje DS" pro portálovou obrazovku (D10).
 * Server vrací výhradně řádky přihlášeného uživatele s lifecycle active.
 * @returns {Promise<Array<{id: number, ds_id: string, name: string, url_app: string, role: string}>|null>}
 *   null při selhání (síť / neaktivní modul)
 */
export async function fetchMyDatasources() {
  const res = await get('/_hosting/portal/my-datasources');
  return res?.success ? (res.data.items ?? []) : null;
}
