# Bank import: identita výpisu — slévání výpisů ze stejného dne

> **Status:** navrženo · **Modul:** economy.bank (+ malá změna
> old_shipard runneru) · **Typ:** oprava chyby, obsahuje schema změnu
> **Návaznost:** `bank-import-fingerprint-collision.md`, task 20
> (old_shipard)

## Kontext

`StatementImportService::findOrCreateStatement()` páruje výpis výhradně
klíčem `(bank_account, period_start, period_end)` — **číslo výpisu
ignoruje**. Dva výpisy téhož účtu se stejným (jednodenním) obdobím se
slijí do jednoho: hlavička zůstane z prvního, transakce se nasypou do
obou → nevyrovnáno o netto druhého výpisu.

Ověřeno na lefreal (`4dnh-5isz-m4f5-gwa3`), účet 2 (archivní ČS-K CZK):

- nový výpis **425** (nr 16) = staré výpisy 32115016 + 32115021
  (oba 19. 2. 2015) → rozdíl +500 000,
- nový výpis **523** (nr 86) = staré 32115085 + 32115090
  (oba 12. 11. 2015) → rozdíl −8 671.

Rozsah: lefreal 2 páry (přesně odpovídá skupinám „stejný účet + den"
ve zdroji), msi-zlin 0. Riziko trvá pro alfu a ostrý provoz (souborový
import dvou výpisů z jednoho dne).

## Řešení (doporučené: plná identita přes external_id)

### 1. Schema: `economy_bank_statements`

Přidat `external_id VARCHAR(64) NULL` + unikátní index
`unq_external (bank_account, external_id)` — zrcadlí transakce. Schema
změna → `ds-upgrade` (změny schématu předcházejí kódu).

### 2. `StatementImportService::findOrCreateStatement()`

Pořadí párování:
1. `external_id` (je-li v payloadu) — přesná identita,
2. fallback `(bank_account, statement_number, period_start, period_end)`
   — number-aware; `statement_number NULL` páruje jen s NULL,
3. jinak create (s external_id, je-li k dispozici).

Backfill: nalezený výpis bez `external_id` ho při shodě kroku 2 dostane
(stejný vzor jako transakce). Souborový import bez external_id: krok 2 —
dva bezčíselné výpisy z téhož dne se stále slijí (zdokumentovat jako
známé omezení; reálné soubory číslo nesou).

### 3. old_shipard `BankStatementsRunner`

Do canonical hlavičky přidat `externalId: "old:{ndx}"` (applier
passthrough). Jednořádková změna + passthrough v
`core.exchange` `BankStatementApplier`.

### 4. Testy

- Dva apply: stejný účet + den, různá čísla výpisů → dva výpisy,
  transakce správně rozdělené, oba reconcilují.
- Idempotence: opakovaný apply téhož výpisu (external_id match) →
  žádný duplikát, self-healing stavu zachován.
- Backfill external_id na existující výpis při shodě kroku 2.
- Souborový import bez external_id: chování kroku 2.

## Oprava dat

Slité výpisy 425/523 na lefreal **tento fix sám nerozplete** (transakce
už visí na slitém výpisu; backfill `statement` nepřepisuje) — spraví je
plný re-import lefreal po vlně C (spolu s 252 koncepty z tasku 20).
Do té doby zůstávají 2 známé alerty. Alfa: zkontrolovat skupiny
„účet + den" ve zdrojích alfy v rámci rozhodnutí o opravě alfy.

## Hotovo když

- [ ] `economy_bank_statements.external_id` + unikátní index, ds-upgrade
      projde.
- [ ] Dva stejné-denní výpisy s různými čísly se importují jako dva.
- [ ] Migrace posílá `externalId` hlavičky výpisu; re-run je idempotentní.
- [ ] Testy zelené (úzké filtry).
