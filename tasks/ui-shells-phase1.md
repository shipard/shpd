# UI shells — Fáze 1: primitivy chrome, screen surface, activeSection

**Status:** připraveno k implementaci
**Issue:** [#45](https://github.com/shipard/shpd/issues/45) (zastřešující)
**Design doc:** `docs/ui-shells.md` §4–§7

## Cíl

Enabler pro budoucí shelly — **nulová viditelná změna UI**. Tři věci:

1. Rozbít `Sidebar.svelte` (~900 řádků) na skládatelné **primitivy chrome**,
   které budoucí shelly komponují místo reimplementace.
2. Zobecnit `topBar*` kanál v `layout.svelte.js` na **screen surface**
   (přejmenování + terminologie; sémantika beze změny).
3. Přidat **`activeSection`** do `navigationStore` — odvozený stav „sekce
   úrovně 1, do níž patří aktivní leaf".

## Návaznost

- `docs/ui-shells.md` — koncepty; tento PRD realizuje §5 (primitivy),
  §6 (screen surface), §7 (activeSection).
- Fáze 2 (command palette) staví na akčním slovníku a stromu — beze změn zde.
- Fáze 4 (shell `classic`) bude primitivy komponovat; velikostní varianty
  ikon v `NavIconStrip` se řeší **až tam** (rozhodnuto: teď extrakce 1:1).

## Před implementací přečti

- `docs/ui-shells.md` §4–§7 (kontrakt, primitivy, surface, activeSection)
- `frontend/src/components/layout/Sidebar.svelte` — celý (extrahuje se)
- `frontend/src/stores/layout.svelte.js` — topBar kanál (přejmenovává se)
- `frontend/src/stores/navigation.svelte.js` — `findLeafById`, normalizace
  položek (rozšiřuje se)
- `frontend/src/components/layout/MobileTopBar.svelte` — konzument kanálu
- `frontend/src/components/viewer/Viewer.svelte` řádky ~860–915 — publikující
  strana kanálu
- `docs/frontend.md` §4 (shell, sidebar, mobilní režim) — aktualizuje se

## Rozhodnutí v tomto PRD

Vyšší rozhodnutí jsou uzavřená v design docu; tady jsou implementační volby,
které PRD fixuje (při nesouhlasu zastavit a probrat):

- **R1 — umístění primitiv:** nový adresář
  `frontend/src/components/chrome/`. `layout/` zůstává pro shell-level
  komponenty (AppShell, ContentArea, MobileTopBar, ThemePanel, Sidebar);
  `chrome/` jsou stavební kusy, ze kterých se shelly skládají.
- **R2 — activeSection jako čistá derivace:** `navigationStore` dostane
  `appNavTree` (`$state`, plní Sidebar po loadu app navigace přes
  `setAppNavTree()`) a getter `activeSection` = lookup root sekce
  `activeItem.id` ve stromu. Žádný zápis při navigaci → nemůže zestárnout,
  funguje automaticky i pro `navigateToViewer()` z dashboardu a deep linky.
  V módech `settings`/`account` a pro `_top` leafy vrací `null`.
- **R3 — tree helpery do utils:** `frontend/src/utils/navTree.js` —
  `flattenLeaves` (ze Sidebaru), `findLeafById` (z navigationStore),
  nový `findRootSectionId(tree, leafId)`. Čisté funkce, unit-testovatelné
  přes `node --test`.
- **R4 — pojmenování surface:** `setScreenSurface` / `clearScreenSurface`;
  gettery `surfaceContext`, `surfaceActions`, `surfaceTitle`, `surfaceBack`.
  Komponenta `MobileTopBar` se **nepřejmenovává** — je to legitimní mobilní
  konzument surface, ne vlastník kanálu.
- **R5 — BrandingHeader bez collapse toggle:** toggle sbalení je specifikum
  sidebar shellu → zůstává v `Sidebar.svelte` vedle `BrandingHeader`.
- **R6 — fetch navigace zůstává v Sidebaru:** přesun do store/service až ve
  Fázi 4, kdy bude druhý konzument (classic shell). Teď by to byl refaktor
  bez užitku; Sidebar po loadu jen navíc volá `setAppNavTree()`.

## Scope — po souborech

### Nové soubory

**`frontend/src/utils/navTree.js`**
- `flattenLeaves(tree)` — přesun ze `Sidebar.svelte` (depth-first leafy
  s `type`), beze změny chování.
- `findLeafById(nodes, id)` — přesun z `navigation.svelte.js`, beze změny.
- `findRootSectionId(tree, leafId)` — nová: vrátí `id` root uzlu-sekce
  (uzel bez `type`, s `children`), pod nímž leaf leží; leaf na root úrovni
  (`_top`, dashboard, chat) → `null`; nenalezený leaf → `null`.

**`frontend/src/components/chrome/NavTree.svelte`**
- Rekurzivní renderer stromu: sekce/skupiny (toggle, chevron), leafy,
  zvýraznění aktivní položky (accent proužek + primary pozadí — třídy 1:1
  ze Sidebaru).
- Props: `tree`, `activeId`, `onNavigate(item)`.
- Interní stav rozbalených skupin + auto-expand cesty k aktivní položce
  (`collectAncestorGroupIds` se stěhuje sem).

**`frontend/src/components/chrome/NavIconStrip.svelte`**
- Plochý pás ikon leafů — extrakce collapsed větve Sidebaru **1:1**
  (ikona + `title` tooltip + zvýraznění aktivní). Používá
  `flattenLeaves` z utils.
- Props: `tree` (flatten si dělá sám přes `$derived`), `activeId`,
  `onNavigate(item)`.
- Žádné velikostní varianty (Fáze 4).

**`frontend/src/components/chrome/UserMenu.svelte`**
- Avatar + jméno (plný režim) / kruhový avatar (kompakt), dropdown:
  Nastavení účtu, Nastavení aplikace, jazyk, Odhlásit — vč. logiky
  skrývání položky aktuálního módu (`mode !== 'account'` / `!== 'settings'`).
- Props: `compact` (bool — dnešní collapsed vzhled vč. side-overlay
  varianty dropdownu), `onLogout`.
- Mode akce volá přímo `navigationStore` (enterAccount/enterSettings);
  jazyk přes `language.setMode`.
- **Pozor na past** click-bubbling při zavírání menu z handleru položky —
  chování převzít 1:1 (viz `docs/frontend.md` §9 *Dropdown / popover
  komponenty*); logout menu nezavírá (komponenta zmizí sama).

**`frontend/src/components/chrome/BrandingHeader.svelte`**
- Ikona aplikace (jen když `appInfoStore.icon`) + logo/shortName.
  Čte `appInfoStore` sám (analogie dnešního Sidebaru).
- Props: žádné povinné.

**`frontend/src/components/chrome/ModeBackBar.svelte`**
- „← Zpět do aplikace" (plný text) / kompaktní ikonové tlačítko.
- Props: `compact`, klik volá `navigationStore.exitToApp()`.

### Změny

**`frontend/src/stores/layout.svelte.js`**
- Přejmenování kanálu: `topBarContext/Actions/Title/Back` →
  `surfaceContext/surfaceActions/surfaceTitle/surfaceBack`;
  `setTopBar`/`clearTopBar` → `setScreenSurface`/`clearScreenSurface`.
- Sémantika, tvary hodnot i `null`-fallback kontrakt beze změny.
- Aktualizovat hlavičkový komentář: kanál je obecný „screen surface"
  (obrazovka publikuje, shell rozhoduje kde vykreslí); MobileTopBar je
  jeho mobilní konzument. Odkaz na `docs/ui-shells.md` §6.

**`frontend/src/stores/navigation.svelte.js`**
- `import { findLeafById, findRootSectionId } from '../utils/navTree.js'`
  (lokální `findLeafById` smazat).
- Nový `$state appNavTree = null` + `setAppNavTree(tree)`.
- Getter `activeSection`: `mode === 'app' && appActiveItem && appNavTree`
  → `findRootSectionId(appNavTree, appActiveItem.id)`, jinak `null`.
- Export `setAppNavTree` a getteru `activeSection`.

**`frontend/src/components/layout/Sidebar.svelte`**
- Stává se **kompozicí**: fetch navigace per mode (beze změny logiky),
  `collapsed` stav + toggle, a skládá `BrandingHeader` (+ toggle tlačítko),
  `ModeBackBar` (v settings/account), `NavTree` / `NavIconStrip`
  (dle `collapsed`), `UserMenu` (footer, `compact={collapsed}`).
- Po úspěšném loadu **app** navigace volá
  `navigationStore.setAppNavTree(tree)` (před `activateReportDeepLink` /
  `ensureDefaultActiveItem` — pořadí volání jinak beze změny).
- CSS: styly stěhovaných bloků jdou s komponentami; třídy `shpd-sidebar__*`
  u přesunutých částí přejmenovat na `shpd-navtree__*`, `shpd-usermenu__*`
  atd. dle nového vlastníka. Globální závislost
  `.shpd-shell__drawer :global(.shpd-sidebar)` v AppShell **nesmí** přestat
  platit (kořenová třída Sidebaru zůstává).
- Cíl velikosti: Sidebar po refaktoru ≲ 300 řádků.

**`frontend/src/components/viewer/Viewer.svelte`**
- Řádky ~860–915: `layoutStore.setTopBar(...)` → `setScreenSurface(...)`,
  `clearTopBar()` → `clearScreenSurface()`. Nic jiného.

**`frontend/src/components/layout/MobileTopBar.svelte`**
- Čtení přejmenovaných getterů. Chování beze změny.

**`docs/frontend.md`**
- §4: struktura sidebaru → odkaz na primitivy `components/chrome/`
  (tabulka z `docs/ui-shells.md` §5); zmínka o `activeSection`.
- §4 mobilní režim + §7 mobilní viewer: terminologie `topBar*` →
  screen surface (`setScreenSurface`), s poznámkou, že MobileTopBar je
  konzument.
- §2 adresářová struktura: doplnit `components/chrome/` a `utils/navTree.js`.

**`docs/ui-shells.md`**
- V §5/§6/§7 přepnout formulace z budoucího času na stav („realizováno
  Fází 1, viz frontend.md") — drobná úprava, ne přepis.

### Mimo scope Fáze 1

- Jakákoli vizuální změna (pixel-perfect zachování současného vzhledu).
- Velikostní/vzhledové varianty primitiv (Fáze 4/6).
- Přesun fetche navigace do store (R6), volba shellu, badge, paleta.
- Publikace surface z dalších obrazovek (FormStateBar řeší mobil přes
  `isMobile` kebab, ne přes surface — beze změny).

## Testy

- **Unit (`node --test`, `frontend/tests/Unit/navTree.test.mjs`):**
  `flattenLeaves` (vnořené skupiny, prázdný strom, leafy na rootu),
  `findLeafById` (nalezen/nenalezen, vnořený), `findRootSectionId`
  (leaf v sekci, leaf v pod-skupině sekce, root leaf `_top` → null,
  neznámý → null).
- **Build:** `npm run build` bez chyb a warningů Svelte.
- **`npm run check:i18n`** — beze změn klíčů (refaktor texty nemění).
- **Manuální smoke (dev, min. jedna DS):**
  1. desktop: rozbalený sidebar — skupiny, auto-expand aktivní cesty,
     zvýraznění, klik naviguje;
  2. collapsed: pás ikon, tooltips, aktivní stav, avatar + side dropdown;
  3. user menu: vstup do Nastavení účtu i aplikace, skrývání aktuálního
     módu, jazyk (reload), odhlášení (menu se nezavírá předčasně — past);
  4. settings/account mód: ModeBackBar plný i kompaktní, návrat drží
     poslední app položku;
  5. mobil (≤768px): drawer otevřít/zavřít (overlay, ✕, Esc, klik na
     položku), MobileTopBar — viewer list akce, detail ← zpět + kebab;
  6. ThemePanel: otevření z Nastavení účtu, pozice dle collapsed;
  7. konzole bez warningů (zvl. `state_referenced_locally`).

## Strategie commitů

1. `refactor(frontend): extract nav tree helpers to utils/navTree.js`
   (+ unit testy)
2. `refactor(frontend): split Sidebar into chrome primitives`
   (NavTree, NavIconStrip, UserMenu, BrandingHeader, ModeBackBar + kompozice)
3. `refactor(frontend): rename topBar channel to screen surface (#45)`
   (layout store + Viewer + MobileTopBar)
4. `feat(frontend): activeSection derived from app nav tree (#45)`
5. `docs: frontend.md — chrome primitives, screen surface, activeSection`

Commity průběžně po dokončení kroku; push dělá David.

## Hotovo když

- [ ] `components/chrome/` obsahuje 5 primitiv dle scope; Sidebar je
      kompozice ≲ 300 řádků
- [ ] `utils/navTree.js` + unit testy zelené (`npm test`)
- [ ] kanál přejmenován na screen surface; `grep -rn "topBar" frontend/src`
      nevrací nic (mimo případný komentář „dříve topBar")
- [ ] `navigationStore.activeSection` funguje (ověřit v konzoli / dočasným
      logem pro leaf v sekci, `_top` leaf a settings mód)
- [ ] `npm run build` + `npm run check:i18n` čisté
- [ ] manuální smoke 1–7 prošel, UI vizuálně beze změny
- [ ] `docs/frontend.md` a `docs/ui-shells.md` aktualizované
- [ ] komentář v issue #45: Fáze 1 hotová (odkaz na commity)
