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
  initLayout,
  openDrawer,
  closeDrawer,
  toggleDrawer,
};
