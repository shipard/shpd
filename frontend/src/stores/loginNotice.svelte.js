// Zpráva pro login obrazovku: chybový kód z OIDC redirectu (?login_error=…),
// z neúspěšného handoff exchange, nebo úspěšná hláška (nastavené heslo ze
// SetPasswordScreen). Plní ho main.js při bootu (před mountem, URL se hned
// čistí přes history.replaceState) nebo auth obrazovky, čte LoginScreen.
// Jen in-memory — reload = čistý stav.

let notice = $state(null);

export const loginNotice = {
  get error() { return notice?.type === 'error' ? notice.code : null; },
  get code() { return notice?.code ?? null; },
  get type() { return notice?.type ?? null; },
  set(code, type = 'error') { notice = { code, type }; },
  clear() { notice = null; },
};
