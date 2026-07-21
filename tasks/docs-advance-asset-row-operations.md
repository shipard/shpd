# Řádkové operace pro zálohy a majetek (vlna C — nová strana)

> **Status:** navrženo · **Moduly:** docs.core, economy.accounting
> **Typ:** rozšíření konfigurace + testy · **Design:**
> `docs/design-import-row-operations.md` (D1, D2, D4 — tento PRD je
> implementačním kontraktem designu, nedubluje zdůvodnění)
> **Návaznost:** old_shipard task 21 (migrace) závisí na tomto PRD —
> applier musí operace znát dřív, než je import začne posílat.

## Cíl

Pět nových řádkových operací (zálohy × 4, majetek × 1) s přímým účtem na
řádku a kroky účtovacího předpisu, které je zaúčtují dle D4. Bez změn
`AccountingEngine` — vše existující mechanikou (`accountSrc: "row"`,
`reverseSign`, vlajky operací).

## Scope

### 1. `modules/docs/core/config/rowOperations.jsonc`

Doplnit (labels cs/en, order navazuje na stávající bloky invni/invno;
`rowAccount: "direct"` — dle komentáře v konfigu musí souhlasit
s `accountSrc` předpisu):

| klíč | cs | docTypes | vlajky |
|---|---|---|---|
| `purchase.advanceDeduction` | Odpočet poskytnuté zálohy | invni | `rowAccount: "direct"`, `rowPaymentId: 1` |
| `purchase.advanceVat` | Zdanění poskytnuté zálohy | invni | `rowAccount: "direct"`, `rowPaymentId: 1` |
| `purchase.asset` | Pořízení majetku | invni | `rowAccount: "direct"` |
| `sale.advanceDeduction` | Odpočet přijaté zálohy | invno | `rowAccount: "direct"`, `rowPaymentId: 1` |
| `sale.advanceVat` | Zdanění přijaté zálohy | invno | `rowAccount: "direct"`, `rowPaymentId: 1` |

`rowPaymentId` na zálohových operacích: `payment_reference` řádku nese
číslo zálohového dokladu (D1 — příprava pro saldokonto Fáze 4+).

### 2. `modules/economy/accounting/config/accountingRules.cz.jsonc`

Kroky dle D4, zařazené mezi řádkové kroky a head kroky
(rounding/payables/receivables):

- invni:
  - `{"src": "rows", "accountSrc": "row", "side": 1, "reverseSign": 1, "operation": "purchase.advanceDeduction"}`
  - `{"src": "rows", "accountSrc": "row", "side": 0, "operation": "purchase.advanceVat"}`
  - `{"src": "rows", "accountSrc": "row", "side": 0, "operation": "purchase.asset"}`
- invno:
  - `{"src": "rows", "accountSrc": "row", "side": 0, "reverseSign": 1, "operation": "sale.advanceDeduction"}`
  - `{"src": "rows", "accountSrc": "row", "side": 1, "operation": "sale.advanceVat"}`

Sémantika znamének: odpočty jsou na dokladu **záporné** (věrný obraz),
`reverseSign` je otočí na kladný zápis protistrany (invni → DAL 314xxx,
invno → MD 324xxx). Zdanění zálohy je kladný řádek na nákladové/výnosové
straně účtu x14901/x24901; jeho DPH jde standardně z rekapitulace.

### 3. Frontend

Žádné změny kódu — formuláře odvozují vstupy z vlajek operace
(`rowAccount: "direct"` → input účtu, `rowPaymentId` → platební identita).
Ověřit ručně na invni/invno formuláři, že se nové operace nabízejí
a zobrazují správné vstupy.

### 4. Nasazení

Rebuild kompilované konfigurace + `ds-upgrade` na obou dev DS (změny
`.jsonc` předcházejí použití).

## Testy

- **Unit (engine)** — `tests/Unit/Module/Economy/Accounting/…`: krok
  `accountSrc row` + `reverseSign` na záporném řádku vytvoří kladný zápis
  na straně kroku; `rowPaymentId` operace razítkuje platební identitu
  z řádku (payment_reference), ne z hlavičky.
- **Integrace** — `tests/Integration/Accounting/AccountingEngineTest.php`
  (vzor PDP testu):
  1. invni: purchase.services (+10 000, DPH 21) + purchase.advanceDeduction
     (−6 050 vč. DPH −1 050, účet 314901, payment_reference „ZAL-1") →
     deník MD 518 10 000 + MD 343 1 050 / DAL 314901 5 000 + DAL 343 1 050…
     přesné částky dle fixtury; vyrovnáno, DAL 321 = zbytek k úhradě,
     řádek 314901 nese payment_reference.
  2. invni „daňový doklad k záloze": purchase.advanceVat (+7 290, účet
     314901, DPH 21 z ceny) + purchase.advanceDeduction (−7 290, tax0,
     účet 314001) → MD 314901 + MD 343 / DAL 314001, žádný 321 (netto 0),
     vyrovnáno. (Vzor: starý doklad 56036.)
  3. invno zrcadlo: sale.services + sale.advanceDeduction (tax0, 324001)
     → DAL 6xx / MD 324001 / MD 311 zbytek, vyrovnáno.
- PHPUnit vždy s úzkým `--filter`.

## Commit strategie

1. `docs: řádkové operace pro zálohy a majetek` — bod 1.
2. `economy.accounting: předpis pro zálohové a majetkové řádky` — bod 2
   + testy.

## Dodatek 2026-07-20 — D10: zálohy kategoriemi (revize D1/D4)

Po potvrzení D10 (viz dodatek designu) se zálohové operace mění
z přímého účtu na kategorie — revize už implementovaných bodů:

1. **`rowOperations.jsonc`**: čtyřem zálohovým operacím odebrat
   `rowAccount: "direct"` (zůstává `rowPaymentId: 1`); formulář pak
   input účtu nezobrazuje (vzor: saldokontní zápočty).
   `purchase.asset` zůstává s `rowAccount: "direct"` beze změny.
2. **`accountingRules.cz.jsonc`** — sekce `accounts` dostane položky
   (pořadí záznamů je významové — brutto s query první):

   ```jsonc
   {"cat": "advances.given",    "accountMask": "314",  "query": {"vat_amount": 0}},
   {"cat": "advances.given",    "accountMask": "3149"},
   {"cat": "advances.received", "accountMask": "324",  "query": {"vat_amount": 0}},
   {"cat": "advances.received", "accountMask": ["3249", "324"]}
   ```

   Zálohové kroky předpisu: místo `accountSrc: "row"` →
   `"cat": "advances.given"` (invni) / `"cat": "advances.received"`
   (invno); strany a `reverseSign` beze změny. Krok `purchase.asset`
   zůstává `accountSrc: "row"`.
3. **`AccountingEngine::resolveCategoryAccount`** — jediná změna kódu:
   `accountMask` smí být řetězec **nebo pole** — masky se zkoušejí po
   řadě přes `maskResolver`, první dohledaná vyhrává; žádná → stávající
   `account_not_found` chování.
4. **Testy** — doplnit/upravit:
   - unit: mask-řetěz (první maska nedohledatelná → druhá; žádná →
     error účet s `is_error`);
   - unit: výběr položky kategorie pořadím + query
     (`vat_amount 0.00/NULL` → brutto maska, jinak zdaněná);
   - integrace: scénáře 1–3 přejít na kategorie (účty se dohledají
     z rozvrhu dev DS, ne z fixtur) + scénář „rozvrh bez 3249“ →
     fallback na 324 (vzor lefreal).

Body 1–2 původního Scope zůstávají jinak v platnosti; ds-upgrade +
rebuild cfg po změně .jsonc znovu na obou DS.

### Hotovo když (dodatek)

- [x] Zálohové operace bez inputu účtu ve formuláři; účet dohledává
      kategorie (msi: 314001/314901/324001/324901, lefreal:
      314100/314900/324100/324100 — ověřeno simulací masek 2026-07-21
      přes AccountMaskResolver proti rozvrhům btpg a 4dnh, shoda 8/8).
- [x] `accountMask` jako pole funguje (unit řetěz + error, integrační
      fallback 3249 → 324 dočasným skrytím 3249xx).
- [x] Integrační scénáře zelené s kategoriovým dohledáním (účty
      z rozvrhu 4l3j: 314900/314100/324100/324900, žádné fixtury).

## Hotovo když

- [x] Operace se nabízejí na invni/invno s inputem účtu; zálohové nesou
      platební identitu řádku. (Pozn.: PRD předpoklad „FE beze změn"
      neplatil — vlajky přepínaly do kontačního layoutu bez DPH; vyřešeno
      novou vlajkou `rowSide` na cmnbkp operacích, kryto unit testy
      `DocRowsFormContationTest`; ruční proklik na dev DS zbývá.)
- [x] Integrační scénáře 1–3 zelené (deník dle D4, vyrovnaný, správné
      strany a payment_reference). Účty ve fixturách: seedované analytiky
      314900/314100/324100 (PRD čísla x14901/x14001 jsou msi-specifické).
- [x] Rebuild cfg + ds-upgrade na btpg i 4dnh proběhly (+ 4l3j pro
      integrační testy).
- [x] Připraveno pro old_shipard task 21 (operace applier přijímá —
      DocumentApplier posílá operation passthrough, validace je zná
      z cfgItem).
