// Section badges store — badge stavů sekcí v sidebaru (UI shells Fáze 3).
//
// Drží mapu `{ "<sectionId>": { count, severity } }` z GET /_ui/section-badges
// a obnovuje ji pollingem à 60 s + při focusu okna. Polling startuje/stopuje
// AppShell ($effect s cleanup). Chyba fetche i 401 ponechají poslední známý
// stav — tichá degradace (vzor AI shrnutí), badge není kritická informace.

import { fetchSectionBadges } from '../api/sectionBadges.js';

const POLL_INTERVAL_MS = 60_000;

let badges = $state({});

let intervalId = null;
let onFocus = null;

async function refresh() {
  try {
    const sections = await fetchSectionBadges();
    if (sections !== null) badges = sections;
  } catch {
    // Network error — poslední známý stav zůstává.
  }
}

function tick() {
  // Skrytá karta prohlížeče se neobnovuje — refresh dožene focus listener.
  if (document.hidden) return;
  refresh();
}

function startPolling() {
  if (intervalId !== null) return; // idempotentní
  refresh();
  intervalId = setInterval(tick, POLL_INTERVAL_MS);
  onFocus = () => refresh();
  window.addEventListener('focus', onFocus);
}

function stopPolling() {
  if (intervalId !== null) {
    clearInterval(intervalId);
    intervalId = null;
  }
  if (onFocus) {
    window.removeEventListener('focus', onFocus);
    onFocus = null;
  }
}

export const sectionBadgesStore = {
  get badges() { return badges; },
  refresh,
  startPolling,
  stopPolling,
};
