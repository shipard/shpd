# accbal Fáze 0 — platební identita v účetním deníku

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/accbal.md` (§3.5, §3.6).
> Saldokonto samo se **neimplementuje** — tohle je prerekvizita, která dostane
> párovací symboly a splatnost do deníku a sjednotí symboly napříč zdroji.

## Kontext

Saldokonto (`docs/accbal.md`) bude číst **výhradně účetní deník**
(`economy_accounting_journal`) — žádný read dokladů/transakcí při generování
saldo pohybů. Aby to šlo, musí deník nést párovací symboly a splatnost, které
dnes nemá (vědomě — `accounting.md` rozhodnutí #10). Zároveň jsou symboly
nekonzistentní napříč zdroji: hlavička dokladu má `payment_reference` (varchar
35) / `specific_symbol` / `constant_symbol`, kdežto bankovní transakce ještě
starý `symbol1/2/3` (varchar 10). Párovací klíč musí být porovnatelný
doklad↔transakce.

Tahle změna má hodnotu i nezávisle na saldu: filtr účetního deníku za
variabilní symbol („najdi všechno na VS 12345") je běžná účetní potřeba.

## Cíl

1. Deník `economy_accounting_journal` nese `payment_reference`,
   `specific_symbol`, `constant_symbol`, `due_date` — plní je oba účtovací
   enginy.
2. Bankovní transakce mají symboly přejmenované na konvenci dokladů
   (`payment_reference` / `specific_symbol` / `constant_symbol`), varchar 35 / 20 / 10.
3. `JournalViewer` umí filtrovat a fulltextově hledat za `payment_reference`
   a zobrazuje symboly v detailu.

## Návaznost

- **Odemyká** accbal Fázi 1 (nastavení) a Fázi 2 (generátor pohybů).
- **Vědomě mimo tento task: událost `journalWritten`** (`docs/accbal.md` §4.1).
  Je to trigger, který spotřebuje až generátor pohybů ve Fázi 2 — postavit
  pub/sub bez odběratele je spekulativní. Přesune se na **začátek PRD Fáze 2**,
  kde vznikne i s prvním reálným handlerem. (Pokud chceš jinak, je to k veto —
  `docs/accbal.md` §9 ji zatím řadí pod Fázi 0.)
- **old_shipard bank runner** (`docs/bank.md` §7) zatím neexistuje; až ho budeš
  psát, musí použít nová jména symbolů (lockstep — nová strana applieru tady
  deployuje první).

## Před implementací přečti

- `docs/accbal.md` §3.5 (sloupce deníku), §3.6 (rename), §10 rozhodnutí 3/11
- `docs/accounting.md` §6 (deník), §8 (měny — politika „každá částka v obou měnách")
- `docs/bank.md` §3.1 (transakce), §6 (mikroengine)
- `modules/economy/accounting/tables/economy_accounting_journal.jsonc`
- `modules/economy/accounting/src/AccountingEngine.php` — metoda `writeResult()`
  (insert do `economy_accounting_journal`, kde se už razítkuje `doc_type`/
  `doc_number`/`currency` z `$head`)
- `modules/economy/bank/src/BankTransactionAccountingEngine.php` — `writeResult()`
  (insert) + `buildText()` (čte `$tx['symbol1']`)
- `modules/economy/accounting/src/JournalViewer.php` — `selectRows()` (SELECT +
  fulltext blok), `getFilters()`, `renderDetail()`
- `modules/economy/bank/tables/economy_bank_transactions.jsonc` (+`.md`)
- `modules/economy/bank/src/Import/ParsedTransaction.php`
- `modules/economy/bank/src/Import/StatementImportService.php` (ř. ~223 insert, ~463 fingerprint)
- `modules/economy/bank/src/Import/Parsers/{CbaXmlParser,FioJsonParser,GpcParser}.php`
- `modules/core/exchange/schemas/shpd.bank.statement.v1.jsonc` **a** `.json` (twin)
- `modules/core/exchange/src/Bank/BankStatementApplier.php` (ř. ~234)
- `modules/economy/bank/forms/economy_bank_transactions.jsonc`
- `modules/economy/bank/src/{BankTransactionsViewer,BankTransactionDocument}.php`
- `tests/Unit/Module/Core/Exchange/Schema/SchemaDriftTest.php` (vynucuje jsonc⇔json sync)

## Scope

**Uvnitř:** sloupce deníku + razítkování v obou enginech; přejmenování symbolů
na bankovních transakcích včetně všech navazujících (DTO, parsery, service,
exchange schéma, applier, formulář, viewer, document, table `.md`); rozšíření
`JournalViewer`; aktualizace dotčených testů; re-import DS přes `ds-reset`.

**Mimo:** událost `journalWritten` (Fáze 2); jakákoliv saldo tabulka/logika;
obohacení CAMT o strukturovanou RF referenci (viz Otevřené body); old_shipard
runner.

## Co implementovat

### A. Přejmenování symbolů na bankovních transakcích

Lockstep rename `symbol1/2/3 → payment_reference/specific_symbol/constant_symbol`:

- **Tabulka** `modules/economy/bank/tables/economy_bank_transactions.jsonc`:
  - `symbol1` → `payment_reference`, **varchar 35**, name:cs „Variabilní symbol"
  - `symbol2` → `specific_symbol`, **varchar 20**, name:cs „Specifický symbol"
  - `symbol3` → `constant_symbol`, **varchar 10**, name:cs „Konstantní symbol"
  - aktualizovat `.md` doc tabulky.
- **DTO** `ParsedTransaction.php`: konstruktor params `symbol1/2/3` →
  `paymentReference` / `specificSymbol` / `constantSymbol` (typy zachovat
  `?string`).
- **Parsery** (`CbaXmlParser` ř.152, `FioJsonParser` ř.84, `GpcParser` ř.75 +
  interní klíče ř.119) — pojmenované argumenty + GPC interní array klíče
  přejmenovat.
- **StatementImportService.php**: insert mapa (ř.223–225) →
  `'payment_reference' => $this->cap($tx->paymentReference, 35)`,
  `'specific_symbol' => $this->cap($tx->specificSymbol, 20)`,
  `'constant_symbol' => $this->cap($tx->constantSymbol, 10)`; fingerprint
  (ř.463–464) přejmenovat na nová pole (pořadí hodnot ve fingerprintu zachovat,
  ať se neztratí dedup kompatibilita v rámci běhu importu).
- **Exchange schéma** `shpd.bank.statement.v1.jsonc` **i** `.json`: properties
  `symbol1/2/3` → `payment_reference/specific_symbol/constant_symbol`
  (SchemaDriftTest hlídá sync obou souborů — ověř `git diff` na obou).
- **Applier** `BankStatementApplier.php` (ř.234): named args + čtení
  `$t['payment_reference']` atd.
- **Formulář** `economy_bank_transactions.jsonc` (ř.34–36): column refs.
- **Viewer** `BankTransactionsViewer.php`: SELECT (ř.39), fulltext sloupce
  (ř.70), `renderRow` VS (ř.110), `renderDetail` (ř.175–177).
- **Document** `BankTransactionDocument.php` (ř.59): trim list.

### B. Nové sloupce účetního deníku

`modules/economy/accounting/tables/economy_accounting_journal.jsonc` — přidat
(vše nullable, system, do skupiny za `currency`/context):

| sloupec | typ |
|---|---|
| `payment_reference` | varchar 35, nullable |
| `specific_symbol` | varchar 20, nullable |
| `constant_symbol` | varchar 10, nullable |
| `due_date` | date, nullable |

+ index `idx_payment_reference` na `payment_reference`. Aktualizovat doc
komentář v hlavičce tabulky (dnes říká „žádné saldokontní sloupce") a
`docs/accounting.md` §6 tabulku + dodatek do logu rozhodnutí (#10 se částečně
obrací — důvod zaznamenat, ať tester nepřehlédne).

### C. Razítkování v enginech

- **`AccountingEngine::writeResult()`** — do insert pole přidat (z `$head`,
  konstantní přes doklad, vedle `doc_type`/`doc_number`/`currency`):
  `payment_reference`, `specific_symbol`, `constant_symbol`, `due_date`.
- **`BankTransactionAccountingEngine::writeResult()`** — do insert pole přidat
  z `$tx`: `payment_reference` (= přejmenovaný), `specific_symbol`,
  `constant_symbol`; `due_date` = `null` (transakce splatnost nemá).
  `buildText()` přepsat `$tx['symbol1']` → `$tx['payment_reference']`.

### D. JournalViewer

`modules/economy/accounting/src/JournalViewer.php`:

- `selectRows()`: do SELECT doplnit `j.payment_reference`; do fulltext bloku
  přidat `OR j.payment_reference LIKE %s` (+ param).
- `getFilters()`: nový text filtr `payment_reference` (label cs „Variabilní
  symbol" / en „Payment reference"); v `selectRows` filtr větev (prefix nebo
  rovnost — zvol rovnost/`LIKE val%`, VS se hledá přesně).
- `renderDetail()`: do skupiny „Doklad" (nebo nová skupina „Platba") přidat
  `payment_reference`, `specific_symbol`, `constant_symbol`, `due_date`
  (jen neprázdné, přes `addItem`).

### E. Migrace DS a testy

- Rename sloupců **není** bezpečná `ds-upgrade` operace (drop+add = ztráta dat).
  Cesta: **`ds-reset`** + re-import výpisů (jsme v alfě, vzor „re-import přes
  ds-reset, ne backfill"). `ds-upgrade` na deníku (sloupce B) je čistě
  additivní a bezpečný.
- Testy k aktualizaci: `CbaXmlParserTest`, `FioJsonParserTest`, `GpcParserTest`
  (očekávané názvy polí DTO), `SchemaValidatorTest`/`SchemaDriftTest` (schéma),
  `BankPhase1Test` (insert mapa). Přidat aspoň jeden test, že obě enginy
  orazítkují `payment_reference` do deníku (doklad i transakce).

## Hotovo když

- `ds-upgrade` přidá 4 sloupce do deníku; faktura ve stavu 40 má v řádcích
  deníku vyplněný `payment_reference` + `due_date` (z hlavičky), bankovní
  transakce ve stavu 40 má `payment_reference` (`due_date` NULL).
- V `economy_bank_transactions` neexistují sloupce `symbol1/2/3`; import výpisu
  (CAMT/GPC/FIO) i exchange apply plní `payment_reference` (až 35 znaků) /
  `specific_symbol` / `constant_symbol`.
- `JournalViewer`: filtr a fulltext za VS vrací řádky; detail řádku ukazuje
  symboly + splatnost.
- `grep -rn 'symbol1\|symbol2\|symbol3' modules/ tests/` nevrací nic
  v bankovním/exchange kontextu (zbývá jen historie, pokud vůbec).
- Celá test suite zelená (bank + exchange + accounting filtry úzce, ať
  neběží naprázdno — vzor `--filter` z paměti).

## Doporučené pořadí

1. **Rename A** celý naráz (je mechanický a široký) → `git diff` na schématu
   (jsonc+json) → spustit bank + exchange testy.
2. **Sloupce deníku B** → `ds-upgrade` na dev DS.
3. **Razítkování C** (oba enginy) + test razítkování.
4. **JournalViewer D**.
5. **`ds-reset` + re-import** dev DS, ruční ověření faktura/transakce → deník.
6. Doladit testy E, celá suite.

## Rozhodnutí ✓

1. Symboly + splatnost **patří do deníku** — drží „saldo zná jen deník", zlevní
   generátor (žádný join na zdroj) a dá filtr deníku za VS. Obrací část
   `accounting.md` #10. *(David ✓)*
2. Bankovní symboly přejmenovat na konvenci dokladů, **varchar 35** u
   `payment_reference` (RF/EndToEndId se nesmí osekat). *(David ✓)*
3. `quantity`/`unit` se do salda ani deníku nepřidávají (vypuštěno). *(David ✓)*
4. Rename přes **`ds-reset`** + re-import, ne backfill (alfa).
5. Událost `journalWritten` **mimo tento task** — do Fáze 2 s prvním handlerem.

## Otevřené body

- **CAMT strukturovaná RF reference** — dnes mechanický rename
  (`CbaXmlParser` → `payment_reference`). Mapování ISO 20022
  `RmtInf/Strd/CdtrRefInf` (RF Creditor Reference) do `payment_reference`, když
  je přítomné, je refinement parseru — samostatně, ne blokující.
- **`payment_reference` filtr: prefix vs. rovnost** — návrh rovnost/`val%`;
  potvrdit při implementaci dle chování ostatních filtrů deníku.
- **Fingerprint dedup** — po přejmenování ověřit, že se nezměnilo pořadí/obsah
  hashovaných polí (jinak by re-import po `ds-reset` vyrobil duplicitní otisky
  proti případně zachovaným datům — v alfě nízké riziko, ale ohlídat).
