// Navigation store — manages the currently active navigation item.
// Item shape: { id, label, type, table, viewerId, filter }

let activeItem = $state(null);

/**
 * Navigate to the given item. Replaces the current view.
 * @param {{ id: string, label: string, type: string, table: string, viewerId?: string, filter?: object }} item
 */
function navigate(item) {
  activeItem = { id: item.id, label: item.label, type: item.type, table: item.table, viewerId: item.viewerId, filter: item.filter ?? null };
}

export const navigationStore = {
  get activeId() { return activeItem?.id ?? null; },
  get activeItem() { return activeItem; },
  navigate,
};
