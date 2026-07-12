// Chybový kód pro login obrazovku z OIDC redirectu (?login_error=…) nebo
// z neúspěšného handoff exchange. Plní ho main.js při bootu (před mountem,
// URL se hned čistí přes history.replaceState), čte LoginScreen. Jen
// in-memory — reload = čistý stav.

let errorCode = $state(null);

export const loginNotice = {
  get error() { return errorCode; },
  set(code) { errorCode = code; },
  clear() { errorCode = null; },
};
