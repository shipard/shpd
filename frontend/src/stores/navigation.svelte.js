// Navigation store — manages navigation mode and the active item per mode.
// Modes: 'app' | 'settings'.
// Each mode remembers its own activeItem so switching app→settings→app
// returns the user to where they were.

let mode = $state('app');
let appActiveItem      = $state(null);
let settingsActiveItem = $state(null);

function navigate(item) {
  const normalized = {
    id: item.id,
    label: item.label,
    type: item.type,
    table: item.table,
    viewerId: item.viewerId,
    filter: item.filter ?? null,
  };
  if (mode === 'settings') {
    settingsActiveItem = normalized;
  } else {
    appActiveItem = normalized;
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
  navigate,
  enterSettings,
  exitSettings,
};
