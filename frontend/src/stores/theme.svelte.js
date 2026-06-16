// Theme store — manages the effective sidebar appearance.
//
// Two-level model (Fáze 4):
//   account.theme (per-user, server)  → follow vs. override
//   app.theme     (per-DS, server)    → DS-wide default
//
//   efektivní vzhled = follow ? (DS default ?? Shipard) : user override
//
// `follow` (default true) = sleduj DS default včetně jeho budoucích změn;
// override = vlastní volba s možností návratu k follow. Legacy account.theme
// bez follow se interpretuje jako override (follow:false).
//
// Modes (platí pro override i pro DS default config):
//   'light'  → Shipard default (no data-theme attribute, no inline tokens)
//   'dark'   → built-in dark (data-theme="dark", no inline tokens)
//   'custom' → picked sidebar color: data-theme follows custom.base,
//              sidebar tokens applied as inline custom properties on <html>
//
// Persistence: localStorage je anti-flash cache (server je zdroj pravdy).
// Per-DS key v dev módu (DS ID v URL path); v produkci izoluje origin.
// Keys (base names, suffixed with ':{dsId}' v dev):
//   shpd_theme            — 'follow' | 'light' | 'dark' | 'custom'
//                           ('follow' = sleduj DS default; jinak override mode)
//   shpd_theme_custom     — override custom config JSON
//   shpd_theme_tokens     — override derived tokens (anti-flash bootstrap)
//   shpd_ds_theme         — DS default config JSON {mode, custom} (follow cache)
//   shpd_ds_theme_tokens  — DS default derived tokens (follow anti-flash)
//
// Čtyři synchronizovaná místa: tento store, bootstrap v index.html
// (klíče + formáty, vč. shpd_ds_theme* DS cache), a api/config.js (DS regex).
// Změna kteréhokoli znamená aktualizaci komentářů u všech.

import { DATA_SOURCE_ID } from '../api/config.js';
import { deriveSidebarTokens, SIDEBAR_TOKEN_NAMES } from '../utils/themeColor.js';
import { pushAccountPrefs } from '../api/account.js';

const VALID_MODES = ['light', 'dark', 'custom'];
const FOLLOW = 'follow';

const MODE_KEY = 'shpd_theme';
const CUSTOM_KEY = 'shpd_theme_custom';
const TOKENS_KEY = 'shpd_theme_tokens';
const DS_THEME_KEY = 'shpd_ds_theme';
const DS_TOKENS_KEY = 'shpd_ds_theme_tokens';

// Per-DS prefix klíčů v dev módu — volby pro různé DS na stejném
// originu se nesmí míchat.
const storageKey = (name) => (DATA_SOURCE_ID ? `${name}:${DATA_SOURCE_ID}` : name);

// Default custom config = Shipard modrá na světlé bázi, takže panel
// startuje vizuálně z defaultu. Formát je sdílený se serverovou persistencí
// (user override i DS default). `sidebar` je buď {type:'solid', color} nebo
// {type:'gradient', stops:[a,b]}; `opacity` (0–100) je top-level záměrně —
// výměna sidebar objektu při přepnutí solid ↔ gradient ji nesmí přepsat.
const DEFAULT_CUSTOM = {
  version: 1,
  base: 'light',
  opacity: 100,
  sidebar: { type: 'solid', color: '#00345C' },
};

const deriveTokensFor = (c) => deriveSidebarTokens(c.sidebar, c.base, c.opacity ?? 100);

// Normalizace custom configu: configy z Fáze 1 nemají opacity → 100.
// Nevalidní/chybějící → DEFAULT_CUSTOM.
function normalizeCustom(custom) {
  if (custom && (custom.sidebar?.color || custom.sidebar?.stops)) {
    return {
      ...custom,
      opacity: typeof custom.opacity === 'number' ? custom.opacity : 100,
    };
  }
  return DEFAULT_CUSTOM;
}

// Serverový/cache DS default → {mode, custom} | null.
function normalizeDsDefault(cfg) {
  if (cfg && VALID_MODES.includes(cfg.mode)) {
    return {
      mode: cfg.mode,
      custom: cfg.mode === 'custom' ? normalizeCustom(cfg.custom) : null,
    };
  }
  return null;
}

// Počáteční stav z localStorage cache (před serverovým syncem).
// Vrací { follow, mode }: explicitní override mode → override, jinak follow.
function loadInitialState() {
  let stored = FOLLOW;
  try {
    const s = localStorage.getItem(storageKey(MODE_KEY));
    if (s) stored = s;
  } catch (e) {
    // private mode / disabled storage — default follow
  }
  // Migrace zaniklého 'auto' režimu → follow (sleduj DS default) s write-backem.
  if (stored === 'auto') {
    try { localStorage.setItem(storageKey(MODE_KEY), FOLLOW); } catch (e) { /* ignore */ }
    stored = FOLLOW;
  }
  if (VALID_MODES.includes(stored)) {
    return { follow: false, mode: stored };
  }
  // 'follow' sentinel nebo neznámá hodnota → sleduj DS default.
  return { follow: true, mode: 'light' };
}

function loadInitialCustom() {
  try {
    const raw = localStorage.getItem(storageKey(CUSTOM_KEY));
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && (parsed.sidebar?.color || parsed.sidebar?.stops)) {
        return normalizeCustom(parsed);
      }
    }
  } catch (e) {
    // chybný JSON / disabled storage — default
  }
  return DEFAULT_CUSTOM;
}

function loadInitialDsDefault() {
  try {
    const raw = localStorage.getItem(storageKey(DS_THEME_KEY));
    if (raw) return normalizeDsDefault(JSON.parse(raw));
  } catch (e) {
    // chybný JSON / disabled storage — žádný známý DS default
  }
  return null;
}

const initial = loadInitialState();
const initialCustom = loadInitialCustom();
const initialDsDefault = loadInitialDsDefault();

let follow = $state(initial.follow);          // sleduji DS default?
let mode = $state(initial.mode);              // override mode (platí když !follow)
let customConfig = $state(initialCustom);     // override custom config
let dsDefault = $state(initialDsDefault);     // {mode, custom} | null — z appInfo

function setDarkAttribute(dark) {
  if (dark) {
    document.documentElement.setAttribute('data-theme', 'dark');
  } else {
    document.documentElement.removeAttribute('data-theme');
  }
}

function clearInlineTokens() {
  for (const name of SIDEBAR_TOKEN_NAMES) {
    document.documentElement.style.removeProperty(name);
  }
}

// Čistá aplikace módu + custom configu na <html> — žádná persistence.
// (Cache se řeší zvlášť v persist* funkcích, aby follow/override nepřepisovaly
// cizí cache klíče.)
function applyTheme(currentMode, currentCustom) {
  if (typeof document === 'undefined') return;

  if (currentMode === 'custom') {
    setDarkAttribute(currentCustom.base === 'dark');
    // Clear-then-set: derivace nemusí vrátit všechny tokeny (bg-image
    // má jen gradient) — přepnutí gradient → solid by jinak nechalo
    // viset starý inline --shpd-sidebar-bg-image.
    clearInlineTokens();
    const tokens = deriveTokensFor(currentCustom);
    for (const [name, value] of Object.entries(tokens)) {
      document.documentElement.style.setProperty(name, value);
    }
    return;
  }

  // Built-in témata: žádné inline tokeny.
  setDarkAttribute(currentMode === 'dark');
  clearInlineTokens();
}

// Efektivní konfigurace = follow ? DS default (?? Shipard) : override.
function effectiveConfig() {
  if (follow) {
    if (dsDefault) {
      return { mode: dsDefault.mode, custom: dsDefault.custom ?? DEFAULT_CUSTOM };
    }
    return { mode: 'light', custom: DEFAULT_CUSTOM };
  }
  return { mode, custom: customConfig };
}

// Zapíše DS default cache (shpd_ds_theme + tokeny) — pro anti-flash bootstrap
// follow-uživatele. Nepíše MODE_KEY (ten řeší follow/override stav).
function cacheDsDefault() {
  try {
    if (dsDefault) {
      localStorage.setItem(storageKey(DS_THEME_KEY), JSON.stringify(dsDefault));
      if (dsDefault.mode === 'custom') {
        localStorage.setItem(storageKey(DS_TOKENS_KEY), JSON.stringify(deriveTokensFor(dsDefault.custom)));
      } else {
        localStorage.removeItem(storageKey(DS_TOKENS_KEY));
      }
    } else {
      localStorage.removeItem(storageKey(DS_THEME_KEY));
      localStorage.removeItem(storageKey(DS_TOKENS_KEY));
    }
  } catch (e) { /* persistence selhala — vizuál pro session platí */ }
}

// Aplikuje efektivní vzhled + synchronizuje localStorage cache podle
// follow/override stavu. Jediné místo, které píše MODE_KEY.
function applyEffective() {
  const eff = effectiveConfig();
  applyTheme(eff.mode, eff.custom);

  try {
    if (follow) {
      localStorage.setItem(storageKey(MODE_KEY), FOLLOW);
    } else {
      localStorage.setItem(storageKey(MODE_KEY), mode);
      localStorage.setItem(storageKey(CUSTOM_KEY), JSON.stringify(customConfig));
      if (mode === 'custom') {
        localStorage.setItem(storageKey(TOKENS_KEY), JSON.stringify(deriveTokensFor(customConfig)));
      } else {
        localStorage.removeItem(storageKey(TOKENS_KEY));
      }
    }
  } catch (e) { /* persistence selhala — vizuál pro session platí */ }

  // DS cache píšeme vždy, když následujeme — bootstrap z ní čte při 'follow'.
  if (follow) cacheDsDefault();
}

// Apply current state immediately on module load (bootstrap mohl běžet se
// stale hodnotou nebo vůbec). Lokální initial* hodnoty místo $state proměnných
// — viz state_referenced_locally poznámka v docs/frontend.md.
(function applyInitial() {
  const eff = initial.follow
    ? (initialDsDefault
        ? { mode: initialDsDefault.mode, custom: initialDsDefault.custom ?? DEFAULT_CUSTOM }
        : { mode: 'light', custom: DEFAULT_CUSTOM })
    : { mode: initial.mode, custom: initialCustom };
  applyTheme(eff.mode, eff.custom);
})();

// Server zápis — account.theme je per-user na serveru (zdroj pravdy).
// follow → {follow:true}; override → {follow:false, mode, custom}.
// Selhání je tiché (lokál platí pro session).
function pushToServer() {
  try {
    const payload = follow
      ? { follow: true }
      : { follow: false, mode, custom: customConfig };
    pushAccountPrefs({ 'account.theme': payload });
  } catch (e) {
    // network / not authenticated — sync se dožene příště
  }
}

// Debounce pro setCustom — color picker pálí oninput, nechceme POST na
// každý pixel.
let pushTimer = null;
function pushToServerDebounced() {
  if (pushTimer) clearTimeout(pushTimer);
  pushTimer = setTimeout(() => { pushTimer = null; pushToServer(); }, 300);
}

// Je override stále na výchozí (Shipard) hodnotě, tj. uživatel si nic
// nezvolil? Pak má smysl při prvním přepnutí na override předvyplnit
// zděděnou DS hodnotou („začni od toho, co vidíš").
function overrideIsPristine() {
  return mode === 'light'
    && customConfig.base === DEFAULT_CUSTOM.base
    && customConfig.sidebar?.type === 'solid'
    && (customConfig.sidebar?.color ?? '').toLowerCase() === DEFAULT_CUSTOM.sidebar.color.toLowerCase()
    && (customConfig.opacity ?? 100) === DEFAULT_CUSTOM.opacity;
}

/**
 * Aplikuje account.theme ze serveru (zdroj pravdy). Rozpozná follow tvar:
 *   {follow:true}                → sleduj DS default
 *   {follow:false, mode, custom} → override
 *   {mode, custom} (legacy)      → override
 * NEpíše zpět na server.
 * @param {object} value
 */
function applyFromServer(value) {
  if (!value) return;
  if (value.follow === true) {
    follow = true;
  } else {
    follow = false;
    if (VALID_MODES.includes(value.mode)) mode = value.mode;
    customConfig = normalizeCustom(value.custom);
  }
  applyEffective();
}

/**
 * Nastaví DS-wide default vzhledu (z appInfo). Push směr appInfo → theme,
 * aby theme store neimportoval appInfo (žádný kruhový import). Pokud
 * následujeme, re-aplikuje efektivní vzhled (DS default se mohl změnit).
 * @param {object|null} cfg — {mode, custom} | null
 */
function setDsDefault(cfg) {
  dsDefault = normalizeDsDefault(cfg);
  if (follow) {
    applyEffective();        // re-apply + cacheDsDefault uvnitř
  } else {
    cacheDsDefault();        // jen obnov cache pro pozdější follow / bootstrap
  }
}

/**
 * Přepne mezi follow (sleduj DS default) a override (vlastní volba).
 * @param {boolean} next — true = follow, false = override
 */
function setFollow(next) {
  follow = !!next;
  if (!follow && overrideIsPristine()) {
    // První zapnutí vlastního vzhledu — předvyplň zděděnou DS hodnotou.
    const seed = dsDefault ?? { mode: 'light', custom: DEFAULT_CUSTOM };
    mode = seed.mode;
    customConfig = seed.custom ?? DEFAULT_CUSTOM;
  }
  applyEffective();
  pushToServer();
}

/**
 * Set the override theme mode. Implies override (follow=false) — uživatel
 * aktivně volí. Persists to localStorage, applies and syncs to server.
 * @param {'light' | 'dark' | 'custom'} newMode
 */
function setMode(newMode) {
  if (!VALID_MODES.includes(newMode)) return;
  follow = false;
  mode = newMode;
  applyEffective();
  pushToServer();
}

/**
 * Merge into the override custom config, persist and apply. Implies override
 * mode 'custom'.
 * @param {object} patch — partial config, e.g. {sidebar: {type:'solid', color:'#6D1F2C'}}
 */
function setCustom(patch) {
  follow = false;
  customConfig = { ...customConfig, ...patch };
  if (mode !== 'custom') mode = 'custom';
  applyEffective();
  pushToServerDebounced();
}

export const themeStore = {
  get mode() { return mode; },
  get custom() { return customConfig; },
  get follow() { return follow; },
  get dsDefault() { return dsDefault; },
  setMode,
  setCustom,
  setFollow,
  setDsDefault,
  applyFromServer,
};
