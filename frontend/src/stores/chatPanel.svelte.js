// Stav bočního AI chat panelu (mountovaný v AppShellu, otevíraný
// z dashboardového ChatLauncheru). Drží jen otevřenost — obsah
// (konverzace) žije v chatStore.

let isOpen = $state(false);

export const chatPanelStore = {
  get isOpen() { return isOpen; },
  open()  { isOpen = true; },
  close() { isOpen = false; },
};
