# Viewer grid — Fáze 2: řazení + toggle + BankTransactionsViewer

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/viewer-grid.md` —
> závazné jsou §7.1 (řazení, D9), §7.2 (toggle, D10), §7.3 (bank pilot,
> D11) a kontrakt D12 v §5. Staví na hotové F1
> (`tasks/viewer-grid-phase1.md`) — grid infrastruktura, drawer,
> `JournalViewer` pilot.

## Kontext

F1 dodala grid layout s pevným řazením a bez možnosti přepnutí zpět na
list. F2 doplňuje tři věci: řazení klikem na hlavičku (server-side,
whitelist přes `sortable` sloupce), toggle list ↔ grid s localStorage
persistencí, a druhý pilot `BankTransactionsViewer` — první
**editovatelný** grid (editace beze změny přes stávající mechaniku:
Open/dvojklik → FormDialog, stavové přechody, drawer akce).

## Cíl

1. Řazení: `sortable` v column meta, `sort=<colId>:<asc|desc>` parametr,
   `TableViewer::setSort()` + `buildSortedOrderBy()` helper, třídicí UI
   v `ViewerGrid` (cyklus asc → desc → výchozí, indikátor ↑/↓).
2. Toggle: ikona vedle searche (desktop, `layouts.length > 1`),
   persistence `shpd_viewer_layout` (per-DS, mapa viewerId → layout).
3. `BankTransactionsViewer`: grid default (7 sloupců, badge Zaúčtování,
   stavový proužek z `docStateMain`), sortable Datum + Částka,
   bez footeru (D11).
4. `JournalViewer`: doplnit `sortable` na Datum / Účet / MD / DAL.

## Návaznost

- **Prerekvizita:** F1 hotová (merged).
- **Odemyká:** saldokonto grid (accbal Fáze 4) — tam platí kontrakt D12
  (řazení nesmí rozbít clustering skupin).

## Před implementací přečti

- `docs/viewer-grid.md` §7.1–§7.3 + D9–D12 v tabulce rozhodnutí
- `src/Core/Viewer/TableViewer.php` — kam přijde `$sort` /
  `setSort()` / `buildSortedOrderBy()`; vzor docbloků z F1 metod
- `src/Api/Controller/ViewerController.php` — `rows()`: parsování
  `layout`, sem přibude `sort` (validace + injekce před `selectRows()`)
- `frontend/src/components/viewer/ViewerGrid.svelte` — hlavička
  (`shpd-grid__th`), sem přijde sort UI
- `frontend/src/components/viewer/Viewer.svelte` — `activeLayout` +
  layout-change `$effect` + `prevLayout` sentinel (toggle ho recykluje);
  `fetchRowsExplicit` (přibude `sort` parametr); disciplína `$effect`
  (komentáře v souboru)
- `frontend/src/api/config.js` — export `DATA_SOURCE_ID` (per-DS
  storage klíč)
- `frontend/src/stores/theme.svelte.js` ř. ~48 — `storageKey` vzor
  (jen jako reference, NEimportovat odtud)
- `modules/economy/bank/src/BankTransactionsViewer.php` — celý;
  POZOR: docblock třídy tvrdí „LIST nejoinuje", ale `selectRows()` už
  `base_persons_persons` joinuje (kvůli hledání v partner full_name) —
  docblock při té příležitosti oprav
- `modules/economy/accounting/src/JournalViewer.php` — F1 vzor
  (`getGridColumns`, `renderGridRow`, `gridAmountCell`)
- `tests/Unit/Api/Controller/ViewerControllerLayoutTest.php` +
  `tests/Unit/Module/Economy/Accounting/JournalViewerTest.php` — vzory
- `frontend/src/icons.js` — registr ikon (toggle potřebuje list/table
  ikony; `iconTable` existuje, list ikonu případně přidej dle postupu
  v `docs/frontend.md` §10)

## Scope

**Uvnitř:** setSort/buildSortedOrderBy + controller sort parsing;
sort UI v ViewerGrid; sort stav ve Viewer.svelte; toggle tlačítko +
`utils/viewerLayout.js` persistence; BankTransactionsViewer grid;
JournalViewer sortable sloupce; testy; i18n; aktualizace
`docs/frontend.md` §7 a stavu v `docs/viewer-grid.md`.

**Mimo:** footer pro bank (D11 — mix měn), saldokonto grid, server-side
persistence volby layoutu, řazení v list layoutu, cell akce, CSV export.

## Co implementovat

### A. `TableViewer` — sort

```php
/** Aktivní řazení injektované controllerem, nebo null (výchozí). */
protected ?array $sort = null;   // {column: string, dir: 'asc'|'desc'}

public function setSort(?array $sort): void;

protected function buildSortedOrderBy(array $columnMap, string $default, string $uniqueTail): string;
```

`buildSortedOrderBy`: když `$this->sort` je aktivní a `column` je klíč
v `$columnMap` → `"{expr} {DIR}, {$uniqueTail} {DIR}"`; jinak vrací
`$default` beze změny. `$uniqueTail` se připojuje VŽDY (deterministické
stránkování). Směr uniqueTail = směr sortu (stabilní keyset chování).
Docblok s odkazem na design §7.1.

### B. `ViewerController::rows()` — sort parsing

- Parametr `sort` (string `<colId>:<asc|desc>`) čti **jen pro
  `layout=grid`**.
- Validace: colId musí mít `sortable: true` v `getGridColumns()`,
  dir ∈ {asc, desc}. Nevalidní → tiše ignorovat (`setSort(null)` /
  nevolat), žádná chyba — padá na výchozí řazení.
- Validní → `$viewer->setSort(['column' => $colId, 'dir' => $dir])`
  PŘED `selectRows()` (a před `renderGridFooter()` — footer sort
  nepoužívá, ale pořadí volání drž kvůli přehlednosti).

### C. `ViewerGrid.svelte` — sort UI

- Nové props: `sort` (`{column, dir} | null`), `onSortChange(colId)`.
- Sortable `th`: kurzor pointer, klik volá `onSortChange(col.id)`;
  indikátor ↑ (asc) / ↓ (desc) u aktivního sloupce (CSS/unicode, žádná
  nová ikona). Non-sortable hlavičky beze změny.
- Cyklus počítá `Viewer.svelte` (viz D), grid jen hlásí klik.
- `aria-sort` na aktivní `th` (accessibility zdarma).

### D. `Viewer.svelte` — sort stav + toggle

**Sort:**
- `activeSort` $state (`{column, dir} | null`); `handleSortChange(colId)`
  cykluje asc → desc → null (jiný sloupec = začni asc); reset page,
  refetch. Výběr/drawer NErušit (řazení nemění identitu záznamů).
- `fetchRowsExplicit`: nový explicitní parametr `sort`; do query
  `&sort=<col>:<dir>` jen když non-null a layout grid. Prošít všechna
  volání (vzor F1 s `layout`).
- Reset `activeSort = null`: při přepnutí vieweru (tab-change $effect)
  a při změně efektivního layoutu na list (layout-change $effect).
  Přežívá viewGroup/filtry/hledání (design §7.1).

**Toggle:**
- `frontend/src/utils/viewerLayout.js`: `getViewerLayout(viewerId)` /
  `setViewerLayout(viewerId, layout)` — localStorage klíč
  `shpd_viewer_layout` per-DS (`DATA_SOURCE_ID` z `api/config.js`,
  stejný vzor jako `storageKey` v theme store — NEsdílet kód, jen vzor;
  try/catch okolo localStorage). Hodnota JSON mapa
  `{viewerId: 'list'|'grid'}`.
- Init `activeLayout` v tab-change flow: `persisted ?? meta.defaultLayout`
  (persisted mimo `meta.layouts` ignoruj). POZOR na `prevLayout`
  sentinel — počítej ho z výsledné hodnoty (vzor F1).
- Toggle tlačítko vedle search inputu (desktop, `layouts.length > 1`,
  ne mobil): ikonové tlačítko (Button `iconOnly`/`ghost`), ikona ukazuje
  CÍLOVÝ layout (v gridu ikonu listu a naopak), `title`/aria z i18n.
  Klik: `activeLayout = opačný`, `setViewerLayout(...)`, `activeSort =
  null` když cíl je list — refetch řeší stávající layout-change $effect
  (nevolat fetch ručně, jinak dvojí fetch).

### E. `BankTransactionsViewer` — grid

- `getDefaultLayout()`: `'grid'`.
- `getGridColumns()` (labely cs/en větvení jako `getFilters()`):
  `date_transaction` „Datum" (96, sortable) · `amount` „Částka" (130,
  right, sortable) · `counterparty_name` „Protistrana" (grow) ·
  `partner_name` „Partner" · `payment_reference` „VS" (120) ·
  `operation` „Operace" (110) · `accounting` „Zaúčtování" (120).
- `renderGridRow()`:
  - `stateStyle` z `docStateMain` přes `DocStateConfig` — stejná logika
    jako `renderRow()` (vytáhni do privátní metody, ať se neduplikuje).
  - `amount`: znaménko dle `direction` (vzor `renderRow()`), span
    `amount` + druhý span kód měny `muted` (vzor `gridAmountCell`
    z JournalVieweru, ale znaménkovaný a měna vždy).
  - `operation`: label z cfgItem přes existující `enumLabel()`.
  - `accounting`: `accounting_state` 1 → `{text: zaúčtováno,
    badge: 'success'}`, 2 → `{text: chyba účtování, badge: 'danger'}`,
    jinak null. Texty cs/en dle `$this->language` (jako `renderRow()`).
  - `rowClass` nepoužívej — chybu nese badge, řádek nečervenat
    (chybových může být hodně, D11).
- `renderGridFooter()`: neimplementovat (default null — D11).
- Sort mapa v `selectRows()`: `buildSortedOrderBy(['date_transaction' =>
  't.`date_transaction`', 'amount' => 't.`amount`'], 'ORDER BY výchozí…',
  't.`id`')` — výchozí řazení `t.docStateMain ASC, t.date_transaction
  DESC, t.id DESC` beze změny. Pozn.: řazení dle `amount` je dle
  absolutní hodnoty sloupce (bez direction znaménka) — dokumentuj
  v docbloku, je to záměr (velikost transakce).
- Oprav zastaralý docblock třídy (JOIN persons v selectRows existuje).

### F. `JournalViewer` — sortable

- `sortable: true` na `accounting_date`, `account_number`, `money_dr`,
  `money_cr`; sort mapa v `selectRows()` přes `buildSortedOrderBy`
  (qualifikované `j.`-výrazy, uniqueTail `j.`id``), výchozí
  `accounting_date DESC, id DESC` beze změny.

### G. i18n

Nové klíče (cs + en): `viewer.layout.showGrid`, `viewer.layout.showList`
(title/aria toggle tlačítka). `npm run check:i18n`.

## Testy

PHPUnit s úzkými `--filter`:

- `TableViewer` sort: `buildSortedOrderBy` — bez sortu vrací default;
  sort mimo mapu vrací default; validní sort skládá expr + dir +
  uniqueTail se směrem.
- `ViewerControllerLayoutTest` rozšířit: `sort` validní → projeví se
  (mock/spy setSort); nevalidní colId / dir / non-sortable sloupec →
  ignorováno; `sort` u `layout=list` → ignorováno.
- `BankTransactionsViewerTest` (nový, vzor JournalViewerTest):
  `getGridColumns` tvar; `renderGridRow` — znaménko dle direction,
  badge zaúčtování 1/2/0, stateStyle z docStateMain, operation label;
  sort mapa (ORDER BY s aktivním sortem vs. výchozí).
- `JournalViewerTest` rozšířit: sortable flagy; ORDER BY se sortem.

Frontend manuálně (Hotovo když).

## Commit strategie

1. `viewer: server-side sorting (setSort + sort param)` — A + B + testy.
2. `viewer: grid column sorting UI + layout toggle` — C + D + G +
   `utils/viewerLayout.js`.
3. `bank: BankTransactionsViewer grid layout` — E + testy.
4. `accounting: JournalViewer sortable columns` — F + testy.
5. `docs: viewer grid phase 2` — `docs/frontend.md` §7,
   stav v `docs/viewer-grid.md`.

## Hotovo když

- [ ] Deník: klik na Datum/Účet/MD/DAL cykluje asc → desc → výchozí,
      indikátor v hlavičce, infinite scroll pokračuje ve zvoleném řazení
      bez duplicit/děr (uniqueTail); footer se sortem nemění.
- [ ] Deník i bank: toggle ikona přepíná grid ↔ list, volba přežije
      reload i přepnutí na jiný viewer a zpět (localStorage per-DS);
      viewer bez gridu (Osoby) toggle nemá.
- [ ] Bank transakce: grid default se stavovým proužkem, badge
      Zaúčtování, znaménkovanou částkou s měnou; viewGroup taby +
      filtr „Jen chyby účtování" + hledání fungují; sort Datum/Částka.
- [ ] Bank editace beze změny: Open na výběru i dvojklik otevřou
      FormDialog, stavové přechody fungují, po uložení se grid
      refreshne; drawer akce (vč. přeúčtování) fungují.
- [ ] Přepnutí na list resetuje sort; mobil vždy list, toggle skrytý.
- [ ] Nevalidní `sort` parametr (ručně sestavený request) tiše padá na
      výchozí řazení — žádná 500.
- [ ] `npm run check:i18n` prochází; PHPUnit zelené (úzké filtry);
      build prochází; `git diff` zkontrolován (vč. opraveného docblocku
      bank vieweru).
