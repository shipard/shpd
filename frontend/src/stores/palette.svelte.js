// Command palette store — stav overlaye + zdroje nabídky (provider
// architektura). Zdroj („provider") = jedna položka SOURCE_DEFS: dodává
// položky se svou skupinou, `results` je skládá. Přidání dalšího zdroje
// (nápověda, fulltext záznamů — v2) = nový záznam / nová větev v results,
// ne přepis.
//
// D2: tři navigační stromy (app / settings / account) se stahují lazy při
// prvním otevření palety a cachují po dobu session; app strom přednostně
// z navigationStore.appNavTree (plní ho Sidebar). D3: prázdný vstup nabízí
// recents (jen app mód) — zobrazení resolvuje živý leaf ze stromu podle id,
// záznam bez živého leafu se přeskočí (položka zmizela z navigace).

import { get } from '../api/client.js';
import { navigationStore } from './navigation.svelte.js';
import { authStore } from './auth.svelte.js';
import { flattenLeaves } from '../utils/navTree.js';
import { rankResults } from '../utils/paletteMatch.js';
import { loadRecents } from '../utils/recents.js';

// Provider definice per navigační mód. `groupKey` je i18n klíč nadpisu
// skupiny ve výsledcích.
const SOURCE_DEFS = [
  { mode: 'app',      groupKey: 'palette.group.app',      url: '/_ui/navigation' },
  { mode: 'settings', groupKey: 'palette.group.settings', url: '/_ui/settings/navigation' },
  { mode: 'account',  groupKey: 'palette.group.account',  url: '/_ui/account/navigation' },
];

const RESULTS_PER_GROUP = 10; // R8: žádná virtualizace, jen limit

let open = $state(false);
let query = $state('');
let activeIndex = $state(0);
let loading = $state(false);
// null = ještě nenačteno; pak {app|settings|account: {items, error}}.
// Položka (R5): {key: "<mode>:<id>", mode, id, label, icon, type,
// groupLabel, leaf} — `leaf` jde beze změny do navigationStore.navigate().
let sources = $state(null);
// Snapshot recents při otevření (localStorage není reaktivní; navigace
// paletu zavírá, takže stačí načíst jednou per otevření).
let recents = $state([]);

function toItems(tree, mode) {
  return flattenLeaves(tree ?? [], { withGroupLabel: true })
    .filter((leaf) => leaf.id != null)
    .map((leaf) => ({
      key: mode + ':' + leaf.id,
      mode,
      id: leaf.id,
      label: leaf.label ?? String(leaf.id),
      icon: leaf.icon ?? null,
      type: leaf.type,
      groupLabel: leaf.groupLabel ?? null,
      leaf,
    }));
}

async function fetchTree(url) {
  try {
    const res = await get(url);
    if (res?.success && Array.isArray(res.data)) {
      return { tree: res.data, error: false };
    }
    return { tree: null, error: true };
  } catch {
    return { tree: null, error: true };
  }
}

async function ensureSources() {
  if (sources || loading) return;
  loading = true;
  try {
    const loaded = {};
    await Promise.all(SOURCE_DEFS.map(async (def) => {
      // App strom už typicky drží navigationStore (Sidebar ho načetl) —
      // fetch jen jako fallback před prvním loadem navigace.
      if (def.mode === 'app' && Array.isArray(navigationStore.appNavTree)) {
        loaded.app = { items: toItems(navigationStore.appNavTree, 'app'), error: false };
        return;
      }
      const { tree, error } = await fetchTree(def.url);
      loaded[def.mode] = { items: error ? [] : toItems(tree, def.mode), error };
    }));
    sources = loaded;
  } finally {
    loading = false;
  }
}

function openPalette() {
  query = '';
  activeIndex = 0;
  recents = loadRecents(authStore.user?.id);
  open = true;
  ensureSources();
}

function closePalette() {
  open = false;
}

function setQuery(value) {
  query = value;
  activeIndex = 0;
}

// Skupiny výsledků: [{key, groupKey, items, error}]. Selhaný zdroj se do
// výsledků propíše jako skupina s error=true (řádek s chybou, ne toast) —
// paleta zůstává použitelná pro ostatní zdroje.
const results = $derived.by(() => {
  if (!sources) return [];
  const groups = [];
  const q = query.trim();

  if (q === '') {
    const byId = new Map((sources.app?.items ?? []).map((i) => [i.id, i]));
    const items = recents.map((r) => byId.get(r.id)).filter(Boolean).slice(0, RESULTS_PER_GROUP);
    if (items.length) {
      groups.push({ key: 'recent', groupKey: 'palette.group.recent', items, error: false });
    }
  } else {
    const recentIds = recents.map((r) => r.id);
    for (const def of SOURCE_DEFS) {
      const src = sources[def.mode];
      if (!src || src.error) continue;
      const items = rankResults(
        src.items, q,
        def.mode === 'app' ? recentIds : [],
        RESULTS_PER_GROUP,
      );
      if (items.length) {
        groups.push({ key: def.mode, groupKey: def.groupKey, items, error: false });
      }
    }
  }

  // Chybové řádky selhaných zdrojů — vždy viditelné, ať je jasné, proč
  // položky daného módu chybí.
  for (const def of SOURCE_DEFS) {
    if (sources[def.mode]?.error) {
      groups.push({ key: def.mode + ':error', groupKey: def.groupKey, items: [], error: true });
    }
  }
  return groups;
});

// Plochý seznam navigovatelných položek (bez chybových skupin) — šipky,
// Enter a aria-activedescendant pracují nad ním.
const flatItems = $derived(results.flatMap((g) => g.items));

function moveActive(delta) {
  const count = flatItems.length;
  if (count === 0) return;
  activeIndex = (activeIndex + delta + count) % count;
}

function setActive(index) {
  activeIndex = index;
}

function confirmActive() {
  const item = flatItems[Math.min(activeIndex, flatItems.length - 1)];
  if (!item) return;
  confirm(item);
}

// R6: cílový mód ≠ aktuální → přepnout mód, pak navigate s originálním
// objektem leafu ze stromu. Recents zaznamenává navigate() sám (R4, jen
// app mód) — tady se nic neukládá.
function confirm(item) {
  if (item.mode !== navigationStore.mode) {
    if (item.mode === 'settings') navigationStore.enterSettings();
    else if (item.mode === 'account') navigationStore.enterAccount();
    else navigationStore.exitToApp();
  }
  navigationStore.navigate(item.leaf);
  closePalette();
}

export const paletteStore = {
  get open()        { return open; },
  get query()       { return query; },
  get activeIndex() { return Math.min(activeIndex, Math.max(0, flatItems.length - 1)); },
  get loading()     { return loading; },
  get results()     { return results; },
  get flatItems()   { return flatItems; },
  openPalette,
  closePalette,
  setQuery,
  moveActive,
  setActive,
  confirmActive,
};
