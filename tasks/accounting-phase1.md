# Účtování dokladů — Fáze 1: pohyby a sloupce

**Stav:** hotovo

## Kontext

Začínáme implementovat automatické účtování dokladů podle návrhu v
`docs/accounting.md`. Fáze 1 připravuje datový základ — žádný engine, žádný
deník, jen pohyby řádků, měnové dopočty a účet na položce. Engine a deník
přijdou ve Fázi 2.

Fáze 1 má tři pracovní balíčky:

- **W1** — pohyby řádků (`rowOperations` + sloupec `operation` + UI + validace)
- **W2** — domácí měna v řádcích dokladu (`_dom` sloupce, `total_rounding_dom`,
  persistence počítaných sloupců, haléřové dorovnání)
- **W3** — účet na položce (extension `accounting_account` na `economy_items`)

## Návaznost

- Návrhový dokument: `docs/accounting.md` (sekce 2, 3, 8) — **závazný**,
  tento task ho jen rozpracovává do kroků.
- Fáze 2 (deník + engine) bude číst: `operation` z řádků, `_dom` hodnoty
  z řádků/rekapitulace/hlavičky, `accounting_account` z položek. Všechno
  z DB — proto je persistence (W2) kritická.
- Účtový rozvrh (`economy_accounting_accounts`) už existuje a je naplněný
  provisionerem — W3 na něj jen odkazuje.

## Před implementací přečti

- `docs/accounting.md` — celý, hlavně sekce 2 (pohyby), 3 (změny tabulek),
  8 (měny a invarianty)
- `modules/docs/core/src/DocDocument.php` — compute pipeline v `beforeSave`
  (`calculateRowPrice`, `calculateRowVat`, `buildVatRecapitulation`,
  `sumTotals`, `applyTotalRounding`, `applyExchangeRate`), `afterPersist`,
  `resolveRowsForCompute`
- `modules/docs/core/src/DocRowsDocument.php` — `recomputeHeader` (cesta
  přepočtu při změně řádku)
- `modules/docs/core/src/DocRowsForm.php` + `docs/edit-forms.md` +
  `docs/edit-forms-cookbook.md` — jak postavit select v řádkovém formuláři
- `modules/docs/core/extensions/base_persons_persons.jsonc` +
  `docs/modules.md` sekce extensions — vzor pro W3
- `modules/economy/items/src/ItemsForm.php` — formulář položky (podmíněná
  viditelnost dle `item_type`, vzor je v `PersonsForm`)
- `modules/docs/core/tables/docs_core_rows.jsonc`,
  `docs_core_heads.jsonc`, `docs_core_vat_recap.jsonc`

## Scope

### V scope

- config `rowOperations.jsonc`, sloupec `operation`, validace, select v UI
- `vat_base_dom` / `vat_amount_dom` / `vat_total_dom` v `docs_core_rows`
- `total_rounding_dom` v `docs_core_heads`
- persistence počítaných sloupců řádků (vč. dnes nepersistovaných
  `vat_base` / `vat_amount` / `vat_total`)
- haléřové dorovnání podle invariantů z `docs/accounting.md` sekce 8
- extension `accounting_account` na `economy_items` + pole ve formuláři

### Mimo scope

- tabulka deníku, `AccountingEngine`, předpis, `documentEventHandlers`,
  `accounting_state` na hlavičce — to vše Fáze 2
- jakékoliv saldokonto, sklad, vazba pohybů na `item_type` (kromě validace
  `acc.entry` níže)
- UI zobrazení zaúčtování — Fáze 3

---

## Co implementovat

### W1 — Pohyby řádků

**W1.1 Config** — `modules/docs/core/config/rowOperations.jsonc`
→ cfgItem `docs.core.rowOperations`. Obsah přesně podle
`docs/accounting.md` sekce 2 (6 pohybů: `sale.services`, `sale.goods`,
`purchase.goods`, `purchase.services`, `purchase.other`, `acc.entry`).
Registrace v `module.jsonc` stejně jako ostatní configy modulu.

**W1.2 Sloupec** — do `docs_core_rows.jsonc` přidat za `row_kind`:

```jsonc
{
    "id": "operation",
    "name": "Operation",
    "name:cs": "Pohyb",
    "name:en": "Operation",
    "type": "enumString",
    "length": 40,
    "cfgItem": "docs.core.rowOperations",
    "nullable": true
}
```

**W1.3 Validace** — tvrdá, ve dvou místech:

1. `DocRowsDocument::validate` (nová metoda) — při uložení řádku přes
   sub-form:
   - `row_kind = 1`: `operation` povinný; musí existovat v cfgItem a mít
     `docTypes[{doc_type hlavičky}]` (doc_type načti přes `doc_head` FK)
   - `row_kind = 0`: `operation` musí být prázdný/NULL
   - `operation = "acc.entry"`: `item` povinný
2. `DocDocument` — při přechodu do stavu 40 zvaliduj všechny řádky dokladu
   stejnými pravidly (záchytná síť pro řádky vzniklé před touto změnou
   nebo importem). Chyby hlásit s `rows.{index}.operation` konvencí.

**W1.4 Default a select v UI** — `DocRowsForm`:

- select `operation` s options z cfgItem, filtrovaný podle `doc_type`
  hlavičky, řazený dle `docTypes[docType].order` vzestupně
- default pro nový řádek = pohyb s nejnižším `order`
- pro `row_kind = 0` pole skrýt/disablovat (vzor podmíněné viditelnosti
  viz cookbook)

**W1.5 Backfill testovacích dat** — součást `ds-upgrade` není potřeba;
jednorázově po nasazení spustit (ručně nebo jako poznámka v commit message):

```sql
UPDATE docs_core_rows r
JOIN docs_core_heads h ON h.id = r.doc_head
SET r.operation = CASE h.doc_type
    WHEN 'invno' THEN 'sale.services'
    WHEN 'invni' THEN 'purchase.services'
END
WHERE r.row_kind = 1 AND r.operation IS NULL;
```

### W2 — Domácí měna v řádcích

**W2.1 Sloupce** — do `docs_core_rows.jsonc` přidat za `vat_total` tři
systémové sloupce `vat_base_dom`, `vat_amount_dom`, `vat_total_dom`
(numeric 15,2, nullable, system). Do `docs_core_heads.jsonc` přidat za
`total_amount_dom` sloupec `total_rounding_dom` (numeric 15,2, default 0,
system, group `totals`).

**W2.2 Přepočet — pořadí a závaznost (top-down)**

Rozšířit compute pipeline v `DocDocument::beforeSave`. Závazné jsou head
totals, rekapitulace se dorovnává na head, řádky na rekapitulaci:

1. Per-řádek: `vat_base_dom = round(vat_base × rate, 2)`, analogicky
   `vat_amount_dom`; `rate = exchange_rate` (1.0 pro domácí měnu).
2. Rekapitulace per `vat_code`: `base_dom = round(base × rate, 2)`,
   `tax_dom = round(tax × rate, 2)` (dnešní chování v
   `buildVatRecapitulation` ověř a zachovej).
3. Head: `total_base_dom = Σ recap.base_dom`,
   `total_vat_dom = Σ recap.tax_dom` (tj. **ne** nezávislé `round(total ×
   rate)` jako dnes v `applyExchangeRate` — změna!),
   `total_amount_dom = round(total_amount × rate, 2)`,
   `total_rounding_dom = total_amount_dom − total_base_dom −
   total_vat_dom` (**odvozeně**, ne kurzem — absorbuje haléřový rozdíl
   a invariant platí konstrukčně).
4. Dorovnání řádků na rekapitulaci, per skupina `vat_code`:
   `diff = recap.base_dom − Σ rows.vat_base_dom` → přičti k poslednímu
   řádku skupiny s nenulovým `vat_base`; analogicky `vat_amount_dom` vs.
   `tax_dom`. Pak `vat_total_dom = vat_base_dom + vat_amount_dom`
   per řádek.

Výsledné invarianty (testovat!):

```
Σ rows.vat_base_dom   (per vat_code) == recap.base_dom
Σ rows.vat_amount_dom (per vat_code) == recap.tax_dom
Σ recap.base_dom == total_base_dom
Σ recap.tax_dom  == total_vat_dom
total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
```

Pozn.: u domácí měny (`rate = 1`) musí všechno vyjít beze změn chování —
`_dom` = kopie, `total_rounding_dom == total_rounding`.

**W2.3 Persistence počítaných sloupců řádků** — KLÍČOVÉ. Dnes se
`vat_base` / `vat_amount` / `vat_total` do `docs_core_rows` v DB nikdy
nezapisují (head počítá na lokální kopii kvůli ochraně child setu — viz
komentář v `beforeSave` a `docs/document-system.md` sekce 6). Fáze 2 ale
bude číst hodnoty řádků z DB, takže computed sloupce (stávající trojice
+ nová `_dom` trojice) musí být po každém přepočtu v DB aktuální.

Implementace: nový helper v `DocDocument` (např.
`persistRowComputedColumns(array $rowsForCompute)`) — přímé UPDATE
jednotlivých řádků podle `id`, **pouze** computed sloupce (`vat_base`,
`vat_amount`, `vat_total`, `vat_base_dom`, `vat_amount_dom`,
`vat_total_dom`). Nikdy nezapisovat řádky přes `$data['rows']`
(child-sync wipe riziko). Volat z obou přepočtových cest:

1. `DocRowsDocument::recomputeHeader` — do stávající transakce vedle
   update heads + recap přidat update řádků.
2. Uložení hlavičky (vč. přechodů stavů) — v `DocDocument::afterPersist`
   (řádky existují jen u existujícího dokladu; u insertu nového dokladu
   bez řádků je to no-op). Pozor: `beforeSave` musí výsledek
   `rowsForCompute` pro `afterPersist` zpřístupnit (property na instanci).

Ošetři případ řádků z payloadu bez `id` (nové řádky při full-sync save):
ty persistuje gateway sám, helper je přeskočí — ale ověř, že gateway
zapisuje i computed hodnoty (rows v `$data` jsou po `beforeSave`
přepočítané jen pokud `beforeSave` počítá nad `$data['rows']` referencí —
zkontroluj `resolveRowsForCompute` a případně uprav tak, aby se computed
hodnoty promítly do `$data['rows']`, KDYŽ tam rows jsou).

### W3 — Účet na položce

**W3.1 Extension** —
`modules/economy/accounting/extensions/ext-economy-items.jsonc` přesně
podle `docs/accounting.md` sekce 3.4 (sloupec `accounting_account`, int,
nullable, reference `economy_accounting_accounts`, group
`classification`). Registrace v `module.jsonc` modulu `economy.accounting`
(pole `extensions`) + dependency na `economy.items`.

**W3.2 Formulář položky** — `ItemsForm`: pole `accounting_account`
viditelné jen pro `item_type = 2` (podmíněná sekce/pole — vzor
`PersonsForm`). Picker (LookupInput) omezit na `account_level = 4` a
aktivní záznamy (`docStateMain` aktivní); jak se filtruje reference
picker zjisti z existujících forms / edit-forms.md — pokud filtr pickeru
zatím framework neumí, omezení vynutit aspoň validací v
`ItemsDocument`/`AccountDocument` stylu (validace: odkazovaný účet musí
mít `account_level = 4`).

---

## Hotovo když

1. `ds-upgrade` projde: nové sloupce v `docs_core_rows`,
   `docs_core_heads`, `economy_items` (extension).
2. Řádek faktury jde uložit jen s platným pohybem pro daný typ dokladu;
   textový řádek pohyb nemá; `acc.entry` bez položky neprojde. Select
   v řádkovém formuláři nabízí jen povolené pohyby ve správném pořadí
   a předvyplňuje default.
3. Po uložení/změně řádku i po uložení hlavičky jsou v DB
   (`docs_core_rows`) aktuální `vat_base`, `vat_amount`, `vat_total`
   i `_dom` trojice.
4. Všech 5 invariantů z W2.2 platí — pokryto testy:
   - CZK faktura (rate 1): `_dom` == cur, `total_rounding_dom ==
     total_rounding`
   - EUR faktura s kurzem 25,285 a více řádky ve dvou DPH sazbách:
     invarianty sedí, dorovnání skončilo na posledním nenulovém řádku
     skupiny
   - faktura se zaokrouhlením celkové částky: invariant
     `base + vat + rounding == amount` v dom
   - hlavička uložená bez řádků v payloadu: řádky v DB nejsou smazané
     (regrese child-sync) a `_dom` v DB sedí
5. Položka typu Účetní položka má ve formuláři pole Účet s filtrovaným
   pickerem; u jiných typů pole není vidět.
6. PHPUnit s úzkým filtrem (`--filter 'DocDocument|DocRows|Items'`)
   zelený; existující testy dokladů neporušené.

## Doporučené pořadí

1. W1.1 + W1.2 (config + sloupec) → ds-upgrade
2. W2.1 (sloupce) → ds-upgrade
3. W2.2 přepočet + W2.3 persistence + testy invariantů
4. W1.3 validace + W1.4 UI + W1.5 backfill
5. W3 extension + formulář
6. Celkový průchod testů, commit per balíček (W1 / W2 / W3 zvlášť)

## Rozhodnutí ✓

- Pohyb = `operation` (enumString 40) v `docs_core_rows`; `row_kind`
  zůstává strukturální. Definice v `docs.core`.
- Tvrdá validace pohybu při uložení řádku + záchytná síť při přechodu
  do 40. Měkké (účetní) kontroly až ve Fázi 2.
- Top-down dorovnání: head závazný, recap → head, rows → recap.
- `total_rounding_dom` odvozeně (`amount − base − vat` v dom), ne kurzem.
- Computed sloupce řádků se persistují přímými UPDATE mimo child-sync,
  z obou přepočtových cest.
- `accounting_account` jako extension z `economy.accounting` (FK na
  rozvrh), ne přímý sloupec items.

## Otevřené body

- Filtrace reference pickeru (`account_level = 4`) — pokud to LookupInput
  neumí deklarativně, stačí validace + poznámka do tasku Fáze 3 / backlogu.
- `enumString` select v sub-formu řádků filtrovaný podle hodnoty
  z hlavičky — pokud na to není hotový pattern, navrhni minimální řešení
  (options endpoint s parametrem `docType`) a zdokumentuj v
  `docs/edit-forms-cookbook.md`.
