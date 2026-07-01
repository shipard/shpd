# Účetní doklady (cmnbkp) — Fáze 3: UI (Viewer, Form, řádky, sekce Účtárna)

## Kontext

Fáze 2 (hotová) dodala kompletní účetní backend: `cmnbkp` jde přes API
založit, vyrovnat, zaúčtovat (per-řádková identita) a vygenerovat saldo.
Chybí UI — tato fáze zpřístupní účetní doklad v aplikaci:

- per-typ **Viewer** v sekci **Účtárna** (`navSection: accounting`),
- per-typ **Form** hlavičky (lehčí než faktura — bez DPH, partner nepovinný),
- rozšíření sdíleného **`DocRowsForm`** o řádky kontace (účet / položka, strana
  MD/DAL, částka, per-řádková saldo identita).

Jak se řádky v novém UI editují (zjištěno z frontendu): `FormSubTable.svelte`
zobrazí řádky jako **read-only náhledovou tabulku** (Add / Edit / Delete);
edit i přidání otevře **modal** (`FormDialog`) s `DocRowsForm`. Není to inline
editovatelný grid jako starý dvojsloupec „Má dáti / Dal" — proto stranu
zadáme **selectem `acc_side`** (Má dáti / Dal) + jedno pole částky `total_price`
v modalu. (Inline editovatelná mřížka řádků je samostatný větší frontend
úkol — viz Otevřené body.)

## Cíl

Účetní doklad je plně použitelný v UI: v sekci Účtárna je položka „Účetní
doklady", jde založit doklad, přidat řádky kontace obou typů (Účetní zápis =
přímý účet, Účetní položka = z položky) s MD/DAL, partnerem a platebními
symboly, potvrdit do stavu 40 (zaúčtuje + vygeneruje saldo). Faktury v UI
beze změny.

## Návaznost

- **Předchází:** Fáze 2 (backend — engine, předpis, subclass `AccountingDocument`,
  sloupce `account` / `acc_side`, operace `acc.record` / `acc.item`).
- **Navazuje:** Fáze 4 (import ze starého Shipardu).

## Před implementací přečti

- `modules/docs/core/src/DocRowsForm.php` — `buildFormDefinition()`,
  `buildOperationOptions()`, `applyNewRecordDefaults()`, `recalculate()`.
  Tady přibude větev řádku kontace.
- `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php` — vzor per-typ head
  Formu (override `buildHeaderTab` / `buildExtraTabs` / titulky).
- `modules/docs/invoicesIn/src/ReceivedInvoicesViewer.php` — vzor per-typ
  Vieweru (`$scopedDocType`).
- `modules/docs/core/src/DocsHeadsFormBase.php` — háčky head Formu
  (`getFormTitle`, `getDocTypeLabel`, `getHeaderIcon`, `getPartnerSnapshotKey`,
  `buildHeaderTab`, `buildExtraTabs`, `resolveNumberSeriesOptions`,
  `resolveCfgItemOptions`).
- `modules/docs/core/src/DocsHeadsViewer.php` — odvození filtru / spodních
  tabů / new-record defaults z `$scopedDocType`.
- `modules/docs/invoicesIn/module.jsonc` — vzor registrace `viewers` + `forms`
  (mapa `classes`).
- `frontend/src/components/form/FormSubTable.svelte` — jak se řádky renderují
  (náhled + modal). `frontend/src/icons.js` — registr ikon.
- `modules/install/base/config/navSections.jsonc` — sekce `accounting`
  („Účtárna", order 40).

## Scope

UI modulu `docs.accountingDocs` (Viewer + Form), rozšíření `DocRowsForm`
(sdílené v `docs.core`), jeden form-facing atribut operace, registrace v
navigaci, ikona. **Nesahat na:** engine / předpis (Fáze 2 hotová), backend
subclassy kromě nezbytných form hooků, import.

## Co implementovat

### 1. Form-facing atribut operace `rowAccount`

Do `rowOperations.jsonc` k operacím z Fáze 2 přidat `rowAccount` (řídí, jaký
vstup účtu formulář ukáže; je to forma-facing protějšek `accountSrc` z
předpisu — invariant: musí souhlasit):

- `acc.record` → `"rowAccount": "direct"` (vstup `account`)
- `acc.item` → `"rowAccount": "item"` (vstup `item`)

Doplnit do hlavičkového komentáře cfgItem.

### 2. `DocRowsForm` — větev řádku kontace

V `buildFormDefinition()` rozpoznat **kontační řádek**: operace má atribut
`rowAccount`. Pro takový řádek:

- **skrýt** položkový blok: `quantity`, `unit`, `unit_price`,
  `price_calc_mode`, sleva, DPH;
- **zobrazit**:
  - účet: `rowAccount === "direct"` → `lookup('account', table:
    'economy_accounting_accounts', …)`; `=== "item"` → stávající
    `lookup('item', …)` (omezit na položky typu „Účetní položka");
  - `select('acc_side', options: resolveCfgItemOptions('docs.core.accSides'),
    required)` — Má dáti / Dal;
  - `number('total_price', required)` — částka (na zvolené straně);
  - `input('description')` — text řádku;
  - **podle vlajek operace** (načíst z cfgItem `docs.core.rowOperations`):
    `rowPartner` → `lookup('partner', table: 'base_persons_persons', …)`;
    `rowPaymentId` → `input('payment_reference')` (VS), `input('specific_symbol')`
    (SS), `input('constant_symbol')` (KS), `date('due_date')`.
- nastavit `price_calc_mode = 1` (z celkové), ať `calculateRowPrice`
  nepřepíše `total_price` (pole skryté, hodnota fixní).

Operace se mění → `triggers: 'reload'` na `operation`, v `recalculate` na změnu
`operation` přestavět definici (zobrazí/skryje pole dle nové operace).
`applyNewRecordDefaults` už default operace řeší (první dle `order` =
`acc.record`).

Pozn.: větvení neovlivní faktury — jejich operace `rowAccount` nemají, takže
běží stávající položková větev beze změny.

### 3. Modul `docs.accountingDocs` — Viewer + Form

`AccountingDocsViewer extends DocsHeadsViewer`:
```php
protected ?string $scopedDocType = 'cmnbkp';
```

`AccountingDocsForm extends DocsHeadsFormBase`:
- titulky „Účetní doklad" / „Nový účetní doklad", `getDocTypeLabel()` =
  „Účetní doklad", `getHeaderIcon()` = ikona účetního dokladu.
- `buildHeaderTab()` — lehká hlavička: `number_series` (hidden), `doc_number`
  (readOnly, hidden), `partner` (lookup, **nepovinný** — hlavní osoba dokladu),
  `accounting_date` (required, primární datum), `issue_date`, `due_date`,
  `doc_text`, `period_from` / `period_to`, `notice`. **Žádná** DPH /
  zaokrouhlení / měna pole (účetní doklad je `vat_mode = 0`).
- nový doklad: default `vat_mode = 0` (přes `applyNewRecordDefaults` Formu nebo
  `newRecordDefaults` Vieweru) — aby DPH větve v base zůstaly vypnuté.
- `buildExtraTabs()` — prázdné (žádný tab Nastavení v MVP), případně jen
  `notice` už v hlavičce.

`docs/accountingDocs/module.jsonc` — doplnit:
```jsonc
"viewers": [
    { "id": "docs.accountingDocs.heads",
      "name": "Accounting documents", "name:cs": "Účetní doklady",
      "name:en": "Accounting documents",
      "icon": "doc-accounting",
      "table": "docs_core_heads",
      "class": "Shipard\\Module\\Docs\\AccountingDocs\\AccountingDocsViewer",
      "navSection": "accounting", "navOrder": 5 }
],
"forms": [
    { "table": "docs_core_heads", "typeColumn": "doc_type",
      "classes": { "cmnbkp": "Shipard\\Module\\Docs\\AccountingDocs\\AccountingDocsForm" } }
]
```
(`navOrder: 5` = nahoře v Účtárně, nad deníkem/bankou/saldem — ověřit vizuálně
proti existujícím `navOrder` v sekci.)

### 4. Ikona

Zajistit ikonu `doc-accounting` (nebo zvolit existující klíč z
`frontend/src/icons.js`, např. `calculator` / `document`) a v případě nové
přidat do `iconMap` + `npm run build`.

## Hotovo když

- V sidebaru sekce **Účtárna** je položka „Účetní doklady"; otevře viewer
  filtrovaný na `cmnbkp` se spodními taby per číselná řada.
- Lze založit nový účetní doklad (lehká hlavička, partner nepovinný), přidat
  řádky obou typů přes modal:
  - „Účetní zápis" → účet přímo + Má dáti/Dal + částka (+ volitelně partner /
    VS / SS / KS / splatnost);
  - „Účetní položka" → položka typu Účetní položka + Má dáti/Dal + částka.
- Vyrovnaný doklad (Σ MD = Σ DAL) jde Potvrdit a do stavu 40 → vznikne deník +
  saldo (ověřeno už ve Fázi 2, tady přes UI).
- Nevyrovnaný doklad UI nepustí do 40 (validační chyba z `AccountingDocument`).
- Faktury vydané/přijaté v UI beze změny (Form i řádky).

## Doporučené pořadí

1. `rowAccount` atribut do operací.
2. `DocRowsForm` — větev kontačního řádku + `recalculate`.
3. `AccountingDocsViewer` (triviální).
4. `AccountingDocsForm` (head tab + defaulty).
5. `module.jsonc` (viewers + forms) + ikona + `npm run build`.
6. Klik-test: založení, oba typy řádků, potvrzení do 40, kontrola deníku +
   salda; regrese faktur.

## Rozhodnutí ✓

- **UX řádků** — editace přes modal (`DocRowsForm` v `FormDialog`), strana
  `acc_side` selectem (Má dáti / Dal) + částka `total_price`. Ne inline
  dvojsloupec (ten odpovídá staré inline mřížce, kterou nové UI nemá).
- **Hlavička** — lehká, partner nepovinný, bez DPH polí (`vat_mode = 0`).
- **`rowAccount`** — form-facing atribut operace (`direct` / `item`), souhlasí
  s `accountSrc` v předpisu.
- **Navigace** — sekce `accounting` (Účtárna).

## Otevřené body

- **Náhled řádků** — `FormSubTable` derivuje prvních 5 sloupců genericky;
  pro kontaci by dávaly smysl účet / strana / částka / partner / text. Vylepšit
  (konfigurovatelné náhledové sloupce na child elementu) je samostatná drobnost
  mimo tuto fázi.
- **Inline editace řádků** — dvojsloupcová inline mřížka jako ve starém
  Shipardu je samostatný frontend úkol; MVP jede přes modal.
- **Snapshoty** — `DocDocument::maintainSnapshots` se u `cmnbkp` (trade_dir 0)
  spustí ve stavu 20 zbytečně (ownSnap). Neškodí; případný úklid (override
  skip pro cmnbkp) je drobnost na později.
- **Pole mimo MVP** (ze screenshotu) — `Č. účtu` na řádku, středisko /
  zakázka / majetek — navazující moduly.
- README (`docs/`, `tasks/`) — spravuje David.
