// Theme store — manages light/dark/custom appearance.
//
// Modes:
//   'light'  → Shipard default (no data-theme attribute, no inline tokens)
//   'dark'   → built-in dark (data-theme="dark", no inline tokens)
//   'custom' → user-picked sidebar color: data-theme follows custom.base,
//              sidebar tokens applied as inline custom properties on <html>
//
// Legacy 'auto' mode (follow OS preference) was removed — loadInitialMode()
// migrates a stored 'auto' to 'light' with an immediate write-back.
//
// Persistence: localStorage, per-DS key in dev mode (DS ID in URL path);
// in production each DS lives on its own origin so isolation is automatic.
// Keys (base names, suffixed with ':{dsId}' in dev):
//   shpd_theme        — mode string
//   shpd_theme_custom — JSON custom config {version, base, sidebar:{type,color}}
//   shpd_theme_tokens — JSON cache of derived tokens for the anti-flash
//                       bootstrap in index.html (no OKLCH math there)
//
// Tři synchronizovaná místa: tento store, bootstrap v index.html
// (klíče + formáty) a api/config.js (DS regex). Změna kteréhokoli
// znamená aktualizaci komentářů u všech.

import { DATA_SOURCE_ID } from '../api/config.js';
import { deriveSidebarTokens, SIDEBAR_TOKEN_NAMES } from '../utils/themeColor.js';

const VALID_MODES = ['light', 'dark', 'custom'];

const MODE_KEY = 'shpd_theme';
const CUSTOM_KEY = 'shpd_theme_custom';
const TOKENS_KEY = 'shpd_theme_tokens';

// Per-DS prefix klíčů v dev módu — volby pro různé DS na stejném
// originu se nesmí míchat.
const storageKey = (name) => (DATA_SOURCE_ID ? `${name}:${DATA_SOURCE_ID}` : name);

// Default custom config = Shipard modrá na světlé bázi, takže panel
// startuje vizuálně z defaultu. Formát je sdílený s budoucími úrovněmi
// persistence (server per-user, DS default) i Fází 2 (gradient, opacity).
const DEFAULT_CUSTOM = {
  version: 1,
  base: 'light',
  sidebar: { type: 'solid', color: '#00345C' },
};

function loadInitialMode() {
  try {
    const stored = localStorage.getItem(storageKey(MODE_KEY));
    if (VALID_MODES.includes(stored)) return stored;
    // Migrace zaniklého 'auto' režimu → 'light' s write-backem.
    if (stored === 'auto') {
      localStorage.setItem(storageKey(MODE_KEY), 'light');
      return 'light';
    }
  } catch (e) {
    // private mode / disabled storage — fall through to default
  }
  return 'light';
}

function loadInitialCustom() {
  try {
    const raw = localStorage.getItem(storageKey(CUSTOM_KEY));
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && parsed.sidebar?.color) return parsed;
    }
  } catch (e) {
    // chybný JSON / disabled storage — default
  }
  return DEFAULT_CUSTOM;
}

const initialMode = loadInitialMode();
const initialCustom = loadInitialCustom();

let mode = $state(initialMode);
let customConfig = $state(initialCustom);

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

// Aplikuje aktuální mód + custom config na <html> a synchronizuje
// token cache pro anti-flash bootstrap.
function applyTheme(currentMode, currentCustom) {
  if (typeof document === 'undefined') return;

  if (currentMode === 'custom') {
    setDarkAttribute(currentCustom.base === 'dark');
    const tokens = deriveSidebarTokens(currentCustom.sidebar.color);
    for (const [name, value] of Object.entries(tokens)) {
      document.documentElement.style.setProperty(name, value);
    }
    try {
      localStorage.setItem(storageKey(TOKENS_KEY), JSON.stringify(tokens));
    } catch (e) { /* persistence selhala — vizuál pro session platí */ }
    return;
  }

  // Built-in témata: žádné inline tokeny, cache pryč.
  setDarkAttribute(currentMode === 'dark');
  clearInlineTokens();
  try {
    localStorage.removeItem(storageKey(TOKENS_KEY));
  } catch (e) { /* ignore */ }
}

// Apply current mode immediately on module load (in case bootstrap script
// ran with stale value or didn't run at all). Lokální initialMode/Custom
// místo $state proměnných — viz state_referenced_locally poznámka
// v docs/frontend.md.
applyTheme(initialMode, initialCustom);

/**
 * Set the theme mode. Persists to localStorage and applies to <html>.
 * @param {'light' | 'dark' | 'custom'} newMode
 */
function setMode(newMode) {
  if (!VALID_MODES.includes(newMode)) return;
  mode = newMode;
  try {
    localStorage.setItem(storageKey(MODE_KEY), newMode);
  } catch (e) {
    // private mode / disabled storage — change won't persist, but
    // the visual theme still applies for this session
  }
  applyTheme(newMode, customConfig);
}

/**
 * Merge into the custom config, persist and apply. Implies mode 'custom'.
 * @param {object} patch — partial config, e.g. {sidebar: {type:'solid', color:'#6D1F2C'}}
 */
function setCustom(patch) {
  customConfig = { ...customConfig, ...patch };
  try {
    localStorage.setItem(storageKey(CUSTOM_KEY), JSON.stringify(customConfig));
  } catch (e) { /* persistence selhala — vizuál pro session platí */ }
  if (mode !== 'custom') {
    setMode('custom');
  } else {
    applyTheme(mode, customConfig);
  }
}

export const themeStore = {
  get mode() { return mode; },
  get custom() { return customConfig; },
  setMode,
  setCustom,
};
