// Theme store — manages light/dark/auto color theme.
//
// Modes:
//   'light' → force light (no data-theme attribute on <html>)
//   'dark'  → force dark   (data-theme="dark")
//   'auto'  → follow OS preference via prefers-color-scheme media query
//
// Persistence: localStorage under key 'shpd_theme'. The same key is read
// by the bootstrap script in index.html (runs before first render to
// avoid flash of wrong theme). If the key changes here, change it there too.
//
// Default for new users: 'auto' — match what their OS already prefers.

const STORAGE_KEY = 'shpd_theme';
const VALID_MODES = ['light', 'dark', 'auto'];

function loadInitialMode() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (VALID_MODES.includes(stored)) return stored;
  } catch (e) {
    // private mode / disabled storage — fall through to default
  }
  return 'auto';
}

const initialMode = loadInitialMode();
let mode = $state(initialMode);

// Effective theme = what's actually rendered. For 'light'/'dark' it's
// the same as mode; for 'auto' it depends on OS preference.
function computeEffective(currentMode) {
  if (currentMode === 'dark') return 'dark';
  if (currentMode === 'light') return 'light';
  // auto
  try {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  } catch (e) {
    return 'light';
  }
}

function applyToDocument(effective) {
  if (typeof document === 'undefined') return;
  if (effective === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  } else {
    document.documentElement.removeAttribute('data-theme');
  }
}

// Apply current mode immediately on module load (in case bootstrap script
// ran with stale value or didn't run at all).
// Používáme lokální initialMode místo $state proměnné, abychom
// nezpotyčovali Svelte (state_referenced_locally warning).
applyToDocument(computeEffective(initialMode));

// Listen to OS preference changes — only relevant for 'auto' mode.
if (typeof window !== 'undefined' && window.matchMedia) {
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  const onChange = () => {
    if (mode === 'auto') applyToDocument(computeEffective(mode));
  };
  // addEventListener is preferred; older Safari uses addListener
  if (mq.addEventListener) mq.addEventListener('change', onChange);
  else if (mq.addListener) mq.addListener(onChange);
}

/**
 * Set the theme mode. Persists to localStorage and applies to <html>.
 * @param {'light' | 'dark' | 'auto'} newMode
 */
function setMode(newMode) {
  if (!VALID_MODES.includes(newMode)) return;
  mode = newMode;
  try {
    localStorage.setItem(STORAGE_KEY, newMode);
  } catch (e) {
    // private mode / disabled storage — change won't persist, but
    // the visual theme still applies for this session
  }
  applyToDocument(computeEffective(newMode));
}

export const themeStore = {
  get mode() { return mode; },
  get effective() { return computeEffective(mode); },
  setMode,
};
