# Účetní doklady (cmnbkp) — Fáze 4a (nov_shipard): Exchange + Applier

## Kontext

Poslední fáze — import účetních dokladů ze starého Shipardu. Párový úkol;
**tento (nov_shipard) se nasazuje první** (applier musí umět nová pole dřív,
než je `DocsRunner` ve starém začne posílat). Druhý: `old_shipard`
`modules/imports/newShipard/tasks/11-cmnbkp-import.md`.

Exchange formát `shpd.docs.document.v1` je dnes business-level a řádek nese jen
item / množství / cena / DPH (`transformRows`). Účetní doklad ale potřebuje
**kontační řádek**: účet, strana MD/DAL, částka, per-řádkový partner + platební
identita. Tato fáze rozšíří schema + applier, aby kontaci přijaly a zapsaly do
sloupců z Fáze 2/3 (`account`, `acc_side`, `partner`, `payment_reference`,
`specific_symbol`, `constant_symbol`, `due_date`).

Zdrojová data (ověřeno): starý `e10doc_core_rows` + debs rozšíření nese
`debsAccountId` (účet, string), `operation`, `item`/`itemBalance`,
`debit` (= Má dáti) / `credit` (= Dal), `person`, `symbol1/2/3`, `dateDue`,
`text`. Activity-generované cmnbkp (zápočty, počáteční stavy, kurz. rozdíly,
otevření/uzavření, majetek) se importují jako obecné účetní doklady s jejich
kontací (D-imp-1); `taxrows` (Přiznání DPH) se ignorují (D-imp-2).

## Cíl

Applier přijme kanonický účetní doklad (`docType: accountingDocument`) s
kontačními řádky a zapíše `cmnbkp` s korektními řádky (účet z čísla, strana,
částka, per-řádková saldo identita). Hlavičkový partner je nepovinný, žádná
selfParty resolution. Faktury beze změny.

## Návaznost

- **Předchází:** Fáze 2/3 (sloupce řádků, operace `acc.record`/`acc.item`,
  subclass), import faktur (Fáze 05/05b/10).
- **Páruje se s:** `old_shipard` `11-cmnbkp-import.md` — **nasadit tento první**.

## Před implementací přečti

- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` — `docType` enum,
  blok `rows`, `$defs`, `applyOptions`.
- `modules/core/exchange/src/Document/DocumentApplier.php` — `DOC_TYPE_MAP`
  (~ř. 91), `transform()` (~ř. 810), `buildHeadPayload` partner logika
  (~ř. 816–890), `transformRows()` (~ř. 908), `resolveNumberSeriesFor()`
  (~ř. 960), per-řádková resolve mapa `sideIds['rowItems']` / `_resolve.rows[i]`.
- `modules/core/exchange/src/Document/DocumentValidator.php` — požadavky na
  partnera / selfParty.
- `modules/core/exchange/src/Resolve/` — vzor resolverů (ItemResolver,
  PartyResolver) pro nový account-by-number resolver.
- `modules/economy/accounting/tables/economy_accounting_accounts.jsonc` —
  sloupec `number` (dohledání účtu).

## Scope

`shpd.docs.document.v1` schema + `DocumentApplier` + `DocumentValidator` +
nový account resolver. **Nesahat na:** engine / docs.core (hotové), UI, runner
(old_shipard, párový úkol).

## Co implementovat

### 1. Schema `shpd.docs.document.v1.jsonc`

- `docType` enum — přidat `"accountingDocument"`.
- `$defs` řádku (resp. `rows.items`) — přidat volitelná pole pro kontaci:
  - `account` (string, číslo účtu, pattern číslic; volitelné)
  - `accSide` (enum `["debit", "credit"]`)
  - `partner` (volitelné — řeší se přes `_resolve.rows[i].partner`, viz applier)
  - `paymentReference` (string ≤35), `specificSymbol` (≤20),
    `constantSymbol` (≤10), `dueDate` (date)
- Doplnit popis: kontační řádky používají `operation` `acc.record` (účet z
  `account`) / `acc.item` (účet z položky), `accSide` + `totalPrice` nesou
  stranu a částku; item/qty/unitPrice/vat zůstávají prázdné.

### 2. `DocumentApplier`

- `DOC_TYPE_MAP` — přidat `'accountingDocument' => 'cmnbkp'`.
- **`transformRows()`** — rozšířit `array_filter` payload o:
  - `account` → dohledat id v `economy_accounting_accounts` podle `number`
    (nový resolver, viz níže); nenalezeno → `null` + apply-warning (řádek bez
    účtu skončí jako chybový až při účtování — neblokovat import).
  - `acc_side` → `['debit' => 0, 'credit' => 1][$row['accSide']] ?? null`.
  - `partner` → per-řádkový partner z resolve pinu
    (`sideIds['rowPartners'][$i]`, viz níže).
  - `payment_reference` / `specific_symbol` / `constant_symbol` / `due_date`
    → verbatim z řádku (`paymentReference` / `specificSymbol` /
    `constantSymbol` / `dueDate`).
- **Per-řádkový partner** — analogicky k `rowItems`: applier čte
  `_resolve.rows[i].partner` (`userAction: useExisting:<newId>`) a uloží do
  `sideIds['rowPartners'][$i]`. (MVP: jen pin cesta, kterou runner používá;
  Party-fragment na řádku je možný follow-up.)
- **Hlavička bez selfParty** — pro `cmnbkp` (kanonický bez `selfParty` /
  `supplier` / `customer`) `buildHeadPayload` nesmí volat self-party resolution;
  hlavičkový `partner` z volitelného top-level `partner` (pin přes
  `_resolve.partner`), jinak `null`. Větvit dle `docType === 'cmnbkp'` nebo
  podle absence `selfParty`.

### 3. `DocumentValidator`

- Pro `accountingDocument` neuplatňovat požadavky vázané na fakturu
  (selfParty/partner povinný, supplier/customer). Validace zůstává na řádky
  (operation, account/item dle operace) + applyOptions (targetDocState
  10/20/40/30, numberSeriesCode, importNumber) — ty už existují.

### 4. Account-by-number resolver

Nový `src/Resolve/AccountResolver.php` (nebo metoda v applieru): `number` →
`SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN
(aktivní) LIMIT 1`. Cache per běh. Nenalezeno → `null`.

## Hotovo když

- Applier přijme `docType: accountingDocument` doklad s kontačními řádky a
  vytvoří `cmnbkp` s řádky, kde `account` (z čísla), `acc_side`, `total_price`,
  `partner`, symboly a `due_date` sedí na vstup.
- Doklad bez hlavičkového partnera projde (žádná selfParty chyba).
- `numberSeriesCode` + `importNumber` + `targetDocState=40` fungují jako u
  faktur (zaúčtuje se, vznikne saldo).
- Neznámé číslo účtu → řádek bez `account` + apply-warning, import neselže.
- Faktury (`invoiceReceived` / `invoiceIssued`) se aplikují identicky jako
  před změnou (řádky bez nových polí → `transformRows` je vynechá přes
  `array_filter`).

## Doporučené pořadí

1. Schema (docType + řádková pole).
2. AccountResolver.
3. `transformRows` rozšíření + per-řádkový partner pin.
4. `DOC_TYPE_MAP` + buildHeadPayload větev (cmnbkp bez selfParty).
5. `DocumentValidator` relaxace pro accountingDocument.
6. Test: apply ručně sestaveného `accountingDocument` JSON přes exchange
   endpoint → `cmnbkp` ve 40 + deník + saldo; regrese faktur.

## Rozhodnutí ✓

- **D-imp-1** — všechny cmnbkp jako obecné účetní doklady (activities zahozeny).
- **D-imp-2** — `taxrows` ignorovány (řeší se s Přiznáním DPH).
- **D-imp-3** — řádky: `debit`/`credit` → `acc_side`+`total_price`,
  `debsAccountId` → `account`, `person` → per-řádkový partner, symboly,
  `dateDue`; hlavičkový partner nepovinný; nasazení nov_shipard → old_shipard.

## Otevřené body

- Per-řádkový partner přes Party-fragment (ne jen pin) — follow-up, pokud bude
  potřeba import bez LocalIdMap pinů.
- README (`docs/`, `tasks/`) — spravuje David.
