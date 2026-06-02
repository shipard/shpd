# Task: Mobilní viewer — list/detail přepínání + akce v top baru (fáze 2)

## Status / Cíl

Druhá fáze responzivního designu. Navazuje na `mobile-app-chrome-phase1.md`
(drawer sidebar + `MobileTopBar` + `layout.svelte.js` store). Tato fáze
zprovozní **viewer** na telefonu (~380px).

Dnešní viewer je dvoupanel (seznam 400px + detail `flex:1` vedle sebe).
Na 380px se nevejde. Řešení = **list/detail přepínání**: na mobilu je
v daný moment vidět buď seznam, nebo detail, ne obojí. Řídí to už
existující `selectedRowId` (`null` = seznam, ne-null = detail).

Akce se přesouvají do `MobileTopBar` (slot připravený ve fázi 1):

- **Seznam** (nic nevybráno): top bar = hamburger + titul + akce seznamu
  jako ikony vpravo (Přidat, příp. Přidat z registru — reálně 1–2).
- **Detail** (vybraný záznam): top bar = **← Zpět** vlevo (místo
  hamburgeru) + titul záznamu + **hlavní akce jako ikona** + **kebab (⋮)**
  se zbytkem akcí vpravo.

Tok akcí: viewer zapisuje svoje aktuální akce + kontext do `layoutStore`,
`MobileTopBar` je čte a renderuje. Jen na mobilu. Na desktopu zůstává
`ViewerToolbar` ve vieweru beze změny.

Na desktopu (> 768px) je viewer i akce **beze změny** — dvoupanel,
`ViewerToolbar` nahoře. Mobil je aditivní větev.

## Návaznost

- `mobile-app-chrome-phase1.md` — `layout.svelte.js` (`isMobile`,
  `drawerOpen`), `MobileTopBar.svelte` (hamburger + titul + prázdný slot).
  Tato fáze ten slot naplní a rozšíří store o viewer akce.
- `viewer-number-series-tabs.md` — spodní lišta číselných řad. Na mobilu
  zůstává součástí seznamu (scrolluje horizontálně jako dnes).
- `Popover.svelte` — anchorovaný floating panel s klik-mimo + Esc +
  viewport clamp. Kebab menu v detailu ho recykluje (stejně jako dnes
  `ViewerDetail` dropdown akce `kind: 'dropdown'`).
- Dokumentace: `docs/frontend.md` sekce **Aplikační shell → Mobilní
  režim** (z fáze 1) rozšířit o viewer list/detail. `docs/frontend.md`
  sekce o vieweru aktualizovat.

## Scope

### V rozsahu

- **Rozšíření `layout.svelte.js`** o kanál pro top-bar akce:
  `topBarActions` (pole), `topBarContext` (`'list' | 'detail' | null`),
  `topBarBackHandler` (fce nebo null), `topBarTitle` (override titulu).
  Metody `setTopBar({...})` a `clearTopBar()`.
- **Úprava `MobileTopBar.svelte`** — když `layoutStore.topBarContext`
  není null, renderovat podle něj: vlevo hamburger (list) nebo ← zpět
  (detail), vpravo akce (ikony) + případně kebab. Když je null (jiná
  obrazovka než viewer, např. dashboard), fallback na dnešní chování
  (hamburger + titul + prázdný slot).
- **Úprava `Viewer.svelte`** — na mobilu:
  - List/detail přepínání přes CSS (panel se ukáže/schová podle
    `selectedRowId` + `isMobile`), ne přes dva panely vedle sebe.
  - `$effect`, který publikuje akce do `layoutStore` podle stavu
    (seznam → `meta.toolbar`, detail → `detailToolbar` + back handler).
  - Úklid (`clearTopBar`) při unmountu a při přepnutí na desktop.
  - Skrýt `ViewerToolbar` na mobilu (akce jsou v top baru). Desktop ho
    renderuje dál.
- **Kebab v top baru** — nová malá komponenta nebo inline v MobileTopBar:
  tlačítko ⋮ (`iconMore`) otevře `Popover` se seznamem akcí. Recykluje
  vzor z `ViewerDetail`.
- **Rozdělení hlavní vs. vedlejší akce v detailu** — backend už posílá
  akce v `detail.toolbar`. Frontend vezme první jako „hlavní" (ikona),
  zbytek do kebabu. Pozn.: dnešní `detailToolbar` je plochý seznam bez
  označení „hlavní"; pro fázi 1 bereme heuristiku „první = hlavní".
  (Explicitní `primary: true` flag z backendu je out of scope, viz níže.)
- Nové i18n klíče: `viewer.back` (aria-label/titul ← zpět), `viewer.more`
  (aria-label kebabu).

### Mimo rozsah

- **Explicitní `context: 'list' | 'detail'` z backendu** — fáze 1 jede
  na stávajícím rozdělení (`meta.toolbar` = seznam, `detail.toolbar` =
  detail), které už fakticky kontext nese. Explicitní pole je připravený
  směr, až bude potřeba jemnější řízení (např. akce dostupná v obou
  kontextech). Nezavádíme teď — žádná backend změna.
- **Explicitní `primary: true` na akci** — heuristika „první akce =
  hlavní ikona" stačí pro fázi 1. Pokud se ukáže, že pořadí z backendu
  nesedí, doplní se flag později.
- **Formuláře / modály fullscreen** — `FormDialog` se z vieweru otevírá,
  ale jeho mobilní chování řeší samostatná fáze. Tady se jen ověří, že
  se otevře a jde zavřít (i kdyby zatím nebyl ideálně velký).
- **Detaily v prohlížečích — vnitřní layout** (properties grid, tabulky
  v detailu) — detail teď dostane plnou šířku, což většinu vyřeší;
  jemné doladění vnitřku detailu (široké tabulky) je samostatná práce.
- **Swipe gesta** (swipe zpět) — řešíme jindy, teď ← tlačítko.
- **Číselné řady / viewGroup taby — mobilní redesign** — zůstávají jak
  jsou, součást seznamu. Horizontální scroll u série už dnes funguje.
- **Browser historie / URL routing** (back tlačítko prohlížeče = zpět
  na seznam) — viewer dnes nemá URL routing, nezavádíme ho tady.

## Datový tok

```
selectedRowId (Viewer $state)
   │  null = seznam, ne-null = detail
   ▼
isMobile && selectedRowId → CSS: který panel je vidět
   │
   └─► $effect ve Vieweru publikuje top bar:
         selectedRowId == null:
            layoutStore.setTopBar({
              context: 'list',
              actions: meta.toolbar,        // Přidat, Přidat z registru
              title:   tab.label,           // nebo z meta
              back:    null,
            })
         selectedRowId != null:
            layoutStore.setTopBar({
              context: 'detail',
              actions: detailToolbar,       // Otevřít + zbytek
              title:   detail?.title ?? '', // titul záznamu
              back:    () => { selectedRowId = null; detail = null; },
            })

layoutStore.topBar* (čte MobileTopBar)
   │
   ├─ context 'list':   [hamburger] [titul] [akce jako ikony]
   ├─ context 'detail': [← zpět]    [titul] [hlavní ikona] [⋮ kebab]
   └─ context null:     [hamburger] [titul] [prázdný slot]   (dashboard ap.)

MobileTopBar akce klik:
   - list akce / hlavní detail akce / kebab položka
     → zavolá handler, který viewer předal ve `actions[].onClick`
       (viewer si drží mapování id → handleToolbarAction / handleDetailAction)
```

Klíčové rozhodnutí o předávání handlerů: `setTopBar` dostane akce už
s navázaným `onClick` callbackem (viewer si ho vyrobí ze svého
`handleToolbarAction` / `handleDetailAction`). MobileTopBar tak nemusí
nic vědět o vieweru — jen volá `action.onClick()`. To drží MobileTopBar
generický.

## Co je potřeba udělat

### 1. Rozšířit `layout.svelte.js` o top-bar kanál

Přidat k existujícímu storu (z fáze 1):

```js
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
```

A do exportu `layoutStore` přidat gettery + metody:

```js
  get topBarContext() { return topBarContext; },
  get topBarActions() { return topBarActions; },
  get topBarTitle()   { return topBarTitle; },
  get topBarBack()    { return topBarBack; },
  setTopBar,
  clearTopBar,
```

### 2. Přepracovat `MobileTopBar.svelte`

Renderovat podle `topBarContext`. Když je null → dnešní chování (fáze 1).

```svelte
<script>
  import Icon from '../ui/Icon.svelte';
  import Popover from '../ui/Popover.svelte';
  import { iconMenu, iconChevronLeft, iconMore } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { resolveIcon } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  // Titul: override z top-bar kanálu, jinak z navigace.
  let title = $derived(
    layoutStore.topBarTitle ?? navigationStore.activeItem?.label ?? ''
  );

  let context = $derived(layoutStore.topBarContext);
  let actions = $derived(layoutStore.topBarActions ?? []);

  // V detailu: první akce = hlavní (ikona), zbytek = kebab.
  let mainAction  = $derived(context === 'detail' && actions.length > 0 ? actions[0] : null);
  let kebabActions = $derived(context === 'detail' ? actions.slice(1) : []);

  // V seznamu: všechny akce jako ikony (reálně 1–2).
  let listActions = $derived(context === 'list' ? actions : []);

  let kebabOpen = $state(false);
  let kebabAnchor = $state(null);

  function openKebab(e) {
    kebabAnchor = e.currentTarget;
    kebabOpen = true;
  }
  function closeKebab() { kebabOpen = false; }

  function runAction(action) {
    closeKebab();
    action.onClick?.();
  }

  function handleLeft() {
    if (context === 'detail' && layoutStore.topBarBack) {
      layoutStore.topBarBack();
    } else {
      layoutStore.toggleDrawer();
    }
  }

  // Ikona akce — backend posílá string jméno, resolveIcon přeloží.
  // Fallback undefined (radši nic než iconTable na ikonovém tlačítku).
  function actionIcon(action) {
    if (typeof action.icon === 'string' && action.icon !== '') {
      return resolveIcon(action.icon, undefined);
    }
    return action.icon ?? undefined;
  }
</script>

<header class="shpd-topbar">
  <button
    class="shpd-topbar__menu-btn"
    onclick={handleLeft}
    aria-label={context === 'detail' ? t('viewer.back') : t('app.menu.open')}
  >
    <Icon icon={context === 'detail' ? iconChevronLeft : iconMenu} size="md" />
  </button>

  <span class="shpd-topbar__title">{title}</span>

  <div class="shpd-topbar__actions">
    {#if context === 'list'}
      {#each listActions as action (action.id)}
        <button
          class="shpd-topbar__action-btn"
          onclick={() => runAction(action)}
          aria-label={action.label}
          title={action.label}
        >
          <Icon icon={actionIcon(action)} size="md" />
        </button>
      {/each}
    {:else if context === 'detail'}
      {#if mainAction}
        <button
          class="shpd-topbar__action-btn"
          onclick={() => runAction(mainAction)}
          aria-label={mainAction.label}
          title={mainAction.label}
        >
          <Icon icon={actionIcon(mainAction)} size="md" />
        </button>
      {/if}
      {#if kebabActions.length > 0}
        <button
          class="shpd-topbar__action-btn"
          onclick={openKebab}
          aria-label={t('viewer.more')}
        >
          <Icon icon={iconMore} size="md" />
        </button>
      {/if}
    {/if}
  </div>
</header>

{#if kebabOpen}
  <Popover open={true} anchor={kebabAnchor} placement="bottom" onClose={closeKebab}>
    <div class="shpd-topbar__kebab-menu">
      {#each kebabActions as action (action.id)}
        <button
          type="button"
          class="shpd-topbar__kebab-item"
          onclick={() => runAction(action)}
        >
          {action.label}
        </button>
      {/each}
    </div>
  </Popover>
{/if}

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

  .shpd-topbar__menu-btn,
  .shpd-topbar__action-btn {
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

  .shpd-topbar__menu-btn:hover,
  .shpd-topbar__action-btn:hover {
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

  .shpd-topbar__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    justify-content: flex-end;
  }

  /* Kebab menu — položky v Popoveru. Stejný vzor jako detail dropdown. */
  .shpd-topbar__kebab-menu {
    display: flex;
    flex-direction: column;
    min-width: 160px;
    padding: 4px 0;
  }

  .shpd-topbar__kebab-item {
    text-align: left;
    padding: 8px 14px;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }

  .shpd-topbar__kebab-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }
</style>
```

Pozn.: kebab menu položky renderují uvnitř `Popover`, jehož pozadí je
`--shpd-color-bg` (světlé), proto text `--shpd-color-text` (ne sidebar
text). Top bar tlačítka jsou na modrém pozadí → sidebar text. Pozor na
to při kontrole v dark módu.

### 3. Viewer — publikování akcí + list/detail layout

#### 3a) Import

```js
import { layoutStore } from '../../stores/layout.svelte.js';
```

#### 3b) $effect publikující top bar (jen mobil)

Přidat nový `$effect` (vedle stávajícího init efektu). **Pozor**: tento
efekt čte `isMobile`, `selectedRowId`, `meta`, `detail`, `toolbarActions`
— reaktivně se přepočítá při změně kteréhokoli. To je žádoucí (akce se
mají aktualizovat při výběru řádku i při přepnutí mobil/desktop).

```js
// Publikování akcí do MobileTopBaru (jen mobil). Na desktopu se akce
// renderují ve ViewerToolbar (beze změny), takže top bar nečteme.
$effect(() => {
  if (!layoutStore.isMobile) {
    layoutStore.clearTopBar();
    return;
  }

  if (selectedRowId == null) {
    // Seznam — akce z meta.toolbar (Přidat, Přidat z registru, …).
    const actions = (meta?.toolbar ?? []).map(a => ({
      id: a.id,
      label: a.label,
      icon: a.icon,
      variant: a.variant,
      onClick: () => handleToolbarAction(a.id),
    }));
    layoutStore.setTopBar({
      context: 'list',
      actions,
      title: tab.label ?? null,
      back: null,
    });
  } else {
    // Detail — akce z detailToolbar. První = hlavní (ikona), zbytek kebab.
    // detailToolbar má stejný tvar jako meta.toolbar (id/label/icon/variant).
    const actions = (detailToolbar ?? []).map(a => ({
      id: a.id,
      label: a.label,
      icon: a.icon,
      variant: a.variant,
      onClick: () => handleToolbarAction(a.id),
    }));
    layoutStore.setTopBar({
      context: 'detail',
      actions,
      title: detail?.title ?? tab.label ?? null,
      back: () => {
        selectedRowId = null;
        detail = null;
      },
    });
  }
});

// Úklid při unmountu — ať akce nezůstanou na další obrazovce.
$effect(() => {
  return () => layoutStore.clearTopBar();
});
```

POZOR — `handleToolbarAction` dnes řeší jen `meta.toolbar` akce
(create, edit, import_from_registry, reanalyze, runDue). Detailní akce
jako snooze/dismiss jdou přes `handleDetailAction` (jiná signatura:
`actionId, action, value`). Pro detail akce v top baru je potřeba volat
správný handler. Řešení: v mapování detailních akcí volat
`handleDetailAction(a.id, a, null)` místo `handleToolbarAction`:

```js
    // Detail akce — pozor, jiný handler než list akce.
    const actions = (detailToolbar ?? []).map(a => ({
      ...
      onClick: () => handleDetailAction(a.id, a, null),
    }));
```

Ale akce jako `edit` (Otevřít/Upravit) jdou dnes přes
`handleToolbarAction('edit')`, ne `handleDetailAction`. **Tady je
nejednoznačnost, kterou Claude Code musí vyřešit při implementaci**:
zmapovat, které id z `detailToolbar` patří kterému handleru. Doporučený
postup: zjistit, co backend posílá v `detail.toolbar` (resp.
`result.data.toolbar` z detail endpointu) pro Osoby a Došlou poštu, a
podle toho rozhodnout. Pravděpodobně `edit` → `handleToolbarAction`,
custom akce (`kind`) → `handleDetailAction`. Viz „Otevřené body" níže.

#### 3c) List/detail CSS přepínání

Dnešní `body` je flex se dvěma panely. Na mobilu chceme jen jeden
viditelný. Přístup: třídy na `body` podle `isMobile` + `selectedRowId`,
zbytek řeší CSS.

V markupu obal `body`:

```svelte
<div
  class="shpd-viewer__body"
  class:shpd-viewer__body--mobile={layoutStore.isMobile}
  class:shpd-viewer__body--detail={layoutStore.isMobile && selectedRowId != null}
>
```

CSS:

```css
/* --- Mobilní list/detail přepínání --- */
/* Na mobilu je vidět jen jeden panel. Bez vybraného řádku seznam přes
   celou šířku; s vybraným řádkem detail přes celou šířku, seznam skrytý. */
@media (max-width: 768px) {
  .shpd-viewer__body--mobile .shpd-viewer__list-panel {
    width: 100%;
    flex-shrink: 1;
    border-right: none;
  }

  .shpd-viewer__body--mobile .shpd-viewer__detail-panel {
    display: none;
  }

  /* Detail stav: seznam pryč, detail přes celou šířku. */
  .shpd-viewer__body--detail .shpd-viewer__list-panel {
    display: none;
  }

  .shpd-viewer__body--detail .shpd-viewer__detail-panel {
    display: block;
  }
}
```

Pozn.: `selectedRowId` řídí jak top bar (přes store), tak viditelnost
panelů (přes třídu). Back handler v top baru nastaví `selectedRowId =
null` → seznam se vrátí. Konzistentní jeden zdroj pravdy.

#### 3d) Skrýt ViewerToolbar na mobilu

```svelte
{#if !layoutStore.isMobile}
  <ViewerToolbar actions={toolbarActions} onAction={handleToolbarAction} />
{/if}
```

Na mobilu jsou akce v top baru (publikované přes store), takže
`ViewerToolbar` se nerenderuje. Desktop beze změny.

### 4. i18n — nové klíče

`frontend/src/i18n/cs.js`:

```js
'viewer.back': 'Zpět na seznam',
'viewer.more': 'Další akce',
```

`frontend/src/i18n/en.js`:

```js
'viewer.back': 'Back to list',
'viewer.more': 'More actions',
```

`npm run check:i18n` musí projít.

### 5. Dokumentace

`docs/frontend.md` — v sekci o vieweru přidat pod-sekci:

```
### Mobilní viewer (list/detail)

Na ≤ 768px se viewer přepne z dvoupanelu na list/detail přepínání:
v daný moment je vidět buď seznam, nebo detail (řídí `selectedRowId`).
Bez vybraného záznamu se zobrazí seznam přes celou šířku; po kliknutí
na řádek se seznam skryje a detail zabere celou šířku.

Akce se přesouvají do `MobileTopBar` (přes `layout.svelte.js` store,
kanál `topBar*`):

- Seznam: hamburger + titul + akce seznamu jako ikony (Přidat, …).
- Detail: ← zpět (vlevo, místo hamburgeru) + titul záznamu + hlavní
  akce jako ikona + kebab (⋮) se zbytkem akcí.

Viewer publikuje akce reaktivně přes `layoutStore.setTopBar(...)`
podle `selectedRowId`; akce nesou navázaný `onClick`, takže MobileTopBar
o vieweru nic neví. Při unmountu / přepnutí na desktop viewer volá
`clearTopBar()`. Na desktopu zůstává `ViewerToolbar` ve vieweru beze
změny.
```

### 6. Smoke test

**Desktop** (> 768px):

- Viewer beze změny — dvoupanel, `ViewerToolbar` nahoře (Přidat/…),
  klik na řádek ukáže detail vpravo. Žádná regrese.

**Mobil** (≤ 768px, ideálně ~380px):

- Otevři viewer Osoby. Top bar: hamburger vlevo, „Osoby" titul, vpravo
  ikona Přidat (+) a Přidat z registru (pokud Osoby tu akci mají).
  Seznam přes celou šířku, žádný `ViewerToolbar`, žádný detail panel.
- Doc-state taby, search, řádky, série dole — fungují, plná šířka.
- Klik na řádek → seznam zmizí, detail přes celou šířku. Top bar se
  změní: vlevo ← zpět, titul = jméno záznamu, vpravo hlavní akce (ikona)
  + ⋮ kebab.
- Klik na ⋮ → Popover se zbytkem akcí (u Došlé pošty např. „Znovu
  analyzovat"). Klik na položku akci spustí, menu se zavře.
- Klik na ← zpět → detail zmizí, seznam se vrátí, top bar zpět na list
  kontext (hamburger + Přidat).
- Hamburger v seznamu → otevře drawer (z fáze 1, pořád funguje).
- Přidat (+) v seznamu → otevře FormDialog (nový záznam). Ověř, že jde
  zavřít (i kdyby modál nebyl ještě ideálně velký — to řeší další fáze).
- Došlá pošta: detail s extracted dokumenty — akce Apply/Reject jsou
  uvnitř detailu (v contentu), ne v top baru. Ověř, že fungují (ty
  nejsou v `detailToolbar`, jsou v tab contentu — neměly by se do top
  baru vůbec dostat).
- Přepni okno na desktop (rozšiř) v detailu → viewer se vrátí na
  dvoupanel, top bar zmizí, `ViewerToolbar` se objeví, `clearTopBar`
  uklidil. Žádné viset zůstalé akce.
- Naviguj z vieweru na Dashboard (přes drawer) → top bar je zpět na
  fallback (hamburger + „Dashboard" + prázdný slot), žádné viewer akce
  neviset.

**Light i dark** — top bar akce (modré pozadí → světlý text), kebab
Popover (světlé pozadí → tmavý text) čitelné v obou.

### Otevřené body k vyřešení při implementaci

- **Mapování detailních akcí na handlery** (viz 3b): zjistit z backendu,
  co je v `detail.toolbar` pro Osoby a Došlou poštu, a správně nasměrovat
  `edit` → `handleToolbarAction`, custom `kind` akce →
  `handleDetailAction`. Možná bude potřeba malý dispatcher ve vieweru:
  `function runDetailAction(a) { if (a.id === 'edit' || a.id === 'create')
  handleToolbarAction(a.id); else handleDetailAction(a.id, a, null); }`.
  Ověřit, že `reanalyze` (otevírá dialog) funguje i z kebabu.
- **Akce s `confirm`** — `handleDetailAction` dnes řeší confirm uvnitř
  `ViewerDetail.handleAction`, ne ve vieweru. Pokud detailní akce s
  `confirm` jde přes top bar kebab, confirm se musí aplikovat taky.
  Ověřit / přidat `window.confirm(a.confirm)` do dispatcheru.
- **Akce s `kind: 'dropdown'`** (snooze má sub-položky) — v top baru by
  dropdown akce potřebovala vlastní pod-menu, což kebab nepodporuje.
  Pro fázi 1: pokud `detailToolbar` obsahuje `kind: 'dropdown'`, řešit
  jak — buď ji do kebabu dát jako jednu položku, která otevře další
  Popover (složité), nebo ji z top baru vynechat a nechat ji řešit jinde.
  **Doporučení**: zjistit, jestli `detailToolbar` vůbec obsahuje
  dropdown akce (snooze je v `detail.actions`, ne nutně v toolbaru!).
  Pravděpodobně toolbar dropdowny neobsahuje a problém je teoretický —
  ověřit a pokud ano, není co řešit.

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů.
- `npm run check:i18n` projde (klíče `viewer.back`, `viewer.more`).
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny).
- Smoke test (sekce 6) projde, desktop bez regrese.
- `docs/frontend.md` aktualizován.

## Rozhodnutí k designu (potvrzená)

- ✓ **List/detail přepínání** — na mobilu vidět buď seznam, nebo detail.
  Řídí `selectedRowId` (už existuje). Detail dostane plnou šířku.
- ✓ **Akce do top baru (varianta 1)** — v chytřejší podobě rozdělené
  podle kontextu: seznam = 1–2 akce jako ikony, detail = hlavní akce
  ikona + kebab (⋮) se zbytkem. Tím padá původní námitka „moc akcí se
  do top baru nevejde" — kebab pojme libovolný počet.
- ✓ **Žádná lišta navíc** — top bar už zabírá výšku, akce do něj jsou
  „zadarmo". Druhá lišta (varianta 2) by ukrojila ~44px z každé
  obrazovky; vertikální prostor je na mobilu nejdražší.
- ✓ **Kebab = Popover** — recykluje existující `Popover.svelte` (klik-mimo,
  Esc, viewport clamp už hotové), stejný vzor jako detail dropdown akce.
- ✓ **← Zpět v top baru vlevo** — nahrazuje hamburger v detail kontextu
  (ne samostatný řádek). Vrací na seznam (`selectedRowId = null`).
- ✓ **Tok akcí přes layout store** — viewer publikuje `setTopBar({...})`
  s navázanými `onClick`; MobileTopBar je generický, o vieweru neví.
  Zvoleno proti prop-drillingu přes `AppShell → ContentArea → Viewer`.
- ✓ **Backend kontext zatím implicitní** — `meta.toolbar` = seznam,
  `detail.toolbar` = detail (rozdělení už existuje). Explicitní
  `context` pole odloženo.
- ✓ **Hlavní akce = první v detailToolbar** — heuristika pro fázi 1,
  explicitní `primary` flag odložen.
- ✓ **Desktop beze změny** — dvoupanel + `ViewerToolbar`. Mobil aditivní.
- ✓ **iconMore (⋮), iconChevronLeft (←), iconAdd, iconMenu** — všechny
  už v `icons.js`, žádné nové.

## Mimo rozsah / nezasahujeme

- **Formuláře / modály fullscreen** — samostatná fáze. Tady jen ověřit,
  že FormDialog z vieweru jde otevřít/zavřít.
- **Vnitřní layout detailu** (široké tabulky, properties grid) — detail
  dostane plnou šířku, jemné doladění vnitřku jindy.
- **Backend** — žádné změny (`context`/`primary` pole odložena).
- **Extracted dokumenty akce** (Apply/Reject) — jsou v tab contentu
  detailu, ne v toolbaru, takže do top baru nepatří a needitujeme je.
- **Swipe gesta, URL routing, browser back** — odložené.
- **viewGroup / číselné řady taby redesign** — zůstávají jak jsou.
