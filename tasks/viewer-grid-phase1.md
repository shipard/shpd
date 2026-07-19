# Viewer grid — Fáze 1: infrastruktura + pilot JournalViewer

> PRD pro jednu Claude Code session. Design: `docs/viewer-grid.md`
> (rozhodnutí D1–D8, API kontrakty §3, frontend §4–§6). Staví na
> stávajícím viewer systému (`docs/frontend.md` §7) — nic z něj se
> nerozbíjí, grid je aditivní druhý layout.

## Kontext

Viewer systém dnes umí jen list layout (řádky t1/i1/t2/i2/t3 + detail
panel). Pro účetní deník, bankovní transakce a saldokonto je potřeba
klasická tabulka se sloupci. Klíčový princip (D1): **layout je
prezentační režim jednoho vieweru, ne jiná třída** — `selectRows()`,
filtry, search, detail i toolbar zůstávají sdílené, liší se jen render
řádku.

## Cíl

1. `TableViewer`: `getGridColumns()`, `getGridOptions()`,
   `renderGridRow()`, `getDefaultLayout()`, `renderGridFooter()` —
   vše s defaulty, existující viewery beze změny.
2. `ViewerController`: `layout` parametr na `rows`, meta klíče
   `layouts` / `defaultLayout` / `grid`, footer s page 0.
3. Frontend: `ViewerGrid.svelte` (sticky thead/footer, infinite scroll,
   index sloupec, skupinové hlavičky, stavový proužek),
   `ViewerDetailDrawer.svelte` (non-modální slide-over),
   `viewerSpans.js` (sdílená normalizace + badge — i pro list).
4. Pilot: `JournalViewer` s grid layoutem jako defaultem + footer
   s obraty MD/DAL.

## Návaznost

- **Prerekvizity:** žádné — staví na stávajícím viewer systému.
- **Odemyká:** F2 (řazení, toggle, `BankTransactionsViewer` grid),
  saldokonto grid (vstup do accbal Fáze 4 — párovací UI).
- Toggle layoutů je **F2** — v F1 se efektivní layout mění jen přes
  mobile breakpoint.

## Před implementací přečti

- `docs/viewer-grid.md` — celý (krátký); závazné kontrakty §3 (backend),
  §4 (frontend), §5 (skupiny), §6 (drawer)
- `src/Core/Viewer/TableViewer.php` — kam přidáváš metody; dbej na
  docblock styl souboru
- `src/Api/Controller/ViewerController.php` — `meta()` a `rows()`;
  `$filters` už obsahují viewGroup i number_series jako položky
- `frontend/src/components/viewer/Viewer.svelte` — orchestrace;
  POZOR na disciplínu `$effect` (nesmí číst jiný $state než
  `tab.viewerId` — viz komentáře v souboru; `fetchRowsExplicit`
  přijímá explicitní parametry, budeš přidávat `layout`)
- `frontend/src/components/viewer/ViewerRow.svelte` —
  `normalizeSpans()` k vytažení; span třídy
- `frontend/src/components/viewer/ViewerDetail.svelte` — props kontrakt
  (detail/loading/onRefresh/onAction), pro drawer se NEmění
- `frontend/src/components/ui/Modal.svelte` — stack management (kvůli
  Esc koordinaci draweru)
- `modules/economy/accounting/src/JournalViewer.php` — pilot;
  `formatMoney`/`formatDate` helpery, `selectRows` WHERE skladba
  (footer použije stejnou)
- `tests/Unit/Module/Economy/Accounting/JournalViewerTest.php` +
  `tests/Unit/Api/Controller/ViewerControllerGuardTest.php` — vzory testů
- `frontend/src/i18n/cs.js` / `en.js` — přidání klíčů; po změně
  `npm run check:i18n`
- `docs/design-system.md` §4–§5 — doc-state proužky, badge paleta

## Scope

**Uvnitř:** TableViewer metody; ViewerController layout/meta/footer;
`viewerSpans.js` + badge (vč. renderu v ViewerRow); `ViewerGrid.svelte`
vč. skupinových hlaviček a footeru; `ViewerDetailDrawer.svelte`;
zapojení do `Viewer.svelte` (efektivní layout, mobile fallback, drawer);
JournalViewer grid + footer; testy; aktualizace `docs/frontend.md` §7
a `docs/viewer-grid.md` (stav).

**Mimo:** řazení, toggle ikona a persistence volby, jiné grid viewery
(bank, saldo), cell akce/odkazy, inline editace, CSV export,
skupinové řádky na backendu (F1 jen frontend logika — první konzument
saldo).

## Co implementovat

### A. `TableViewer` — nové metody

Přesně dle `docs/viewer-grid.md` §3.1 (signatury, defaulty, docbloky
s odkazem na design doc). Nic abstraktního — zpětná kompatibilita.

### B. `ViewerController`

- `meta()`: `layouts` (odvozené — `list` vždy, `grid` když
  `getGridColumns() !== null`), `defaultLayout` (validace proti
  layouts, fallback `list`), `grid: {columns, showIndex}` jen když
  grid podporován (`showIndex` default true, override
  z `getGridOptions()`).
- `rows()`: parametr `layout` (default `list`); `grid` →
  `renderGridRow()` větev (bez doplňování default ikony — grid ikonu
  nemá), `footer` klíč jen když `page === 0` a `renderGridFooter($search,
  $filters)` vrátí non-null; `layout=grid` na vieweru bez gridu →
  `Response::error('LAYOUT_NOT_SUPPORTED', …, 400)`.
- `detail()` beze změny.

### C. `viewerSpans.js` + badge

- `frontend/src/components/viewer/viewerSpans.js`: `normalizeSpans()`
  přesunout z `ViewerRow.svelte` (chování 1:1).
- Badge render: span s `badge: <style>` → pilulka; paleta = badge
  systém (`shpd-detail__badge--*` vzhled — může být lokální třída
  `shpd-span-badge--{style}` se stejnými tokeny, NEsdílet `:global`,
  viz anti-patterny v design-system.md §6). `badge` má přednost před
  `class`.
- `ViewerRow.svelte`: import z utility, badge render v t1/i1/t2/i2/t3.

### D. `ViewerGrid.svelte`

Props: `columns`, `showIndex`, `rows`, `footer`, `selectedRowId`,
`hasMore`, `loadingRows`, `loadingMore`, `onRowClick`, `onRowDblClick`,
`onScroll` (nebo scroll handling uvnitř — drž konzistenci
s `Viewer.svelte` vzorem listu).

- `<table>` ve scroll containeru; `overflow-x: auto`; sloupce: `width`
  px / `grow` (flexibilní) / auto; `align: right` → `tabular-nums`
  hlavička i buňky.
- Sticky `<thead>` (top: 0, bg `--shpd-color-bg`, z-index nad řádky,
  spodní border). Sticky footer `<tr>` (bottom: 0, tučně, horní linka —
  vzhled jako `_class: total` v detail table).
- Index sloupec `#` (frontend-computed, průběžně přes skupiny,
  tabular-nums, muted).
- Stavový proužek: `stateStyle` → `docState_*` na řádku; realizace přes
  ::before na první buňce nebo inset box-shadow (border-left na `<tr>`
  není spolehlivý) — vizuálně shodné s `ViewerRow` (6 px). Výběr =
  accent proužek + `--shpd-color-bg-selected` (+ hover varianty).
- `rowClass: 'error'` → podbarvení jako error řádky v detail table.
- Skupinové hlavičky: vlož `<tr>` s colspan přes všechny sloupce, když
  `rows[i].group?.key !== rows[i-1]?.group?.key` a group není null
  (design doc §5).
- Prázdný stav / loading / end-of-list stavy — stejné texty a spinner
  jako list (`common.loading`, `common.empty`, `viewer.endOfList`).

### E. `ViewerDetailDrawer.svelte`

- Non-modální panel `position: fixed` vpravo, šířka 560 px (mobil se
  neřeší — drawer se na mobilu nepoužívá), stín + levý border, slide-in
  transition. **Bez overlay** — grid pod ním zůstává interaktivní.
- Hlavička: akce z `detailToolbar` (prop `actions`; filtruj `create` —
  stejný filtr jako mobile top bar ve `Viewer.svelte`) + ✕.
- Obsah: `<ViewerDetail {detail} {loading} {onRefresh} {onAction} />`.
- Esc: zavírá drawer **jen když není otevřený žádný Modal** — Modal.svelte
  má stack; exportuj z něj helper (např. `isModalOpen()`) nebo použij
  existující mechanismus. Nezaváděj vlastní paralelní stack.
- Zavření = callback `onClose` → `Viewer.svelte` nastaví
  `selectedRowId = null`, `detail = null`.

### F. `Viewer.svelte` — zapojení

- `activeLayout` $state; po `fetchMeta()` nastav
  `meta.defaultLayout ?? 'list'`.
- Efektivní layout: `layoutStore.isMobile ? 'list' : activeLayout`
  (a `'list'` když meta grid nepodporuje). Drž jako `$derived`.
- `fetchRowsExplicit(...)` + všechna volání: nový parametr `layout`
  (posílej `&layout=grid` jen pro grid; list beze změny query).
  Footer z odpovědi ulož do `$state footer` (jen non-append fetch;
  append footer nemění).
- Změna efektivního layoutu po načtení meta (resize přes breakpoint):
  reset page + refetch s novým layoutem — přes `$effect` sledující
  JEN efektivní layout, fetch s explicitními parametry (viz disciplína
  v souboru). Pozor na dvojí fetch při mountu — inicializační fetch
  jde ze stávajícího tab-change `$effect`u; layout-change efekt nesmí
  střílet na první nastavení hodnoty (guard přes předchozí hodnotu).
- Render: efektivní layout `grid` → `ViewerGrid` přes celou šířku
  body + `ViewerDetailDrawer` (otevřený když `selectedRowId != null`);
  `list` → stávající markup beze změny. ViewGroup taby, search,
  `ViewerFilters` a series taby zůstávají nad/pod gridem stejně jako
  u listu (jen list-panel šířka 400px neplatí — grid je full-width).
- Dvojklik na grid řádek → `edit` akce jen pokud je v `detailToolbar`
  (u JournalVieweru není — nic se nestane).
- Mobil: beze změny (efektivní layout `list` → stávající chování vč.
  top baru).

### G. `JournalViewer` — pilot

- `getDefaultLayout()`: `'grid'`.
- `getGridColumns()` (labely `$this->language === 'cs'` větvení jako
  v `renderDetail()`):
  `accounting_date` „Datum" (96) · `doc_number` „Doklad" ·
  `account_number` „Účet" (80) · `money_dr` „MD" (110, right) ·
  `money_cr` „DAL" (110, right) · `payment_reference` „VS" (130) ·
  `partner_name` „Osoba" · `text` „Text" (grow).
- `renderGridRow()`: formátování přes stávající helpery; `money_dr`/
  `money_cr` jen když nenulové (`class: 'amount'`); u cizoměnového
  řádku přidej do částky druhý span `{text: '1 234,00 EUR',
  class: 'muted'}` (pole spanů v buňce); `doc_number`
  `class: 'primary'`; `rowClass: 'error'` když `is_error`;
  `stateStyle` null (proužek u deníku nedává informaci, error řeší
  rowClass).
- `renderGridFooter()`: `SELECT SUM(money_dr), SUM(money_cr)` se
  **stejnou** WHERE skladbou jako `selectRows()` (vytáhni skladbu
  podmínek do privátní metody, ať se neduplikuje); vrací
  `['accounting_date' => 'Σ', 'money_dr' => …, 'money_cr' => …]`
  (částky `class: 'amount'`).
- `renderRow()` a `renderDetail()` beze změny — list zůstává mobilním
  formátem deníku.

### H. i18n

Nové klíče (cs + en, `npm run check:i18n`): `viewer.drawer.close`
(aria ✕). Ostatní texty se recyklují. Column labely jdou z backendu.

## Testy

PHPUnit — pouštěj s úzkým `--filter` (široké filtry způsobují
timeouty):

- `TableViewerGridDefaultsTest` (nebo do stávajícího viewer testu):
  defaulty nových metod (list-only viewer → getGridColumns null,
  defaultLayout list).
- `ViewerController`: meta list-only vieweru nemá `grid` klíč a má
  `layouts: ['list']`; meta grid vieweru má oba layouty + columns;
  `rows?layout=grid` vrací `cells` tvar; `layout=grid` na list-only →
  `LAYOUT_NOT_SUPPORTED`; footer jen na page 0. (Vzor mockování dle
  existujících controller testů.)
- `JournalViewerTest` rozšířit: `getGridColumns()` tvar (ids, align);
  `renderGridRow()` — mapování, error rowClass, cizoměnový span,
  prázdné MD/DAL; footer SQL podmínky sdílené se selectRows (aspoň
  přes extrahovanou metodu).

Frontend bez testové infrastruktury — ověření manuálně (viz Hotovo
když).

## Commit strategie

1. `viewer: grid layout backend (TableViewer + ViewerController)` —
   A + B + PHP testy infra.
2. `viewer: shared span utility + badge variant` — C.
3. `viewer: ViewerGrid + detail drawer + Viewer wiring` — D + E + F + H.
4. `accounting: JournalViewer grid layout (pilot)` — G + testy.
5. `docs: viewer grid phase 1` — `docs/frontend.md` §7 (odstavec +
   odkaz na `docs/viewer-grid.md`), stav v `docs/viewer-grid.md`.

## Hotovo když

- [ ] Existující viewery (Osoby, pošta, úkoly, faktury) fungují beze
      změny — list layout, žádný nový klíč v meta kromě
      `layouts: ["list"]` + `defaultLayout`.
- [ ] Deník se na desktopu otevře jako tabulka: sticky hlavička,
      infinite scroll, pořadová čísla, error řádky podbarvené,
      cizoměnové částky s kódem měny.
- [ ] Footer ukazuje Σ obratů MD/DAL přes celý filtrovaný set a mění se
      s filtry/hledáním (ne jen načtené řádky).
- [ ] Klik na řádek vysune drawer s existujícím detailem deníku;
      odkaz „zdrojový doklad" (open_viewer) funguje; klik na jiný řádek
      přepne detail; Esc/✕ zavře; Esc se správně chová s otevřeným
      modálem nad drawerem.
- [ ] Stávající filtry deníku (rok/měsíc, účet, partner, VS, jen chyby)
      i fulltext fungují v gridu beze změny.
- [ ] Na mobilu (≤ 768 px) deník ukazuje stávající list layout a
      list/detail přepínání; resize přes breakpoint korektně
      přerenderuje (refetch, žádný rozbitý tvar řádků).
- [ ] Badge span (`{text, badge}`) se renderuje v gridu i v list
      řádcích (ověř dočasnou úpravou libovolného renderRow nebo testem).
- [ ] `npm run check:i18n` prochází; PHPUnit zelené (úzké filtry);
      `git diff` zkontrolován.
