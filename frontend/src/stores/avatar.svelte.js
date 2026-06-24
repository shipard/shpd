// Avatar store — fotka přihlášeného uživatele pro patičku sidebaru.
//
// Avatar GET je za auth (Bearer), takže ho nelze dát přímo do <img src> —
// prohlížeč by Authorization hlavičku nepřipojil. Místo toho fetchneme blob
// s hlavičkou a vystavíme object URL, na který se <img> naváže.
//
// Plní se po loginu / autentizovaném bootu (App.svelte onSuccess + main.js).
// Po uploadu/smazání avataru na stránce Nastavení účtu → Základní voláme
// reload(), který blob přegeneruje (nebo zahodí). Object URL revokujeme při
// každé výměně, ať netečou.
//
// Metadata avataru žijí v core_system_user_settings (account.avatar);
// tento store drží jen vyrenderovatelný object URL, ne metadata.

import { API_BASE_URL } from '../api/config.js';

const TOKEN_KEY = 'shpd_token';

let objectUrl = $state(null); // blob: URL | null
let loading   = $state(false);

function revoke() {
  if (objectUrl) {
    URL.revokeObjectURL(objectUrl);
    objectUrl = null;
  }
}

// Fetchne avatar přihlášeného uživatele jako blob a vystaví object URL.
// 404 (uživatel nemá avatar) → objectUrl zůstane null → sidebar ukáže iniciálu.
async function load() {
  const token = localStorage.getItem(TOKEN_KEY);
  if (!token) {
    revoke();
    return;
  }

  loading = true;
  try {
    const res = await fetch(`${API_BASE_URL}/_app/avatar`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.ok) {
      const blob = await res.blob();
      revoke();
      objectUrl = URL.createObjectURL(blob);
    } else {
      // 404 / 401 — žádný avatar, padni na iniciálu.
      revoke();
    }
  } catch {
    revoke();
  } finally {
    loading = false;
  }
}

// Po uploadu/smazání — přegeneruj (load si poradí i s "už není").
function reload() {
  return load();
}

// Při logoutu zahodit blob.
function clear() {
  revoke();
}

export const avatarStore = {
  get objectUrl() { return objectUrl; },
  get loading()   { return loading; },
  load,
  reload,
  clear,
};
