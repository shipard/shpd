// Account preferences store — synchronizuje per-user nastavení (vzhled,
// jazyk) ze serveru, který je zdrojem pravdy. localStorage zůstává jen
// anti-flash cache (viz theme.svelte.js / language.svelte.js).
//
// Volá se po loginu / při bootu, když je uživatel přihlášený (App.svelte
// onSuccess + main.js při už autentizovaném startu). Po načtení aplikuje
// serverový vzhled (themeStore.applyFromServer — bez zpětného zápisu) a,
// pokud se liší, serverový jazyk (language.setMode → reload).

import { getAccountPrefs } from '../api/account.js';
import { themeStore } from './theme.svelte.js';
import { language } from '../i18n/index.js';

// Guard proti reload-smyčce: jazyk aplikujeme nejvýš jednou za život
// stránky. Po reloadu je localStorage už serverová hodnota, takže
// následný load() shledá shodu a nereloadne znovu — flag je belt-and-
// suspenders pro případ vícenásobného load() v rámci jednoho života.
let languageApplied = false;

async function load() {
  let response;
  try {
    response = await getAccountPrefs();
  } catch {
    return; // endpoint nedostupný / nepřihlášen — drží se cache
  }
  if (!response?.success || !response.data) return;

  const values = response.data.values ?? {};

  const theme = values['account.theme'];
  if (theme) {
    themeStore.applyFromServer(theme);
  }

  const lang = values['account.language'];
  if (lang && !languageApplied && lang !== language.mode) {
    languageApplied = true;
    language.setMode(lang); // zapíše localStorage + reloadne stránku
  }
}

export const accountPrefs = {
  load,
};
