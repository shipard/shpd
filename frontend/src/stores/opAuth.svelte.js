// OIDC OP transakce z ?op_auth={txn} (session bridge D10). Plní ji main.js
// při bootu (před mountem, URL se hned čistí přes history.replaceState),
// čte App.svelte. Jen in-memory — přežije login (login je fetch, ne
// navigace), reload = čistý stav a uživatel začne na portálu.

let txn = $state(null);

export const opAuth = {
  get txn() { return txn; },
  set(value) { txn = value; },
  clear() { txn = null; },
};
