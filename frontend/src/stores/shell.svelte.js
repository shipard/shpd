// Shell store — volba chrome aplikace (sidebar | classic), UI shells Fáze 4.
//
// Dvouúrovňový model po vzoru theme.svelte.js:
//   account.shell (per-user, server) → follow vs. override
//   app.shell     (per-DS, server)   → DS-wide default
//   efektivní shell = follow ? (DS default ?? 'sidebar') : override
//
// ZÁMĚRNĚ bez anti-flash mechaniky theme storu (žádný bootstrap
// v index.html, žádné tokeny) — shell rozhoduje, který komponentový strom
// se mountne, což se děje až po bootu JS; není co pre-paintovat.
// localStorage je jen boot cache, aby reload před dokončením
// accountPrefs.load() nezačal ve špatném shellu; špatná cache = jeden
// soft swap po serverovém syncu, přijatelné. Nikdy sem nepřidávat
// bootstrap do index.html — synchronizované místo je jen tento soubor.
//
// Keys (per-DS suffix ':{dsId}' v dev — vzor theme.svelte.js):
//   shpd_shell    — 'follow' | jméno shellu (override)
//   shpd_ds_shell — jméno DS default shellu (cache pro boot před appInfo)

import { DATA_SOURCE_ID } from '../api/config.js';
import { pushAccountPrefs } from '../api/account.js';
import {
  resolveShell,
  normalizeShellValue,
  normalizeDsShell,
  KNOWN_SHELLS,
  DEFAULT_SHELL,
} from '../utils/shell.js';

const FOLLOW = 'follow';
const SHELL_KEY = 'shpd_shell';
const DS_SHELL_KEY = 'shpd_ds_shell';

// Per-DS prefix klíčů v dev módu — volby pro různé DS na stejném
// originu se nesmí míchat.
const storageKey = (name) => (DATA_SOURCE_ID ? `${name}:${DATA_SOURCE_ID}` : name);

// Počáteční stav z cache (před serverovým syncem): známé jméno = override,
// 'follow' sentinel / neznámá hodnota / prázdno = follow.
function loadInitialState() {
  let stored = FOLLOW;
  try {
    const s = localStorage.getItem(storageKey(SHELL_KEY));
    if (s) stored = s;
  } catch (e) {
    // private mode / disabled storage — default follow
  }
  if (KNOWN_SHELLS.includes(stored)) {
    return { follow: false, override: stored };
  }
  return { follow: true, override: DEFAULT_SHELL };
}

function loadInitialDsDefault() {
  try {
    const raw = localStorage.getItem(storageKey(DS_SHELL_KEY));
    if (raw) return raw;
  } catch (e) {
    // disabled storage — žádný známý DS default
  }
  return null;
}

const initial = loadInitialState();

let follow = $state(initial.follow);              // sleduji DS default?
let override = $state(initial.override);          // jméno shellu (platí když !follow)
let dsDefault = $state(loadInitialDsDefault());   // jméno | null — z appInfo

function cacheChoice() {
  try {
    localStorage.setItem(storageKey(SHELL_KEY), follow ? FOLLOW : override);
  } catch (e) { /* ignore */ }
}

function cacheDsDefault() {
  try {
    if (dsDefault) {
      localStorage.setItem(storageKey(DS_SHELL_KEY), dsDefault);
    } else {
      localStorage.removeItem(storageKey(DS_SHELL_KEY));
    }
  } catch (e) { /* ignore */ }
}

// Server je zdroj pravdy — selhání pushe je tiché, lokálně shell platí
// pro session a sync se dožene příštím uložením (vzor theme).
function pushToServer() {
  try {
    const payload = follow
      ? { follow: true }
      : { follow: false, shell: override, params: {} };
    pushAccountPrefs({ 'account.shell': payload });
  } catch (e) {
    // network / nepřihlášen — ignoruj
  }
}

/** ShellField: „Podle aplikace" (true) — návrat k DS defaultu. */
function setFollow(next) {
  follow = !!next;
  cacheChoice();
  pushToServer();
}

/** ShellField: volba konkrétního shellu — implikuje override (follow:false). */
function setOverride(name) {
  if (!KNOWN_SHELLS.includes(name)) return;
  follow = false;
  override = name;
  cacheChoice();
  pushToServer();
}

/** appInfo flow po GET /_app/info — DS default ({shell, params} | null). */
function setDsDefault(value) {
  dsDefault = normalizeDsShell(value);
  cacheDsDefault();
}

/**
 * accountPrefs.load() po GET stránky accountBasic — serverová hodnota
 * account.shell. Tvarovou diskriminaci vlastní store (accountPrefs je
 * jen router, vzor theme.applyFromServer). NEpíše zpět na server.
 */
function applyFromServer(value) {
  const norm = normalizeShellValue(value);
  if (!norm) return;
  follow = norm.follow;
  if (!norm.follow) {
    // Neznámé jméno se drží (efektivní shell stejně padá na sidebar
    // přes resolveShell) — po případném přidání shellu do KNOWN_SHELLS
    // začne platit bez dalšího syncu.
    override = norm.shell;
  }
  cacheChoice();
}

export const shellStore = {
  get follow()    { return follow; },
  get override()  { return override; },
  get dsDefault() { return dsDefault; },
  // Efektivní shell — vždy validní jméno (fallback 'sidebar').
  get effective() { return resolveShell(follow, override, dsDefault); },
  setFollow,
  setOverride,
  setDsDefault,
  applyFromServer,
};
