// Shell-lokální stav wild shellu (D5/D7/R2) — prohlížená sekce railu
// a paměť „opravdového posledního stavu" per sekce. Module-level $state:
// přežije unmount WildShellu (settings/account mód → resolver dává
// sidebar-style), reload paměť čistí (D7). navigationStore beze změny —
// prohlížení je čistě vizuální stav shellu, klik na rail NEnaviguje.

// Prohlížená sekce railu; null = domeček (root-level leafy).
let browsingSection = $state(null);

// První mount shellu v session adoptuje aktivní navigaci (smoke 1);
// další mounty (návrat ze settings) drží paměť.
let initialized = $state(false);

// { [sectionId | '_top']: { tab: 'ai' | leafId } } — plain objekt, ne Map
// ($state deep-proxy reaktivita na přiřazení klíče).
let lastTabBySection = $state({});

// Domeček nemá section id — klíč sdílí sentinel `_top` (nekoliduje:
// sekce jsou uzly bez type, `_top` je serverový sentinel root leafů).
const KEY_HOME = '_top';
const keyFor = (section) => section ?? KEY_HOME;

function setBrowsing(section) {
  browsingSection = section ?? null;
  initialized = true;
}

function recordTab(section, tab) {
  lastTabBySection[keyFor(section)] = { tab };
}

function getLastTab(section) {
  return lastTabBySection[keyFor(section)] ?? null;
}

// Srovnání dle skutečné navigace (první mount i R3 sync po externí
// navigaci — paleta, deep link). Idempotentní.
function adoptNavigation(section, activeId) {
  setBrowsing(section);
  if (activeId != null) recordTab(section, activeId);
}

export const wildShellStore = {
  get browsingSection() { return browsingSection; },
  get initialized() { return initialized; },
  setBrowsing,
  recordTab,
  getLastTab,
  adoptNavigation,
};
