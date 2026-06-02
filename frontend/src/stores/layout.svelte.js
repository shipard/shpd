// Layout store — řídí mobilní vs. desktopový režim a stav drawera.
//
// `isMobile` se odvozuje z window.matchMedia. Breakpoint 768px musí
// LADIT s literálem v @media queries napříč komponentami (AppShell,
// MobileTopBar). Stejný vzor jako theme/language bootstrap ↔ store:
// jedna pravda na dvou místech, drž je v synchronu.
//
// `drawerOpen` je stav mobilního drawer sidebaru. Na desktopu se
// nepoužívá (drawer neexistuje, sidebar je pevný sloupec).

const MOBILE_BREAKPOINT = 768; // px — musí ladit s @media v komponentách

let isMobile   = $state(false);
let drawerOpen = $state(false);

// --- Top bar obsah (publikovaný aktuální obrazovkou, čte MobileTopBar) ---
//
// Obrazovka (typicky Viewer) zapíše, co má top bar zobrazit: kontext
// (list/detail → ovlivní levou ikonu), akce (pole {id, label, icon,
// variant, onClick}), titul override a back handler. MobileTopBar to
// čte a renderuje, sám nic neví o vieweru.
//
// `null` kontext = obrazovka nic nepublikuje → MobileTopBar fallback
// na hamburger + titul z navigace + prázdný slot (dashboard apod.).

let topBarContext = $state(null);  // 'list' | 'detail' | null
let topBarActions = $state([]);    // [{ id, label, icon, variant, onClick }]
let topBarTitle   = $state(null);  // string | null (override; null = z navigace)
let topBarBack    = $state(null);  // (() => void) | null

function setTopBar({ context = null, actions = [], title = null, back = null }) {
  topBarContext = context;
  topBarActions = actions;
  topBarTitle   = title;
  topBarBack    = back;
}

function clearTopBar() {
  topBarContext = null;
  topBarActions = [];
  topBarTitle   = null;
  topBarBack    = null;
}

// Inicializace matchMedia listeneru. Voláno jednou z main.js po mountu.
// Defenzivní — matchMedia nemusí existovat v SSR/test prostředí.
function initLayout() {
  if (typeof window === 'undefined' || !window.matchMedia) return;

  const mq = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`);

  // Počáteční hodnota
  isMobile = mq.matches;

  // Reaktivní update při změně viewportu (resize, rotace zařízení)
  const onChange = (e) => {
    isMobile = e.matches;
    // Když se přepne na desktop, drawer zavři — na desktopu nemá smysl
    // a zůstal by viset jako stav.
    if (!e.matches) drawerOpen = false;
  };

  // addEventListener('change') je moderní API; addListener je legacy
  // fallback pro starší Safari. Cílíme moderní prohlížeče, ale levné.
  if (mq.addEventListener) {
    mq.addEventListener('change', onChange);
  } else if (mq.addListener) {
    mq.addListener(onChange);
  }
}

function openDrawer()   { drawerOpen = true; }
function closeDrawer()  { drawerOpen = false; }
function toggleDrawer() { drawerOpen = !drawerOpen; }

export const layoutStore = {
  get isMobile()   { return isMobile; },
  get drawerOpen() { return drawerOpen; },
  get topBarContext() { return topBarContext; },
  get topBarActions() { return topBarActions; },
  get topBarTitle()   { return topBarTitle; },
  get topBarBack()    { return topBarBack; },
  initLayout,
  openDrawer,
  closeDrawer,
  toggleDrawer,
  setTopBar,
  clearTopBar,
};
