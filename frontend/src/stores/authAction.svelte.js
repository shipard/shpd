// Startup auth akce z mailového linku (?auth_action=set-password&token=…).
// Plní ji main.js při bootu (před mountem, URL se hned čistí přes
// history.replaceState — token nesmí přežít reload ani zůstat v historii),
// čte App.svelte. Jen in-memory — reload = čistý stav.

let action = $state(null);

export const authAction = {
  get kind() { return action?.kind ?? null; },
  get token() { return action?.token ?? null; },
  set(value) { action = value; },
  clear() { action = null; },
};
