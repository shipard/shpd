import { get, post } from './client.js';

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

/**
 * Meta pro wizard nového DS (hosting-08): canCreate + reason
 * (no_server | open_request | max_owned), selfService install moduly,
 * jazyky/země + defaults. Vrací surový envelope {success, data, error},
 * aby volající viděl error.code.
 */
export async function fetchCreateMeta() {
  return await get('/_hosting/portal/create-meta');
}

/**
 * Živá kontrola web_id ve wizardu — informativní, finální autorita je
 * validace při create.
 * @returns {Promise<{success: boolean, data?: {available: boolean, reason: string|null}}|null>}
 */
export async function checkWebId(value) {
  return await get('/_hosting/portal/check-web-id?value=' + encodeURIComponent(value));
}

/**
 * Založení požadavku na nový DS. Úspěch vrací {item} ve tvaru pending
 * karty z my-datasources; 422 nese error.details = [{field, code, message}].
 * @param {{name: string, web_id: string, language: string, country: string, install_module?: string}} payload
 */
export async function createDatasource(payload) {
  return await post('/_hosting/portal/create-datasource', payload);
}
