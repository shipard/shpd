# Účetní doklady (cmnbkp) — Doplněk Fáze 2: saldokontní operace (operation-default účet)

## Kontext

Při ladění importu (Fáze 4) vyšlo najevo, že starý cmnbkp používá **třetí
způsob přiřazení účtu** kromě přímého (`debsAccountId` → `acc.record`) a
z položky (`acc.item`): účet odvozený **z operace** přes starý
`acc-default.json` (`{cat, accountMask}`, operace → účet). Typicky řádky
„Zápočet pohledávky" / „Zápočet závazku" bez vyplněné položky i účtu —
v datech dominantní (1090001 = 12181 řádků, 1090002 = 228 řádků). Bez odvození
účtu je import vyřadí jako nevalidní → doklad má nevyrovnanou bilanci MD/DAL.

Nový engine tuto cestu **už umí bez změny PHP**: `AccountingEngine::buildRowLines`
větví `accountSrc` a fallback je `resolveCategoryAccount` (účet z `cat` → maska
v `accounts[]`) — přesný ekvivalent `acc-default.json`. Kategorie `receivables`
(→311) a `payables` (→321) v `accountingRules.cz.jsonc` už existují. Chybí jen
operace + kroky předpisu, které tuto cestu pro `cmnbkp` zapnou.

Je to **předpis (kontace), ne wizard** — activity (auto-generování zápočtu)
zůstávají zahozené.

## Cíl

`cmnbkp` řádek se saldokontní operací (bez položky i přímého účtu) dostane účet
z kategorie (311/321), nese per-řádkovou saldo identitu a doklad se vyrovná a
zaúčtuje. Pokrývá ~98 % operation-default řádků v datech.

## Návaznost

- **Doplňuje:** Fázi 2 (engine, předpis, operace) a Fázi 3 (DocRowsForm).
- **Páruje se s:** import doplněk `old_shipard`
  `modules/imports/newShipard/tasks/11-cmnbkp-import-balance-ops.md`
  (**tato strana první**).

## Před implementací přečti

- `modules/economy/accounting/config/accountingRules.cz.jsonc` — `accounts[]`
  (kategorie `receivables` → 311 ř. 41, `payables` → 321 ř. 42), `documents`
  sekce `cmnbkp` (kroky z Fáze 2).
- `modules/docs/core/config/rowOperations.jsonc` — operace `acc.record` /
  `acc.item` (vzor + vlajky `rowAccount` / `rowPartner` / `rowPaymentId`).
- `modules/docs/core/src/DocRowsForm.php` — větev kontačního řádku z Fáze 3
  (detekce přes `rowAccount`).
- `modules/economy/accounting/src/AccountingEngine.php` —
  `resolveCategoryAccount()` (potvrdit, že krok bez `accountSrc` s `cat` jde
  touto cestou).

## Scope

Pouze config (`rowOperations.jsonc`, `accountingRules.cz.jsonc`) + drobná
úprava `DocRowsForm` (zobrazení polí). **Žádná změna enginu.**

## Co implementovat

### 1. Dvě saldokontní operace v `rowOperations.jsonc`

```jsonc
"acc.balanceReceivable": {
    "name": "Receivable set-off",
    "name:cs": "Zápočet pohledávky", "name:en": "Receivable set-off",
    "rowPartner": 1, "rowPaymentId": 1,
    "docTypes": { "cmnbkp": {"order": 300} }
},
"acc.balancePayable": {
    "name": "Payable set-off",
    "name:cs": "Zápočet závazku", "name:en": "Payable set-off",
    "rowPartner": 1, "rowPaymentId": 1,
    "docTypes": { "cmnbkp": {"order": 400} }
}
```
Bez `rowAccount` — účet je implicitní z kategorie, formulář vstup účtu/položky
nezobrazí.

### 2. Dva kroky předpisu pro `cmnbkp` v `accountingRules.cz.jsonc`

Do sekce `documents` u `docType: cmnbkp` přidat k existujícím
`acc.record` / `acc.item` krokům:
```jsonc
{"src": "rows", "cat": "receivables", "sideSrc": "row", "operation": "acc.balanceReceivable"},
{"src": "rows", "cat": "payables",    "sideSrc": "row", "operation": "acc.balancePayable"}
```
`resolveCategoryAccount` z `cat` odvodí účet (311/321). Strana z řádku
(`acc_side`), identita per řádek (vlajky operace, Fáze 2/D3).

### 3. `DocRowsForm` — rozšířit detekci kontačního řádku

Fáze 3 rozpoznává kontační řádek podle atributu `rowAccount` na operaci.
Rozšířit na: **kontační řádek = operace má `rowAccount` NEBO `rowPartner`
NEBO `rowPaymentId`**. Vstup účtu/položky zobrazit **jen** když operace má
`rowAccount` (`direct`/`item`); saldokontní operace (bez `rowAccount`) ho
neukážou — účet je implicitní. Zbytek (acc_side, total_price, partner dle
`rowPartner`, symboly+splatnost dle `rowPaymentId`, text) beze změny.

## Hotovo když

- Přes API/UI lze založit `cmnbkp` s řádkem „Zápočet pohledávky" (bez
  účtu/položky), partnerem + VS + stranou + částkou; po stavu 40 vznikne řádek
  deníku na 311 se správnou identitou a saldo pohyb (spárování přes VS).
- Totéž pro „Zápočet závazku" → 321.
- Vyrovnaný doklad (např. 1× `acc.item` MD + N× `acc.balanceReceivable` DAL)
  projde do 40 a zaúčtuje se vyrovnaně.
- Formulář u saldokontních operací neukazuje vstup účtu/položky; u
  `acc.record`/`acc.item` beze změny.
- Faktury i dosavadní cmnbkp řádky (acc.record/acc.item) beze změny.

## Doporučené pořadí

1. Operace do `rowOperations.jsonc`.
2. Kroky předpisu do `accountingRules.cz.jsonc`.
3. `ds-upgrade` + rebuild compiled cfg.
4. `DocRowsForm` detekce + `npm run build`.
5. Test: zápočtový řádek → deník 311/321 + saldo; regrese faktur a acc.record.

## Rozhodnutí ✓

- Saldokontní (operation-default) účet přes `accountSrc: category` =
  `resolveCategoryAccount` (nový ekvivalent `acc-default.json`), žádná změna
  enginu.
- Operace `acc.balanceReceivable` (311) / `acc.balancePayable` (321),
  `rowPartner`+`rowPaymentId`, bez `rowAccount`.
- Majetkové (1090070–73) a kurzové (1090012) operace v této fázi **neúčtujeme**
  — viz import doplněk (cílový stav max 20).

## Otevřené body

- Účtování majetku / kurzových rozdílů přes „Druh dokladu" na hlavičce —
  samostatná budoucí fáze (David: „domyslet později").
- README (`docs/`, `tasks/`) — spravuje David.
