# Účetní doklady (cmnbkp) — Fáze 2: Účetní backend (engine + předpis + subclass)

**Stav:** hotovo

## Kontext

Fáze 1 (hotová) připravila schéma: `docs_core_rows` nese saldo identitu
(`partner`, `payment_reference`, `specific_symbol`, `constant_symbol`,
`due_date`), typ `cmnbkp` je v `docTypes.jsonc`, `acc.entry` je povolená pro
`cmnbkp`, číselná řada se zakládá sama.

Tato fáze dodává **kompletní účetní backend** účetního dokladu — po ní jde
přes API založit vyrovnaný `cmnbkp` ve stavu 40, který se správně zaúčtuje do
deníku (s per-řádkovou saldo identitou) a vygeneruje saldo pohyby. UI (Form,
Viewer, sekce Účtárna) je až Fáze 3.

Účetní doklad má dva druhy řádků kontace (dle reálného starého formuláře):

- **„Účetní zápis"** (operace `acc.record`) — účet se zadává **přímo** na
  řádku, varianta A.
- **„Účetní položka"** (operace `acc.item`) — účet přijde **z položky** typu
  „Účetní položka" (`item_type = 2`), varianta B.

Obě nesou stranu MD/DAL **na řádku** (uživatel vyplní Má dáti **nebo** Dal) a
volitelnou saldo identitu (partner + VS/SS/KS + splatnost). Strana ani saldo
identita tedy nejdou z operace ani z hlavičky — jsou per řádek.

Architektonické připomenutí:

- Deník je čistý derivát, zapisuje ho `AccountingEngine` (DELETE + INSERT).
  Dnes razítkuje `partner` + platební identitu na **všechny** řádky deníku
  z **hlavičky** (`makeLine` bere `head['partner']`, `writeResult` bere
  symboly + `due_date` z `$head`).
- `LedgerGenerator` (saldo) čte `partner` + symboly + `due_date` přímo
  z řádku deníku a saldo skupinu odvozuje z čísla účtu (prefix match v
  `balance_accounts`). Proto stačí, aby engine zapsal správnou per-řádkovou
  identitu na řádek deníku — saldo se odvodí samo, bez další logiky.
- Částku řádku bez DPH dává `DocDocument::calculateRowVat()` do `vat_base`
  (`vat_base = total_price`, ř. ~507), `applyDomesticAmounts()` do
  `vat_base_dom`. Engine `buildRowLines` čte právě tyto sloupce — částka
  účetního řádku tedy půjde do `total_price`.

## Cíl

`cmnbkp` se plně účtuje:

1. Engine umí účet z řádku (`accountSrc:'row'`) i stranu z řádku
   (`sideSrc:'row'`) a razítkuje partnera + platební identitu **per řádek**
   (dle vlajek operace `rowPartner` / `rowPaymentId`), s fallbackem na
   hlavičku.
2. Řádky deníku se neslévají přes různou platební identitu (grouping fix, D7).
3. Předpis `economy.accounting.rules.cz` má sekci pro `cmnbkp`.
4. Subclass `AccountingDocument` zajistí součty (`total_amount = Σ MD`),
   nepovinného hlavičkového partnera a validaci vyrovnanosti MD = DAL.

Faktury (`invno` / `invni`) produkují **bajt po bajtu stejný deník** jako před
změnou.

## Návaznost

- **Předchází:** Fáze 1 (schéma + typ), accounting Fáze 1–3 (engine, deník,
  event handler), accbal Fáze 0–3 (saldo, ledger generator).
- **Navazuje:** Fáze 3 (modul UI — Form + Viewer + sekce Účtárna), Fáze 4
  (import).

## Před implementací přečti

- `modules/economy/accounting/src/AccountingEngine.php` — `buildRowLines()`,
  `makeLine()`, `resolveItemAccount()`, `groupLines()`, `writeResult()`.
  Tady jsou všechny změny enginu.
- `modules/economy/accounting/config/accountingRules.cz.jsonc` — sekce
  `documents` (vzor `invno` / `invni`, krok `accountSrc:'item'`).
- `modules/docs/core/config/rowOperations.jsonc` — operace + atributy
  `docTypes`.
- `modules/docs/core/tables/docs_core_rows.jsonc` — sloupce z Fáze 1
  (saldo identita), kam přidat `account` + `acc_side`.
- `modules/docs/core/src/DocDocument.php` — `validate()` (~ř. 493, požadavek
  partnera), `sumTotals()` (~ř. 686), `calculateRowVat()` (~ř. 495),
  `resolveRowsForCompute()` (~ř. 208), `beforeSave()` (volání `sumTotals`).
- `modules/docs/core/src/DocsHeadsDocument.php` — defaultClass, předek
  subclass.
- `modules/docs/invoicesIn/module.jsonc` + `src/ReceivedInvoiceDocument.php`
  — vzor per-typ modulu (documentClasses `classes` mapa) a subclassy.
- `modules/economy/accbal/src/LedgerGenerator.php` — `buildDesired()`
  (čtení identity z řádku deníku) — jen pro kontext, **nemění se**.

## Scope

Engine (`economy.accounting`), předpis, dva nové sloupce + cfgItem v
`docs.core`, dvě operace, nový modul `docs.accountingDocs` se subclassou.
Drobné háčky v `DocDocument` (overridovatelnost). **Nesahat na:**
`LedgerGenerator` / accbal, UI (Form / Viewer / navigace — Fáze 3), import.

## Co implementovat

### 1. Schéma `docs_core_rows.jsonc` — účet + strana

Přidat (nullable) do sekce za saldo identitu z Fáze 1:

```jsonc
{ "id": "account", "name": "Account", "name:cs": "Účet", "name:en": "Account",
  "type": "int", "nullable": true, "reference": "economy_accounting_accounts" },
{ "id": "acc_side", "name": "Accounting side",
  "name:cs": "Strana", "name:en": "Accounting side",
  "type": "enumInt", "nullable": true, "cfgItem": "docs.core.accSides" }
```

Nový cfgItem `modules/docs/core/config/accSides.jsonc`:
```jsonc
{
    "0": { "name": "Debit",  "name:cs": "Má dáti", "name:en": "Debit" },
    "1": { "name": "Credit", "name:cs": "Dal",     "name:en": "Credit" }
}
```
Registrovat v `modules/docs/core/module.jsonc` (`config` blok):
`{ "id": "docs.core.accSides", "file": "config/accSides.jsonc" }`.

### 2. Operace `acc.record` + `acc.item` v `rowOperations.jsonc`

Nejdřív doplnit hlavičkový komentář cfgItem o dvě vlajky:

- `rowPartner: 1` — řádek nese vlastního partnera (engine ho razítkuje z řádku
  místo z hlavičky; formulář ukáže input).
- `rowPaymentId: 1` — řádek nese vlastní platební identitu
  (`payment_reference` / `specific_symbol` / `constant_symbol` / `due_date`).

```jsonc
"acc.record": {
    "name": "Accounting record",
    "name:cs": "Účetní zápis",
    "name:en": "Accounting record",
    "rowPartner": 1,
    "rowPaymentId": 1,
    "docTypes": { "cmnbkp": {"order": 100} }
},
"acc.item": {
    "name": "Accounting item",
    "name:cs": "Účetní položka",
    "name:en": "Accounting item",
    "rowPartner": 1,
    "rowPaymentId": 1,
    "docTypes": { "cmnbkp": {"order": 200} }
}
```
(Stávající `acc.entry` faktur zůstává beze změny — bez vlajek, jiný docType.)

### 3. Předpis pro `cmnbkp` v `accountingRules.cz.jsonc`

Do `documents`:
```jsonc
{"docType": "cmnbkp", "accounting": [
    {"src": "rows", "accountSrc": "row",  "sideSrc": "row", "operation": "acc.record"},
    {"src": "rows", "accountSrc": "item", "sideSrc": "row", "operation": "acc.item"}
]}
```
Žádné `cat` ani `ceil` — účet i strana jdou z řádku. (Bez DPH/zaokrouhlení
kroků; D4.)

### 4. Engine `AccountingEngine`

Všechny změny v `buildRowLines` + helpery; `buildVatLines` / `buildHeadLines`
ponechat (identita z hlavičky jako dosud — fallback).

- **Účet z řádku** — nový helper `resolveRowAccount(array $row, int $rowId)`:
  `$row['account']` → `SELECT id, number FROM economy_accounting_accounts`.
  Nevyplněný / nenalezený → chybový řádek (`is_error`, maska `??????`) +
  message (stejný vzor jako `resolveItemAccount`). V `buildRowLines` větvit:
  `accountSrc === 'row'` → `resolveRowAccount`, `'item'` →
  `resolveItemAccount` (stávající), jinak `resolveCategoryAccount`.

- **Strana z řádku** — v `buildRowLines`: když krok má `sideSrc === 'row'`,
  efektivní strana = `(int) $row['acc_side']`; jinak `step['side']`. Předat do
  `makeLine` (parametr, nebo lokální klon kroku s nastaveným `side`).

- **Per-řádková identita** — v `buildRowLines` načíst vlajky operace řádku
  z cfgItem `docs.core.rowOperations` (engine už má `$this->config`):
  - `rowPartner` → `partner` z řádku, jinak z hlavičky;
  - `rowPaymentId` → `payment_reference` / `specific_symbol` /
    `constant_symbol` / `due_date` z řádku, jinak z hlavičky.
  Předat do `makeLine`.

- **`makeLine`** — přijme per-řádkovou identitu (partner + 4 platební pole) a
  uloží ji do pole řádku deníku (dnes `partner` bere z `$head`, symboly se
  vůbec nepřenášejí — přidat je). Pro `vat`/`head` zdroje volat s identitou
  z hlavičky (fallback).

- **`groupLines` (D7)** — do klíče přidat `payment_reference`,
  `specific_symbol`, `constant_symbol`, `due_date`. Faktury: identita napříč
  řádky konstantní → klíč i výsledek beze změny. Účetní doklad: různá identita
  → řádky se neslévají.

- **`writeResult`** — platební identitu v `INSERT` brát **z řádku deníku**
  (`$line[...]`), ne globálně z `$head`. (Partner už v `$line` je.)

### 5. Modul `docs.accountingDocs` + subclass

`modules/docs/accountingDocs/module.jsonc`:
```jsonc
{
    "id": "docs.accountingDocs",
    "name": "Accounting documents",
    "name:cs": "Účetní doklady",
    "name:en": "Accounting documents",
    "dependencies": ["docs.core", "economy.accounting"],
    "documentClasses": [
        { "table": "docs_core_heads", "typeColumn": "doc_type",
          "classes": { "cmnbkp": "Shipard\\Module\\Docs\\AccountingDocs\\AccountingDocument" } }
    ]
}
```
(Viewer + Form přidá Fáze 3.)

`modules/docs/accountingDocs/src/AccountingDocument.php extends DocsHeadsDocument`:

- **`headPartnerRequired(): bool` → `false`** (override). Vyžaduje to drobný
  háček v `DocDocument::validate()`: požadavek partnera obalit
  `if ($this->headPartnerRequired()) { … }`; nový `protected function
  headPartnerRequired(): bool { return true; }` v `DocDocument`.
- **`sumTotals()` override** — `total_amount = Σ total_price` řádků s
  `acc_side = 0` (MD); `total_base = total_amount`, `total_vat = 0`,
  `total_rounding = 0`. Vyžaduje předat řádky do `sumTotals`: rozšířit
  bázovou signaturu na `sumTotals(array &$data, array $recap, array $rows = [])`
  a v `beforeSave` předat `$rowsForCompute`. Báze `$rows` ignoruje (chování
  faktur beze změny).
- **Validace vyrovnanosti** (stav 40) — `Σ MD == Σ DAL` přes
  `resolveRowsForCompute`; nerovnost → chyba `unbalanced`. Každý kontační
  řádek musí mít `acc_side` a buď `account` (acc.record), nebo `item`
  (acc.item), a nenulové `total_price`.

## Hotovo když

- `ds-upgrade` přidá `account` + `acc_side`; compiled cfg má `accSides`,
  obě operace a předpis `cmnbkp`.
- Přes API jde uložit `cmnbkp` ve stavu 40 se dvěma řádky (MD na účet X, DAL
  na účet Y, vyrovnané) — vznikne vyrovnaný deník (`money_dr`/`money_cr`),
  účty z řádků, partner a symboly per řádek.
- Řádek na saldokontním účtu (311/321/331…) s partnerem + VS → `LedgerGenerator`
  vyrobí saldo pohyb se správnou identitou a skupinou (odvozeno z účtu).
- Dva závazkové řádky na stejný účet, různý VS → **dva** řádky deníku (D7), ne
  jeden sloučený.
- Nevyrovnaný `cmnbkp` (Σ MD ≠ Σ DAL) nejde do stavu 40 (validační chyba).
- `cmnbkp` ve 40 bez hlavičkového partnera projde (partner nepovinný).
- **Regrese faktur:** přeúčtování `invno` i `invni` dá identický deník jako
  před změnou (zkontrolovat existující accounting testy + diff deníku).

## Doporučené pořadí

1. Schéma (`account`, `acc_side`, `accSides.jsonc`) + `ds-upgrade`.
2. Operace `acc.record` / `acc.item` + vlajky v komentáři.
3. Předpis `cmnbkp`.
4. Engine: `resolveRowAccount`, `sideSrc`, per-řádková identita v `makeLine`,
   `groupLines` klíč, `writeResult`.
5. `DocDocument` háčky (`headPartnerRequired`, `sumTotals` s `$rows`).
6. Modul `docs.accountingDocs` + `AccountingDocument`.
7. Test: vyrovnaný `cmnbkp` přes API → deník + saldo; regrese faktur.

## Rozhodnutí ✓

- **D6** — dvě operace dle zdroje účtu: `acc.record` (účet z řádku, var. A) a
  `acc.item` (účet z položky typu 2, var. B); strana MD/DAL **per řádek**
  (`acc_side` + `sideSrc:'row'`); částka v `total_price` (→ `vat_base`).
- **D7** — grouping klíč rozšířen o platební identitu.
- **D3** — engine razítkuje partnera + platební identitu per řádek dle vlajek
  operace `rowPartner` / `rowPaymentId`, fallback hlavička.
- **Přesun** — subclass `AccountingDocument` (validate + sumTotals override) je
  v této fázi; Fáze 3 je čistě UI.
- **D4** — bez DPH (žádné `vat` kroky v předpisu, `total_vat = 0`).

## Otevřené body

- **Pole mimo MVP** (ze screenshotu): `Č. účtu` na řádku (bankovní účet),
  `Středisko` / `Zakázka` / `Majetek` (analytické dimenze) — Fáze 3+ /
  navazující moduly.
- **`price_calc_mode` kontačního řádku** — aby `calculateRowPrice`
  nepřepsalo `total_price` z `quantity × unit_price`, řádek kontace použije
  `price_calc_mode = 1` (z celkové). Nastaví formulář ve Fázi 3; pro test přes
  API se pošle explicitně.
- README (`docs/`, `tasks/`) — spravuje David.
