import { get } from './client.js';

/**
 * Fetch seznamu „moje DS" pro portálovou obrazovku (D10).
 * Server vrací výhradně řádky přihlášeného uživatele s lifecycle active.
 * `stats` (D7) = poslední push agregát z DS serveru, null bez snapshotu;
 * uvnitř alerts/mail null = modul na DS není aktivní.
 * @returns {Promise<Array<{id: number, ds_id: string, name: string, url_app: string, role: string,
 *   stats: {alerts: number|null, mail: number|null, collected_at: string}|null}>|null>}
 *   null při selhání (síť / neaktivní modul)
 */
export async function fetchMyDatasources() {
  const res = await get('/_hosting/portal/my-datasources');
  return res?.success ? (res.data.items ?? []) : null;
}
