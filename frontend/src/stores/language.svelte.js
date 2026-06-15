// Language store — manages cs/en/auto language selection with i18n helpers.
//
// Modes:
//   'cs'   → force Czech
//   'en'   → force English
//   'auto' → detect from navigator.language (cs/en, fallback en)
//
// Persistence: localStorage under key 'shpd_language'. Must stay in sync
// with the bootstrap script in index.html (runs before first render so
// the server gets the right Accept-Language and <html lang> is correct).
//
// Default for new users: 'auto'.
//
// On change, setMode() persists the choice and calls location.reload() —
// see tasks/frontend-i18n-phase1a.md, "Rozhodnutí k designu". Soft refetch
// can be added later if reload becomes annoying.

import IntlMessageFormat from 'intl-messageformat';
import csMessages from '../i18n/cs.js';
import enMessages from '../i18n/en.js';
import { pushAccountPrefs } from '../api/account.js';

const STORAGE_KEY = 'shpd_language';
const VALID_MODES = ['cs', 'en', 'auto'];
const SUPPORTED_LANGS = ['cs', 'en'];
const DICTIONARIES = { cs: csMessages, en: enMessages };

function loadInitialMode() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (VALID_MODES.includes(stored)) return stored;
  } catch (e) {
    // private mode / disabled storage
  }
  return 'auto';
}

function detectAuto() {
  try {
    const nav = (navigator.language || 'en').slice(0, 2).toLowerCase();
    return SUPPORTED_LANGS.includes(nav) ? nav : 'en';
  } catch (e) {
    return 'en';
  }
}

function computeCurrent(currentMode) {
  if (currentMode === 'cs' || currentMode === 'en') return currentMode;
  return detectAuto();
}

let mode = $state(loadInitialMode());
let current = $derived(computeCurrent(mode));

// Cache for IntlMessageFormat instances — compiling an ICU string is
// non-trivial, repeated renders of the same key would otherwise be costly.
const formatterCache = new Map();

function getFormatter(key, lang) {
  const cacheKey = `${lang}::${key}`;
  if (formatterCache.has(cacheKey)) return formatterCache.get(cacheKey);

  const dict = DICTIONARIES[lang] ?? DICTIONARIES.en;
  // Fallback chain: requested lang → en → key itself (visible signal for devs).
  const message = dict[key] ?? DICTIONARIES.en[key] ?? key;
  const formatter = new IntlMessageFormat(message, lang);
  formatterCache.set(cacheKey, formatter);
  return formatter;
}

/**
 * Translate a key, optionally with parameters for ICU interpolation/plural.
 *
 * @param {string} key   Dot-notated key, e.g. 'sidebar.language' or 'viewer.recordCount'.
 * @param {object} [params]  Values for ICU placeholders, e.g. { count: 3, table: 'Faktury' }.
 * @returns {string}
 */
function t(key, params) {
  const formatter = getFormatter(key, current);
  try {
    return formatter.format(params ?? {});
  } catch (e) {
    console.warn(`i18n format error for key '${key}':`, e);
    return key;
  }
}

/**
 * Shortcut for plural keys where `count` is the dominant param.
 * Equivalent to t(key, { count, ...params }).
 */
function tn(key, count, params) {
  return t(key, { count, ...(params ?? {}) });
}

async function setMode(newMode) {
  if (!VALID_MODES.includes(newMode)) return;
  try {
    localStorage.setItem(STORAGE_KEY, newMode);
  } catch (e) {
    // private mode — choice won't persist, but reload still applies it
    // for this session via the in-memory `mode` value… except we reload
    // immediately, so this branch effectively does nothing.
  }
  // Jazyk je per-user na serveru (zdroj pravdy) — zapiš před reloadem.
  // Reload přijde hned, takže POST awaitujeme (drobné zdržení akceptovatelné);
  // selhání ignorujeme — lokální volba platí, sync se dožene příště.
  try {
    await pushAccountPrefs({ 'account.language': newMode });
  } catch (e) {
    // network / not authenticated — reload stejně
  }
  // Reload — see "Rozhodnutí k designu" in the phase 1A task.
  location.reload();
}

export const language = {
  get mode() { return mode; },
  get current() { return current; },
  setMode,
};

export { t, tn };
