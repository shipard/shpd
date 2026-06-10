// Navigation store — manages navigation mode and the active item per mode.
// Modes: 'app' | 'settings'.
// Each mode remembers its own activeItem so switching app→settings→app
// returns the user to where they were.
//
// `pendingRecordId` je jednorázový hint pro Viewer.svelte: když dashboard
// widget zavolá navigateToViewer(viewerId, recordId), Viewer si ho po mountu
// vyzvedne a předvybere ten záznam. State se konzumuje (resetuje) ve Vieweru,
// ne ve storu — viewer ji vyčistí po prvním použití, aby se neaplikovala
// znovu při následujících tab switchích na stejný viewer.

let mode = $state('app');
let appActiveItem      = $state(null);
let settingsActiveItem = $state(null);
let pendingRecordId    = $state(null);

const DASHBOARD_ITEM = Object.freeze({
  id: 'dashboard',
  label: 'Dashboard',
  type: 'dashboard',
  table: null,
  viewerId: null,
  filter: null,
});

function navigate(item) {
  const normalized = {
    id: item.id,
    label: item.label,
    type: item.type,
    table: item.table,
    viewerId: item.viewerId,
    pageId: item.pageId ?? null,
    filter: item.filter ?? null,
  };
  // Manuální navigace mimo dashboard widget — pending record vyprší.
  pendingRecordId = null;
  if (mode === 'settings') {
    settingsActiveItem = normalized;
  } else {
    appActiveItem = normalized;
  }
}

/**
 * Naviguj na konkrétní viewer a (volitelně) předvyber záznam v něm.
 * Volá se z dashboard widget rows.
 *
 * Pokud viewerId neodpovídá žádné položce sidebar navigation tree,
 * activeItem se přesto nastaví — Viewer si table načte sám podle viewerId.
 * `recordId` se uloží do pendingRecordId, Viewer.svelte ho po mountu
 * vyzvedne a předvybere řádek.
 */
function navigateToViewer(viewerId, recordId = null) {
  pendingRecordId = recordId;
  const item = {
    id: 'viewer:' + viewerId,
    label: viewerId,
    type: 'viewer',
    table: null,
    viewerId,
    filter: null,
  };
  if (mode === 'settings') {
    settingsActiveItem = item;
  } else {
    appActiveItem = item;
  }
}

/**
 * Viewer.svelte po mountu vyzvedne pendingRecordId, pokud existuje,
 * a vynuluje ho — aby další navigace bez recordId nedostala stale hodnotu.
 */
function consumePendingRecordId() {
  const id = pendingRecordId;
  pendingRecordId = null;
  return id;
}

/**
 * Nastav Dashboard jako výchozí activeItem v app módu — voláno
 * po initializaci, pokud zatím není nic vybraného. Settings mód
 * tím neovlivňujeme.
 */
function ensureDefaultActiveItem() {
  if (mode === 'app' && appActiveItem === null) {
    appActiveItem = { ...DASHBOARD_ITEM };
  }
}

function enterSettings() {
  mode = 'settings';
}

function exitSettings() {
  mode = 'app';
}

export const navigationStore = {
  get mode()       { return mode; },
  get activeItem() { return mode === 'settings' ? settingsActiveItem : appActiveItem; },
  get activeId()   { const it = mode === 'settings' ? settingsActiveItem : appActiveItem; return it?.id ?? null; },
  get pendingRecordId() { return pendingRecordId; },
  navigate,
  navigateToViewer,
  consumePendingRecordId,
  ensureDefaultActiveItem,
  enterSettings,
  exitSettings,
};
