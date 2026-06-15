/**
 * API helper pro per-user nastavení účtu (stránka accountBasic, scope user).
 *
 * Server je zdroj pravdy pro vzhled a jazyk; tyto helpery zapisují lokální
 * volbu zpět na server. Drženo zvlášť od accountPrefs storu, aby theme/language
 * stores mohly pushovat bez kruhového importu (accountPrefs → theme → account).
 */

import { get, post } from './client.js';

const ACCOUNT_PAGE = '/_ui/settings/page/accountBasic';

/** Načte hodnoty účtu (theme/language). @returns {Promise<object|null>} */
export function getAccountPrefs() {
  return get(ACCOUNT_PAGE);
}

/**
 * Zapíše část hodnot účtu na server. Selhání je tiché — lokální volba
 * platí pro session, sync se dožene příště.
 * @param {object} values - např. { 'account.theme': {mode, custom} } nebo { 'account.language': 'cs' }
 * @returns {Promise<object|null>}
 */
export function pushAccountPrefs(values) {
  return post(ACCOUNT_PAGE, { values });
}
