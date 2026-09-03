# Sub-tabulky ve formulářích — fáze 1: sloupce ze serveru, read-only prohlížení, potvrzovací modal, filtr

**Stav:** naplánováno — design schválen 2026-09-03 (issue #53), neimplementováno

## Kontext / Cíl

Issue **shipard/shpd#53** (Editace řádků dokladů). Sebik navrhuje místo
inline editace cestu „přehledná tabulka řádků + modální formulář řádku".
Anna potvrdila směr a rozhodla, že řešení musí být **univerzální** — stejný
mechanismus používají Osoby (Kontakty, Adresy, Bankovní účty), Účetní
roky (měsíce) a Registrace DPH (období).

Dnešní stav (`frontend/src/components/form/FormSubTable.svelte`, 231 ř.):

- sloupce se odvozují z **prvních 5 klíčů prvního záznamu** (`deriveColumns`,
  ř. 36–43) — surové názvy DB sloupců, FK jako čísla, žádné formátování;
- tlačítka ✎ / ✕ jsou textové znaky, mazání přes nativní `window.confirm`;
- když je rodič `disabled` (read-only doklad), tlačítka zmizí úplně → řádek
  nejde ani otevřít a prohlédnout (ř. 122–127);
- `DocRowsForm` nemá vlastní `doc_states`, read-only stav hlavičky se do
  sub-formuláře nepropaguje — kdyby se dialog otevřel, byl by editovatelný.

Cíl fáze 1: sub-tabulka vypadá a chová se jako plnohodnotná tabulka řádků
(pojmenované, formátované, zarovnané sloupce definované serverem), řádky
read-only dokladu jdou otevřít k prohlédnutí, mazání potvrzuje vlastní
modal, dlouhé seznamy (adresy z registru) jdou filtrovat.

Fáze 2 (`subtable-phase2.md`) řeší dialog řádku (Předchozí/Další, Uložit a
pokračovat, menší modal). Fáze 3 (`subtable-phase3.md`) přesun řádků a
automatické `order_pos`.

## Rozhodnutí k designu

- ✓ **Sloupce definuje server**, ne klientská heuristika. Specifikace má
  stejný tvar jako `TableViewer::getGridColumns()` (`docs/viewer-grid.md`
  §3.1), aby šla sub-tabulka v budoucnu povýšit na plný grid bez přepisu.
- ✓ **Buňky renderuje server** — FK, enumy a částky přicházejí jako hotové
  texty (vzor `DocsHeadsViewer::buildDetailRows()`, ř. 685–724). Klient
  neví nic o `core_units` ani `economy_items`.
- ✓ **Renderer žije na rodičovském formuláři** (`DocsHeadsFormBase`,
  `PersonsForm`), protože sloupce závisí na kontextu rodiče (doklad bez DPH
  nemá DPH sloupce). `TableForm` má rozumný default odvozený
  z `TableDefinition` dětské tabulky — formuláře bez overridu (Účetní roky,
  Registrace DPH) dostanou pojmenované sloupce zdarma.
- ✓ **Read-only prohlížení:** `disabled` rodiče → v tabulce zůstane jen
  „Zobrazit", dialog řádku se otevře s vypnutými poli a bez Uložit.
- ✓ **Vlastní potvrzovací modal** místo `window.confirm` — nová komponenta
  `ui/ConfirmDialog.svelte`, v této fázi nasazená jen v sub-tabulce.
- ✓ **Klientský filtr** nad tabulkou, zobrazí se automaticky od 11 řádků,
  filtruje přes texty vyrenderovaných buněk. Gridový prohlížeč se
  serverovým hledáním NENÍ v plánu (zapsáno v `tasks/TODO.md`), dokud
  filtr nepřestane stačit.
- ✓ Žádná backward compatibility — `deriveColumns` a `EXCLUDE_COLS` se
  mažou, ne obalují.

## Před implementací přečti

- issue #53 — původní návrh + komentář s rozhodnutími
- `frontend/src/components/form/FormSubTable.svelte` — celý
- `frontend/src/components/form/FormDialog.svelte` — props, `handleSaved`
  (`hasDocStates` větev, ř. 66–80), `handleClose` s `window.confirm`
- `frontend/src/components/form/FormEditor.svelte` — `isDisabled`
  (ř. 52–54), `isDirty` (ř. 73–77), `<FormStateBar>` (ř. 537–544)
- `frontend/src/components/form/FormStateBar.svelte` — `showSave`
- `frontend/src/components/ui/Modal.svelte` — props `width`/`height`,
  modal stack a depth-shrink
- `frontend/src/components/viewer/ViewerGrid.svelte` — tvar `columns`
  (`id`, `label`, `align`, `grow`, `width`), třídy pro zarovnání; NEPOUŽÍVAT
  přímo (infinite scroll, sort, state strip nepatří do formuláře), jen jako
  vzor tvaru dat a CSS
- `frontend/src/components/viewer/DocumentDetail.svelte` ř. 189–230 —
  jak vypadá dnešní tabulka řádků v prohlížeči; cílový vzhled
- `src/Core/Form/TableForm.php::subtableTab()` (ř. 109–130),
  `src/Core/Form/FormTab.php` (`toArray()` ř. 123–135)
- `src/Api/Controller/FormController.php` — `meta()` (jak se načítá parent
  záznam a staví form), `resolveFormDefinition()` ř. 392–420
- `src/Api/Router.php::resolveFormRoute()` ř. 1108–1170 a
  `public/index.php::dispatchForm()` ř. 1355–1382 — kam přidat routu
- `modules/docs/core/src/DocsHeadsFormBase.php::buildRowsTab()` ř. 474–483,
  `vatAgendaDisabled()`, `homeCurrency()`
- `modules/docs/core/src/DocsHeadsViewer.php::buildDetailRows()` ř. 685–724
  — JOINy na `core_units`, `economy_items`, formátování
- `modules/docs/core/src/DocRowsForm.php` — `hasRowSideLayout()`
  (kontační řádky mají jiný layout → jiné sloupce)
- `modules/base/persons/src/PersonsForm.php` ř. 74–76 + JSONC tabulek
  `modules/base/persons/tables/base_persons_{contacts,addresses,bank_accounts}.jsonc`
  (názvy sloupců pro výběr; **zdroj pravdy je JSONC, ne docs**)
- `src/Core/Database/ColumnDefinition.php` — `formLabel`, `type`, `cfgItem`,
  `reference` pro default renderer
- `frontend/src/icons.js` — jak se registrují FA ikony
- `docs/edit-forms.md` — kapitola o sub-formulářích (aktualizovat na konci)

## Krok 1 — kontrakt: specifikace sloupců a vyrenderované řádky

Nový endpoint **`GET /_ui/form/{parentTable}/subtable/{tabId}/{parentId}`**
(`Router::resolveFormRoute` → `Route('form', 'subtable', $table, $parentId)`
s `tabId` v novém poli Route nebo přes query; `dispatchForm` →
`FormController::subtable()`).

Response:

```jsonc
{
  "success": true,
  "data": {
    "columns": [
      { "id": "order_pos",   "label": "#",        "align": "right", "width": 40 },
      { "id": "description", "label": "Popis",    "grow": true },
      { "id": "quantity",    "label": "Množství", "align": "right" },
      { "id": "unit",        "label": "MJ" },
      { "id": "unit_price",  "label": "Cena/MJ",  "align": "right" },
      { "id": "vat_pct",     "label": "DPH %",    "align": "right" },
      { "id": "total_price", "label": "Celkem",   "align": "right" }
    ],
    "rows": [
      { "id": 4711, "cells": { "order_pos": "1", "description": "Ukázková položka",
        "quantity": "2", "unit": "ks", "unit_price": "1 000,00", "vat_pct": "21",
        "total_price": "2 000,00" } }
    ],
    "order_column": null   // fáze 3: "order_pos" → zobrazí šipky
  }
}
```

- Buňka je `string` nebo `{ text, class? }` (stejný tvar jako span ve
  `ViewerGrid`, bez badge). Chybějící klíč = prázdná buňka.
- Řádky jsou seřazené serverem podle `sort` z `subtableTab()` (default
  `order_pos:asc`, jinak `id:asc`).
- `order_column` je v této fázi vždy `null`; klíč se zavádí teď, aby fáze 3
  neměnila kontrakt.

Controller: načte parent `TableDefinition` + parent záznam (stejně jako
`meta()` s `$id`), vytvoří parent form přes `FormRegistry::createForm()`,
zavolá `buildFormDefinition($data, false)` a v `tabs` najde tab
`$tabId` typu `subtable` → z něj `table`, `foreign_key`, `sort`. Načte
řádky dětské tabulky `WHERE fk = parentId ORDER BY …` a předá
`$parentForm->renderSubtable($tabId, $rows, $data)`. Parent bez PHP formu
(JSONC / auto) nebo neexistující tab → 404. Přístup: stejná autorizace jako
`meta` (čtení).

## Krok 2 — `TableForm`: default renderer

Do `src/Core/Form/TableForm.php`:

```php
/** @return array{columns: list<array>, rows: list<array>, order_column: ?string} */
public function renderSubtable(string $tabId, array $rows, array $parentData): array
```

Default implementace:

- sloupce: prvních 6 sloupců dětské `TableDefinition` (načíst přes tables
  registry podle `table` z tabu), vynechat `id`, FK na rodiče,
  `docState*`, `created`, `modified`, sloupce s `system: true`
  a `sensitive: true`; `label` = `formLabel ?? name` (lokalizace stejně
  jako `AutoFormBuilder`);
- buňky podle `ColumnDefinition::type`: číselné typy → zarovnat vpravo,
  formát s desetinnými místy dle `scale`; `cfgItem` → text z compiled
  configu; `reference` → `displayPattern` odkazované tabulky jen pokud to
  jde bez dalšího dotazu na řádek (jinak surové id — default má být levný);
  boolean → „Ano"/„Ne" (i18n); ostatní string.

Sdílené formátování čísel/částek vytáhnout do pomocné třídy
(např. `src/Core/Form/SubtableCellFormatter.php`), aby `DocsHeadsFormBase`
i default sdílely stejný formát a nevznikl čtvrtý privátní `formatMoney()`
(dnes existují v `JournalViewer`, `BankStatementsViewer`,
`BankTransactionsViewer`, `LedgerViewer`, `DocsHeadsViewer` — jejich
sjednocení je MIMO rozsah, jen nepřidávat další kopii).

Unit test `tests/Unit/Core/Form/TableFormSubtableTest.php`: default
sloupce z fake `TableDefinition` (vynechání FK/system/sensitive, limit 6,
labely), formátování čísla/boolean/cfgItem.

**Commit 1:** `feat(forms): endpoint /subtable a default renderer sub-tabulek`

## Krok 3 — `DocsHeadsFormBase::renderSubtable('rows', …)`

Override v `modules/docs/core/src/DocsHeadsFormBase.php`:

- **Položkové řádky** (výchozí sada): `#` (`order_pos`), Popis
  (`description`; textový řádek `row_kind = 0` s třídou pro kurzívu/tlumenou
  barvu a bez číselných buněk), Množství, MJ (`core_units.shortcut`),
  Cena/MJ, Bez DPH (`vat_base`), DPH % (`vat_pct`), DPH (`vat_amount`),
  Celkem (`vat_total`).
- **Doklad bez DPH** (`vat_mode = 0` rodiče): sloupce DPH %, DPH, Bez DPH
  vypadnou; Celkem = `total_price`.
- **Kontační řádky** (doklad, jehož operace mají `rowSide` layout — zjistit
  přes `hasRowSideLayout()` / `resolveOperationAttrs()` z `DocRowsForm`,
  případně tuto logiku přesunout do sdílené třídy): sada `#`, Operace
  (label z `docs.core.rowOperations`), Účet, Popis, Strana (MD/DAL),
  Částka. Jak přesně zjistit „doklad je kontační" ověř v kódu — pokud to
  není z hlavičky jednoznačné, rozhodni per řádek (řádek s `rowSide`
  operací se renderuje do sady K, ostatní do položkové sady, a sloupce
  se sjednotí sjednocením obou sad). Napiš, k čemu jsi došel, do
  komentáře metody.
- JOINy vzít z `DocsHeadsViewer::buildDetailRows()` — units, items
  (pro budoucí odkaz na položku; v této fázi jen text). Nedělat N+1 dotazů:
  jeden dotaz s JOINy nad ids řádků, nebo `WHERE doc_head = …` znovu
  (řádky už máš, ale JOIN je levnější než N lookupů).
- Měna: částky bez symbolu měny (měna je v hlavičce dokladu).

Test `modules/docs/core/tests/…` nebo `tests/Unit/…` dle existujícího
umístění testů modulu docs: renderer nad fake řádky (fiktivní popisy,
kulaté částky) — s DPH, bez DPH, textový řádek.

**Commit 2:** `feat(docs): sloupce sub-tabulky řádků dokladu ze serveru`

## Krok 4 — `PersonsForm::renderSubtable()`

Override pro tři taby. Konkrétní sloupce vyber podle JSONC tabulek
(**ověř názvy, nehádej**), orientačně:

- `contacts`: typ kontaktu (cfgItem label), hodnota, poznámka;
- `addresses`: typ adresy, ulice a číslo, město, PSČ, země (kód nebo název
  přes referenci), příznak hlavní/fakturační pokud existuje;
- `bank_accounts`: číslo účtu / IBAN, banka, měna, příznak hlavní.

Pokud tyto tabulky mají `docState` (Adresy ano — `core.system.docStatesArchive`),
archivované řádky renderovat s třídou pro tlumený text; NEskrývat je
(uživatel je potřebuje najít a odarchivovat).

**Commit 3:** `feat(persons): sloupce sub-tabulek kontaktů, adres a účtů`

## Krok 5 — `FormSubTable.svelte`: nový render, read-only, filtr

- `fetchRows()` volá nový endpoint místo `GET /{table}?filter…`; drží
  `columns`, `rows`, `orderColumn`.
- Tabulka: hlavička z `columns`, zarovnání a `grow` přes třídy (převzít
  CSS vzor z `ViewerGrid`, ne komponentu), `width` jako inline style.
  Sticky hlavička v rámci scroll oblasti tabu.
- Akce v řádku jako ikonové `Button iconOnly size="sm"`:
  - editovatelný rodič: Upravit (`faPen`), Smazat (`faTrash`);
  - `disabled` rodič: jen Zobrazit (`faEye`). Ikony doplnit do
    `frontend/src/icons.js` podle existujícího vzoru; ověřit `node -e …`
    (viz konvence v paměti / `docs/`).
  - Dvojklik na řádek = Upravit / Zobrazit.
- Přidat: `Button` vlevo v toolbaru (dnes vpravo), skrytý při `disabled`.
- **Filtr:** `Input` vpravo v toolbaru, renderovaný jen když
  `rows.length > 10`; filtruje `rows` podle `includes` (case-insensitive,
  bez diakritiky — použij existující normalizační helper, je-li ve
  `frontend/src/utils/`, jinak `normalize('NFD').replace(/\p{M}/gu,'')`)
  přes texty všech buněk. Stav „nic nenalezeno" s vlastním textem.
  Filtr se resetuje při změně `parentId`.
- Read-only propagace: `<FormDialog … readOnly={disabled}>` (krok 6).
- Odstranit `deriveColumns`, `EXCLUDE_COLS`, `MAX_COLS`.
- `data-testid`: `subtable`, `subtable-add`, `subtable-filter`,
  `subtable-row` (s `data-row-id`), `subtable-row-edit`, `subtable-row-delete`,
  `subtable-row-view` — pro video-runner a E2E.
- i18n (`cs.js`, `en.js`): `subtable.filterPlaceholder`,
  `subtable.noMatch`, `common.view` („Zobrazit"), `common.yes`/`common.no`
  pokud chybí.

## Krok 6 — read-only dialog: `FormDialog` → `FormEditor` → `FormStateBar`

- `FormDialog`: nový prop `readOnly?: boolean` (default false), předá do
  `FormEditor`.
- `FormEditor`: prop `readOnly`; `isDisabled = saving || recalculating ||
  readOnly || doc_states.read_only`; `isDirty` vrací `false` při
  `readOnly`; `FormStateBar` dostane `readOnly` → `showSave = false` a
  `transitions = []` (u read-only dokladu nesmí jít z řádku spouštět
  přechody sub-záznamu).
- Titulek dialogu v read-only režimu beze změny (formulář sám je stejný),
  jen se v hlavičce zobrazí existující `ReadOnlyBanner`/badge — ověř, co
  `Modal`/`FormDialog` už umí, a použij to; nový vizuální prvek nevymýšlej.
- Lookup pole (`LookupInput`) v disabled stavu nesmí otevírat vyhledávání
  ani `editForm` — ověř `FormElement`/`LookupInput`, jak reagují na
  `disabled`.

## Krok 7 — `ui/ConfirmDialog.svelte`

Nová komponenta nad `Modal`:

```svelte
<ConfirmDialog
  open
  title={t('subtable.deleteTitle')}
  message={t('subtable.confirmDelete')}
  confirmLabel={t('common.delete')}
  variant="danger"
  onConfirm={…}
  onCancel={…}
/>
```

- Šířka 480px, bez `height`; tlačítka Zrušit (secondary) / potvrzovací
  (`variant` → `primary` | `danger`); Enter = potvrdit, Esc = zrušit
  (Esc řeší `Modal` přes stack — ověř, že se nezavře i rodičovský dialog).
- Nasadit v `FormSubTable.handleDelete`. Ostatní 4 výskyty `window.confirm`
  (`AttachmentPanel`, `FormDialog`, `ViewerDetail`, `Viewer`) jsou MIMO
  rozsah této fáze — `FormDialog` řeší fáze 2, zbytek zapsat do
  `tasks/TODO.md`.

**Commit 4:** `feat(forms): sub-tabulka se sloupci ze serveru, read-only prohlížení, ConfirmDialog, filtr`

## Krok 8 — dokumentace a TODO

- `docs/edit-forms.md`: kapitola o sub-tabulkách — kontrakt endpointu,
  `renderSubtable()` override, default renderer, read-only chování.
- `tasks/TODO.md`: (a) gridový prohlížeč se serverovým hledáním pro
  sub-tabulky, pokud klientský filtr přestane stačit; (b) nahradit
  zbývající `window.confirm` za `ConfirmDialog`; (c) sjednotit privátní
  `formatMoney()` napříč viewery.
- `tasks/subtable-phase1.md`: hlavička `**Stav:**` → `hotovo` ve stejném
  commitu jako poslední kód.

**Commit 5:** `docs(forms): sub-tabulky — kontrakt a renderer`

## Hotovo když (E2E na dev DS s fiktivními daty)

1. Přijatá faktura ve stavu koncept, tab Řádky: tabulka má pojmenované
   sloupce, částky zarovnané vpravo, MJ jako zkratka, textový řádek bez
   čísel a vizuálně odlišený. Přidat / Upravit / Smazat fungují, Smazat
   otevře `ConfirmDialog`, po potvrzení se tabulka i součty v hlavičce
   (tab Základní → Součty) přepočítají.
2. Doklad bez DPH: sloupce DPH chybí.
3. Účetní doklad s kontačními řádky: sada K (operace, účet, strana, částka).
4. Faktura ve stavu read-only (např. zaúčtovaná): Přidat a Smazat chybí,
   u řádku je Zobrazit, dialog se otevře s vypnutými poli, bez Uložit a bez
   přechodů; Esc / křížek zavře bez dotazu na neuložené změny.
5. Osoba s > 10 adresami (fiktivní, nasekat ručně nebo přes `DEMO`
   mechanismus z issue #40): objeví se filtr, zadání části města zúží
   seznam, smazání filtru vrátí vše; archivovaná adresa je tlumená.
6. Osoba: Kontakty a Bankovní účty mají smysluplné sloupce.
7. Účetní roky → měsíce (bez overridu): default sloupce s labely, žádné
   surové `fiscal_year` FK ani `id`.
8. `cd frontend && timeout 90 npm run build 2>&1 | tail -4` bez chyb;
   `php -l` na dotčené soubory; `vendor/bin/phpunit --filter Subtable`.
9. `python3 scripts/tasks-index.py --check && python3 scripts/check-sensitive.py`
   projdou.

## Pasti

- **Parent form pro renderer potřebuje parent data.** `createForm($table,
  $data, …)` dostává data záznamu — bez nich `DocsHeadsFormBase` neví
  `vat_mode` ani měnu. Controller musí parent záznam načíst stejně jako
  `meta($id)`.
- **`buildFormDefinition()` u dokladů dělá další dotazy** (recap, snapshoty,
  options). Pro `subtable` endpoint je to zbytečná zátěž — pokud se ukáže
  drahé, zaveď na `TableForm` lehčí `findSubtableSpec($tabId)`, který
  `buildRowsTab()` sdílí; nesplňuj tím fázi předčasně — nejdřív změř.
- **Řádky textového typu** mají `quantity`/`unit_price` = NULL nebo 0;
  renderovat prázdno, ne „0,00".
- **Nesahej na `handleDialogSaved` / `hasDocStates` logiku** — chování po
  Uložit řeší fáze 2. V této fázi se dialog chová jako dnes.
- **`disabled` vs. `readOnly`:** `disabled` na `FormSubTable` dnes znamená
  „rodič se právě ukládá NEBO je read-only" (`isDisabled` ve `FormEditor`
  zahrnuje `saving`). Během ukládání rodiče se tedy na chvíli přepnou
  tlačítka na Zobrazit — přijatelné, ale ověř, že to neblikne rušivě;
  případně rozliš dva propy (`disabled`, `readOnly`) už teď.
- **Citlivá data:** do testů, docs a issue jen fiktivní popisy, částky
  a osoby. Dev DS pro E2E, alfa jen read-only kontrola SQL, nikdy ne
  screenshoty reálných řádků.
- **JSONC tabulek** má `//` komentáře a trailing commas — při čtení z Pythonu
  použij regex strip (viz paměť / `scripts/`).
- **Modal stack:** `ConfirmDialog` nad `FormDialog` nad hlavním dialogem =
  hloubka 2–3. Depth-shrink zmenší i ConfirmDialog — ověř, že 480 px
  minus 2×60 px je pořád použitelné, jinak `ConfirmDialog` z depth-shrinku
  vyjmi (nový prop `Modal.fixedSize`).
