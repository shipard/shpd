// Navigation store — manages open tabs and the active tab.
// Tab shape: { id, label, type, table, filter }

let tabs = $state([]);
let activeTabId = $state(null);

let activeTab = $derived(tabs.find(t => t.id === activeTabId) ?? null);

/**
 * Open a tab for the given navigation item. If a tab with the same id already
 * exists, just activate it; otherwise append and activate.
 * @param {{ id: string, label: string, type: string, table: string, filter?: object }} item
 */
function openTab(item) {
  const existing = tabs.find(t => t.id === item.id);
  if (existing) {
    activeTabId = item.id;
    return;
  }
  tabs = [...tabs, { id: item.id, label: item.label, type: item.type, table: item.table, filter: item.filter ?? null }];
  activeTabId = item.id;
}

/**
 * Close the tab with the given id. If it was the active tab, activate the
 * nearest remaining tab (previous, then next, then null).
 * @param {string} id
 */
function closeTab(id) {
  const index = tabs.findIndex(t => t.id === id);
  if (index === -1) return;

  const wasActive = activeTabId === id;
  tabs = tabs.filter(t => t.id !== id);

  if (wasActive) {
    if (tabs.length === 0) {
      activeTabId = null;
    } else {
      // Prefer the tab that was before the closed one, fall back to the next.
      const newIndex = Math.min(index, tabs.length - 1);
      activeTabId = tabs[newIndex].id;
    }
  }
}

/**
 * Activate the tab with the given id.
 * @param {string} id
 */
function activateTab(id) {
  activeTabId = id;
}

/**
 * Return the currently active tab object, or null if no tab is active.
 * @returns {{ id: string, label: string, type: string, table: string, filter: object|null }|null}
 */
function getActiveTab() {
  return activeTab;
}

export const navigationStore = {
  get tabs() { return tabs; },
  get activeTabId() { return activeTabId; },
  get activeTab() { return activeTab; },
  openTab,
  closeTab,
  activateTab,
  getActiveTab,
};
