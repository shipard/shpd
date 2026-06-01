# Task: Mobilní app chrome — drawer sidebar + top bar (fáze 1)

## Status / Cíl

První kus responzivního designu pro telefon (~380px). Na mobilu (viewport
≤ 768px) se aplikační shell přepne do mobilního režimu:

- **Sidebar** vystoupí z toku layoutu a stane se z něj **drawer** — vysune
  se zleva přes obsah (overlay ztmaví zbytek). Recykluje stávající
  `Sidebar.svelte`, jen se jinak umístí.
- **Nový `MobileTopBar`** se objeví nahoře — hamburger (otevře drawer),
  titul aktuální obrazovky uprostřed, vpravo prázdný slot pro budoucí akce
  (Přidat / Otevřít).
- **Drawer se zavírá** klikem na overlay (mimo drawer), tlačítkem ✕
  v hlavičce drawera, klávesou Esc, a kliknutím na položku navigace.

Na desktopu (> 768px) zůstává vše **beze změny** — sidebar je pevný
sloupec se sbalovací logikou, žádný top bar.

Detekce mobilu řeší nový malý layout store (`layout.svelte.js`) přes
`window.matchMedia`. To je jediný nový JS stav — strategie „CSS na vzhled,
JS store jen tam, kde potřebujeme přepínat chování". Drawer overlay vs.
pevný sloupec je přepnutí chování, ne jen stylu, proto store.

## Návaznost

- `frontend-phase3-app-sidebar.md` — dynamická navigace ze serveru
  (`/_ui/navigation`), nad kterou drawer staví.
- `sidebar-collapsed-icons.md` — sbalovací logika sidebaru (`collapsed`,
  ploché ikony). Na mobilu se `collapsed` **nepoužije** — drawer je buď
  otevřený, nebo zavřený. Desktop sbalování zůstává nedotčené.
- Dokumentace: `docs/frontend.md` sekce **4. Aplikační shell** popisuje
  layout (sidebar + content, bez horní lišty). Po implementaci sekci
  rozšířit o mobilní režim. `docs/design-system.md` sekce **8. Layout
  konvence** podobně.

## Scope

### V rozsahu

- Nový store `frontend/src/stores/layout.svelte.js` — `isMobile`
  (přes `matchMedia('(max-width: 768px)')` + listener), `drawerOpen`,
  metody `openDrawer()` / `closeDrawer()` / `toggleDrawer()`.
- Nová komponenta `frontend/src/components/layout/MobileTopBar.svelte` —
  hamburger + titul + prázdný slot pro akce. Jen mobil.
- Úprava `AppShell.svelte` — na mobilu renderovat `MobileTopBar` nahoře
  a sidebar jako drawer (overlay + posuvný panel); na desktopu beze změny.
- Úprava `Sidebar.svelte` — na mobilu: skrýt toggle tlačítko (collapse
  nedává smysl), přidat ✕ tlačítko do hlavičky (zavře drawer), klik na
  položku navigace zavře drawer. Desktop chování nedotčené.
- Nové i18n klíče: `app.menu.open`, `app.menu.close` (aria-labely
  hamburgeru a ✕).
- Breakpoint 768px jako JS konstanta ve storu + literál v media queries,
  s komentářem na obou místech, že musí ladit (stejný vzor jako
  `shpd_theme` bootstrap ↔ store).

### Mimo rozsah

- **Viewer dvoupanel** (seznam + detail vedle sebe) — zůstává jak je,
  na mobilu se rozbije; řeší samostatná fáze. Tohle je vědomé: viewer
  je největší kus a potřebuje vlastní task.
- **Formuláře / modály fullscreen na mobilu** — samostatná fáze.
- **Detaily v prohlížečích** — samostatná fáze.
- **Slot pro akce v top baru se teď NEPLNÍ** — jen se připraví prázdný.
  Přesun toolbar akcí (Přidat / Otevřít) z `ViewerToolbar` do top baru
  proběhne ve fázi vieweru, kde k tomu bude kontext.
- **„Hezký" titul vieweru v top baru** — když uživatel přijde na viewer
  přes `navigateToViewer` (dashboard widget), `activeItem.label` je jen
  technický `viewerId`. Pro fázi 1 to akceptujeme (běžná navigace ze
  sidebaru má lidský label). Vyřeší se ve fázi vieweru.
- **Swipe gesta** (zavření drawera tahem) — řešíme jindy, teď klik/Esc.
- **Tablet-specifické chování** — 768px je jediný breakpoint, tablet
  spadá do mobilního režimu. Jemnější ladění tabletu je out of scope.
- Persistence `drawerOpen` napříč reloady — drawer se po reloadu vždy
  zavře, to je žádoucí.

## Datový tok

```
window.matchMedia('(max-width: 768px)')
   │  (listener v layout.svelte.js)
   ▼
layoutStore.isMobile  ($state, reaktivní)
   │
   ├─► AppShell: isMobile ? [MobileTopBar + drawer] : [pevný sidebar]
   │
   └─► Sidebar: isMobile ? [✕ místo toggle, klik=navigace+zavřít]
                         : [toggle, collapse logika beze změny]

layoutStore.drawerOpen  ($state)
   │
   ├─► AppShell: třída --drawer-open na sidebaru + overlay viditelný
   │
   ├─► MobileTopBar hamburger: toggleDrawer()
   ├─► overlay klik: closeDrawer()
   ├─► Esc (document listener): closeDrawer()
   └─► Sidebar položka navigace: handleItemClick → closeDrawer()
```

Titul v top baru:

```
navigationStore.activeItem?.label  →  MobileTopBar titul
   (fallback prázdný řetězec, když activeItem == null)
```

## Co je potřeba udělat

### 1. Layout store — `frontend/src/stores/layout.svelte.js` (nový)

```js
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
```

Pozn.: `initLayout` se volá z `main.js` (bootstrap), ne z komponenty —
chceme jeden listener na celý život aplikace, ne per-mount.

### 2. main.js — zavolat initLayout

V `frontend/src/main.js` po importech přidat:

```js
import { layoutStore } from './stores/layout.svelte.js';

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout();
```

(Přesné umístění: kdekoli před `mount(App, ...)`. Nezávisí na DOM ready,
matchMedia je dostupné okamžitě.)

### 3. MobileTopBar — `frontend/src/components/layout/MobileTopBar.svelte` (nový)

```svelte
<script>
  import Icon from '../ui/Icon.svelte';
  import { iconMenu, iconClose } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { t } from '../../i18n/index.js';

  // Titul = label aktuální položky navigace. Fallback prázdný řetězec.
  // Pozn.: viewer navigovaný přes navigateToViewer má label == viewerId
  // (technický string) — akceptováno pro fázi 1, viz task out-of-scope.
  let title = $derived(navigationStore.activeItem?.label ?? '');
</script>

<header class="shpd-topbar">
  <button
    class="shpd-topbar__menu-btn"
    onclick={() => layoutStore.toggleDrawer()}
    aria-label={t('app.menu.open')}
  >
    <Icon icon={iconMenu} size="md" />
  </button>

  <span class="shpd-topbar__title">{title}</span>

  <!-- Slot pro budoucí akce (Přidat / Otevřít). Zatím prázdný placeholder,
       drží symetrii layoutu (titul je vystředěný mezi hamburgerem a slotem).
       Naplní se ve fázi vieweru. -->
  <div class="shpd-topbar__actions"></div>
</header>

<style>
  .shpd-topbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-sm);
    flex-shrink: 0;
    background-color: var(--shpd-color-bg-sidebar);
    color: var(--shpd-color-text-sidebar);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  .shpd-topbar__menu-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    color: var(--shpd-color-text-sidebar);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  .shpd-topbar__menu-btn:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  .shpd-topbar__title {
    flex: 1;
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Slot pro akce — drží šířku ~40px, aby titul zůstal opticky vystředěný
     vůči hamburgeru. Až se naplní, šířka se přizpůsobí obsahu. */
  .shpd-topbar__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    min-width: 40px;
    justify-content: flex-end;
  }
</style>
```

### 4. Ikony — `frontend/src/icons.js`

Zkontrolovat, že existují `iconMenu` (hamburger) a `iconClose` (✕).
Pokud `iconMenu` chybí, přidat:

```js
import { faBars } from '@fortawesome/free-solid-svg-icons';
export const iconMenu = faBars;
```

`iconClose` pravděpodobně existuje (✕ se používá v Modal / search clear),
ověřit grep — pokud chybí, přidat z `faXmark` nebo `faTimes`. Pozn.:
`MobileTopBar` importuje `iconClose`, ale fakticky ho používá až
`Sidebar` na ✕ tlačítku — viz krok 6. (Import v MobileTopBar lze
vynechat, pokud se ✕ řeší jen v Sidebaru; uveden pro jistotu, smazat
nepoužitý import před buildem kvůli warningu.)

### 5. AppShell — `frontend/src/components/layout/AppShell.svelte`

Přepracovat na dvě větve podle `layoutStore.isMobile`. Desktop větev
je dnešní stav beze změny.

```svelte
<script>
  import Sidebar from './Sidebar.svelte';
  import ContentArea from './ContentArea.svelte';
  import MobileTopBar from './MobileTopBar.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';

  let { onLogout } = $props();

  function handleNavigate(item) {
    navigationStore.navigate(item);
    // Na mobilu klik na položku zavře drawer (jinak by zůstal přes obsah).
    if (layoutStore.isMobile) layoutStore.closeDrawer();
  }

  // Esc zavírá drawer (jen mobil + otevřený drawer).
  $effect(() => {
    if (!layoutStore.isMobile || !layoutStore.drawerOpen) return;
    function onKeyDown(e) {
      if (e.key === 'Escape') layoutStore.closeDrawer();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  });
</script>

<div class="shpd-shell" class:shpd-shell--mobile={layoutStore.isMobile}>
  {#if layoutStore.isMobile}
    <!-- Mobilní režim: top bar nahoře, sidebar jako overlay drawer. -->
    <MobileTopBar />

    {#if layoutStore.drawerOpen}
      <div
        class="shpd-shell__overlay"
        onclick={() => layoutStore.closeDrawer()}
        aria-hidden="true"
      ></div>
    {/if}

    <div
      class="shpd-shell__drawer"
      class:shpd-shell__drawer--open={layoutStore.drawerOpen}
    >
      <Sidebar onNavigate={handleNavigate} {onLogout} />
    </div>

    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} />
    </div>
  {:else}
    <!-- Desktop režim: beze změny — pevný sidebar + obsah vedle. -->
    <Sidebar onNavigate={handleNavigate} {onLogout} />
    <div class="shpd-shell__main">
      <ContentArea activeItem={navigationStore.activeItem} />
    </div>
  {/if}
</div>

<style>
  .shpd-shell {
    display: flex;
    height: 100vh;
    overflow: hidden;
    position: relative;
  }

  /* Mobilní režim: shell je vertikální (top bar nad obsahem).
     Sidebar drawer a overlay jsou position:fixed mimo tok. */
  .shpd-shell--mobile {
    flex-direction: column;
  }

  .shpd-shell__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }

  /* --- Mobilní drawer + overlay --- */

  .shpd-shell__overlay {
    position: fixed;
    inset: 0;
    top: var(--shpd-header-height); /* pod top barem */
    background-color: var(--shpd-color-overlay);
    z-index: 90;
  }

  .shpd-shell__drawer {
    position: fixed;
    top: var(--shpd-header-height);
    bottom: 0;
    left: 0;
    width: 72%;
    max-width: 320px;
    z-index: 100;
    transform: translateX(-100%);
    transition: transform 0.22s ease;
    /* Drawer obsahuje Sidebar, který má vlastní pozadí (modré).
       Sidebar uvnitř roztáhneme na plnou výšku drawera. */
    display: flex;
  }

  .shpd-shell__drawer--open {
    transform: translateX(0);
  }
</style>
```

Důležité k `position: fixed` + `top: var(--shpd-header-height)`: drawer
i overlay začínají **pod** top barem (top bar zůstává vidět nad
ztmavením). To je záměr — uživatel vidí, kde je, a má hamburger/✕
k dispozici.

### 6. Sidebar — `frontend/src/components/layout/Sidebar.svelte`

Drobné úpravy pro mobilní režim. **Desktop chování nedotčené.**

#### 6a) Import

Přidat:

```js
import { layoutStore } from '../../stores/layout.svelte.js';
import { ..., iconClose, ... } from '../../icons.js';
```

#### 6b) Hlavička sidebaru — toggle vs. ✕

Dnešní hlavička má logo + toggle tlačítko. Na mobilu nahradit toggle
za ✕ (zavře drawer). Collapse na mobilu nedává smysl (drawer je vždy
„rozbalený", když je otevřený).

Stávající:

```svelte
<div class="shpd-sidebar__header">
  {#if !collapsed}
    <span class="shpd-sidebar__logo">Shipard</span>
  {/if}
  <button class="shpd-sidebar__toggle" onclick={toggleCollapse} title={...}>
    <Icon icon={collapsed ? iconExpand : iconCollapse} size="sm" />
  </button>
</div>
```

Přepracovat na:

```svelte
<div class="shpd-sidebar__header">
  {#if !collapsed || layoutStore.isMobile}
    <span class="shpd-sidebar__logo">Shipard</span>
  {/if}
  {#if layoutStore.isMobile}
    <button
      class="shpd-sidebar__toggle"
      onclick={() => layoutStore.closeDrawer()}
      aria-label={t('app.menu.close')}
    >
      <Icon icon={iconClose} size="sm" />
    </button>
  {:else}
    <button class="shpd-sidebar__toggle" onclick={toggleCollapse} title={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}>
      <Icon icon={collapsed ? iconExpand : iconCollapse} size="sm" />
    </button>
  {/if}
</div>
```

Pozn.: na mobilu je `collapsed` vždy `false` (uživatel ho nemá jak
přepnout — toggle tlačítko je nahrazeno ✕). Drawer renderuje plný
rozbalený sidebar. Podmínka `!collapsed || layoutStore.isMobile`
zajistí logo i v hypotetickém případě, kdyby `collapsed` zůstalo `true`
z desktopu při přepnutí na mobil.

#### 6c) Klik na položku → zavřít drawer

`handleItemClick` už volá `onNavigate?.(...)`, a `AppShell.handleNavigate`
nově zavře drawer na mobilu (viz krok 5). **Žádná změna v Sidebaru
není potřeba** — zavření jde přes `onNavigate` callback. Ověřit, že
`handleItemClick` v obou větvích (collapsed/expanded) volá `onNavigate`.

Pozn.: na mobilu se vykresluje rozbalená větev (`collapsed === false`),
takže klik jde přes rozbalený `handleItemClick`. Ten volá `onNavigate`,
takže drawer se zavře. OK.

### 7. i18n — nové klíče

Do `frontend/src/i18n/cs.js`:

```js
'app.menu.open':  'Otevřít menu',
'app.menu.close': 'Zavřít menu',
```

Do `frontend/src/i18n/en.js`:

```js
'app.menu.open':  'Open menu',
'app.menu.close': 'Close menu',
```

Spustit `npm run check:i18n` — musí projít.

### 8. Dokumentace — `docs/frontend.md`

V sekci **4. Aplikační shell** přidat pod popis layoutu novou
pod-sekci:

```
### Mobilní režim (drawer)

Na viewportu ≤ 768px se shell přepne do mobilního režimu (řídí
`layout.svelte.js` store přes `window.matchMedia`):

- Nahoře se objeví `MobileTopBar` — hamburger (otevře drawer), titul
  aktuální obrazovky (`navigationStore.activeItem.label`), vpravo prázdný
  slot pro budoucí akce (Přidat / Otevřít — naplní se ve fázi vieweru).
- Sidebar vystoupí z toku layoutu a stane se z něj **drawer** — vysune
  se zleva přes obsah (`position: fixed`, `transform: translateX`),
  zbytek ztmaví overlay. Recykluje stejný `Sidebar.svelte` jako desktop.
- Drawer se zavírá: klikem na overlay, ✕ tlačítkem v hlavičce drawera
  (nahrazuje desktopový collapse toggle), klávesou Esc, a klikem na
  položku navigace.
- Sbalovací logika (`collapsed`) se na mobilu nepoužívá — drawer je buď
  otevřený, nebo zavřený.

Na desktopu (> 768px) zůstává sidebar pevným sloupcem se sbalováním
beze změny.

**Breakpoint 768px** je definovaný na dvou místech, která musí ladit:
JS konstanta `MOBILE_BREAKPOINT` v `layout.svelte.js` a literál
v `@media` queries v komponentách. Stejný vzor jako theme/language
bootstrap ↔ store.
```

V `docs/design-system.md` sekce **8. Layout konvence** přidat krátkou
poznámku pod „Sidebar":

```
### Mobilní drawer

Na ≤ 768px se sidebar mění na overlay drawer (vysune zleva přes obsah,
overlay ztmaví zbytek). Spouští ho hamburger v `MobileTopBar`. Detaily
v [`frontend.md`](frontend.md) sekce *Aplikační shell → Mobilní režim*.
```

### 9. Smoke test

Po implementaci ručně ověřit. **Desktop** (široké okno, > 768px):

- Žádný top bar, sidebar je pevný sloupec vlevo. Sbalování (toggle)
  funguje jako dřív. Žádná regrese.
- Postupně zúžit okno pod 768px → shell se přepne do mobilního režimu
  (objeví se top bar, sidebar zmizí do drawera).

**Mobil** (úzké okno ≤ 768px, ideálně DevTools device toolbar ~380px):

- Nahoře top bar: hamburger vlevo, titul obrazovky (např. „Osoby"),
  vpravo prázdné místo. Obsah pod ním zabírá plnou šířku.
- Klik na hamburger → drawer vyjede zleva (animace), zbytek ztmaví.
  V drawer je plný sidebar (logo + ✕ vpravo nahoře, strom navigace,
  patka s uživatelem).
- Klik na overlay (ztmavená část vpravo) → drawer se zasune zpět.
- Klik na ✕ v hlavičce drawera → drawer se zasune.
- Esc → drawer se zasune.
- Otevřít drawer, kliknout na položku (např. „Úkoly") → drawer se zavře
  a obsah se přepne na Úkoly, titul v top baru se změní na „Úkoly".
- Patka v draweru — user dropdown funguje (Vzhled / Jazyk / Odhlásit).
- Vstup do Nastavení aplikace z draweru → drawer se zavře (přes
  `onNavigate`? — pozn.: enterSettings nejde přes navigate, ověřit,
  že se drawer zavře i tady; pokud ne, přidat `closeDrawer()` do
  `handleAppSettings` na mobilu — viz „Známé okrajové případy" níže).
- Rotace / resize z mobilu na desktop (rozšířit okno) → top bar zmizí,
  sidebar se vrátí jako pevný sloupec, drawer stav se vynuluje
  (`drawerOpen = false` v onChange).

**Light i dark mód** — overlay, top bar (modré pozadí sidebaru),
drawer fungují v obou (tokeny `--shpd-color-overlay`,
`--shpd-color-bg-sidebar` mají dark varianty).

### Známé okrajové případy k ověření

- **Settings vstup z draweru**: `handleAppSettings` v Sidebaru volá
  `navigationStore.enterSettings()`, ne `navigate()` → neprojde přes
  `AppShell.handleNavigate`, takže drawer se sám nezavře. Po vstupu do
  settings se ale překreslí navigace v draweru (mode změna), drawer
  zůstane otevřený. **Rozhodnutí**: na mobilu při `enterSettings`
  z draweru zavřít drawer. Implementace: v `handleAppSettings` přidat
  `if (layoutStore.isMobile) layoutStore.closeDrawer();` po
  `enterSettings()`. Stejně u `exitSettings` (back button) — ten je
  ale v hlavičce sidebaru, na mobilu se zobrazuje uvnitř draweru, takže
  taky přidat `closeDrawer()`.
- **Logout z draweru**: `handleLogoutFromMenu` nezavírá user menu
  záměrně (sidebar zmizí sám po `clearAuth`). Na mobilu to platí taky —
  celý drawer zmizí, když se aplikace přepne na LoginScreen. Není co
  řešit.

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů
  (pozor na nepoužité importy — `iconClose` v MobileTopBar, viz krok 4).
- `npm run check:i18n` projde (nové klíče `app.menu.open` /
  `app.menu.close` v obou slovnících).
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny, ověření, že jsme
  nic nerozbili).
- Smoke test (sekce 9) projde všemi body, desktop bez regrese.
- `docs/frontend.md` a `docs/design-system.md` aktualizovány.

## Rozhodnutí k designu (potvrzená)

- ✓ **Mobil = plnohodnotná práce na telefonu (~380px)**, včetně editace.
  Tato fáze klade základ (app chrome); viewer/formuláře/detaily přijdou
  v dalších fázích.
- ✓ **Strategie B** — CSS breakpointy na vzhled, JS layout store jen tam,
  kde se přepíná chování (drawer vs. pevný sloupec). Jediný nový JS stav:
  `layout.svelte.js`.
- ✓ **Drawer (varianta A)** — sidebar vyjede zleva přes obsah jako overlay.
  Recykluje stávající `Sidebar.svelte`. Vybráno proti top-bar-shora (B)
  a spodní liště ikon (C) — drawer nejlíp sedí na dynamickou stromovou
  navigaci a je nejmíň nového kódu.
- ✓ **Top bar: hamburger + titul + slot pro akce.** Slot se teď připraví
  prázdný, naplní se ve fázi vieweru (přesun Přidat / Otevřít).
- ✓ **Zavření drawera**: klik mimo (overlay) + ✕ tlačítko + Esc + klik
  na položku navigace. Swipe gesta out of scope.
- ✓ **Breakpoint 768px** — jeden breakpoint, tablet spadá do mobilního
  režimu. Definovaný jako JS konstanta + literál v media queries (musí
  ladit).
- ✓ **Desktop beze změny** — sidebar pevný sloupec se sbalováním zůstává.
  Mobil je čistě aditivní větev v AppShell.
- ✓ **`collapsed` se na mobilu nepoužívá** — drawer je open/closed, ne
  collapsed/expanded. Toggle tlačítko nahrazeno ✕.
- ✓ **Drawer šířka 72%, max 320px** — necháme kus obsahu prosvítat vpravo
  pod overlayem, aby bylo jasné, že je to overlay a klik mimo zavře.

## Mimo rozsah / nezasahujeme

- **Viewer** (`Viewer.svelte`, dvoupanel) — beze změny, řeší další fáze.
  Na mobilu se zatím rozbije (400px panel + detail se nevejdou); to je
  vědomě odložené.
- **Formuláře a modály** (`FormDialog`, `Modal`, fixní šířky) — beze
  změny, řeší další fáze.
- **`ViewerToolbar`** — akce Přidat / Otevřít zůstávají kde jsou; přesun
  do top baru je práce fáze vieweru.
- **`navigation.svelte.js`** — žádné změny, jen čtení `activeItem.label`.
- **Backend** — žádné změny, čistě frontend.
- **Swipe / touch gesta**, **klávesové zkratky**, **tablet ladění**,
  **persistence drawer stavu** — vše odložené.
