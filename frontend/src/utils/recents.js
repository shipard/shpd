// Posledně navštívené položky navigace pro command paletu — čisté funkce
// nad localStorage, bez Svelte (testovatelné přes `node --test` s mockem).
//
// Klíč je per user (`shpd_recents_<userId>`); DS izolaci řeší doména
// (localStorage je per origin, každá DS má vlastní subdomain). Hodnota:
// pole `{id, label, icon, type, ts}`, nejnovější první, cap 7, dedup
// podle `id`. Label/icon jsou jen fallback — paleta při zobrazení
// resolvuje živý leaf ze stromu podle `id`.

const KEY_PREFIX = 'shpd_recents_';
const MAX_RECENTS = 7;

function storageKey(userId) {
  return KEY_PREFIX + userId;
}

/** Načti recents uživatele; poškozený/chybějící záznam → []. */
export function loadRecents(userId) {
  if (userId == null) return [];
  try {
    const parsed = JSON.parse(localStorage.getItem(storageKey(userId)) ?? '[]');
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((e) => e && e.id != null);
  } catch {
    return [];
  }
}

/** Zaznamenej návštěvu položky — dedup dle id (přesun nahoru), cap 7. */
export function recordRecent(userId, entry) {
  if (userId == null || entry?.id == null) return;
  const list = loadRecents(userId).filter((e) => e.id !== entry.id);
  list.unshift({
    id: entry.id,
    label: entry.label ?? null,
    icon: entry.icon ?? null,
    type: entry.type ?? null,
    ts: Date.now(),
  });
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify(list.slice(0, MAX_RECENTS)));
  } catch {
    // localStorage plný/nedostupný — recents jsou jen pohodlí, tiše přeskočit
  }
}

/** Smaž recents uživatele. */
export function clearRecents(userId) {
  if (userId == null) return;
  try {
    localStorage.removeItem(storageKey(userId));
  } catch {
    // viz recordRecent
  }
}
