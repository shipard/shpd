# accbal Fáze 2a — událost `journalWritten` (core)

> PRD pro jednu Claude Code session. Design: `docs/accbal.md` (§4.1, §10 #8).
> Toto je core mechanismus odložený z Fáze 0 — sám o sobě nemá saldo logiku.
> Konzumenta (generátor pohybů) přidá Fáze 2b.

## Kontext

Saldokonto se nesmí zaháknout na `stateChanged` dokladu, protože:
1. má pracovat **jen s deníkem** (`docs/accbal.md` §1.2), a
2. **přeúčtování** (reaccount) přepisuje deník **bez změny docState** — jde
   přes endpoint `POST /_accounting/reaccount` resp. `/_bank/reaccount` přímo
   do enginu, `stateChanged` se nevyvolá. Po spárování (Fáze 3) matcher spustí
   přeúčtování bankovní transakce (clearing → 311) — kdyby saldo poslouchalo
   `stateChanged`, tuhle re-derivaci by minulo.

Proto zavádíme událost **`journalWritten(sourceKind, sourceId)`**, kterou
vyšle **účtovací engine** po každém (pře)zápisu nebo vymazání deníku zdroje.
Mechanismus je přesná obdoba `documentEventHandlers` (`stateChanged`/
`beforeDelete`), jen fire-point je engine, ne TableGateway.

## Cíl

1. Core mechanismus `journalWritten`: interface + abstract + dispatcher +
   loader + registrace `journalEventHandlers` v `module.jsonc` — zrcadlo
   `documentEventHandlers`.
2. Oba enginy (`AccountingEngine`, `BankTransactionAccountingEngine`) vyšlou
   `journalWritten` po zápisu deníku (`writeResult`) **i** po jeho vymazání
   (`clearDocument`/`clearTransaction`).
3. Dispatcher je proplumbovaný do všech 4 míst konstrukce enginu (2 handlery,
   2 reaccount controllery) + migračních cest.

## Návaznost

- **Prerekvizita:** Fáze 0 + 1 hotové.
- **Odemyká:** Fázi 2b (accbal `JournalToBalanceHandler` se zaregistruje jako
  `journalEventHandlers` a začne pohyby generovat).
- Po této fázi **žádný handler `journalWritten` reálně nic nedělá** (kromě
  testovacího) — to je v pořádku, mechanismus se ověří testem.

## Před implementací přečti

- `docs/accbal.md` §4.1 (trigger), §10 rozhodnutí #8, §11 (otevřený bod:
  synchronně vs. po commitu — řeší se zde, viz Rozhodnutí)
- Celé zrcadlené jádro:
  - `src/Core/Document/DocumentEventHandler.php` (interface)
  - `src/Core/Document/AbstractDocumentEventHandler.php` (settery služeb)
  - `src/Core/Document/DocumentEventDispatcher.php` (dispatch, lazy instanciace,
    error semantika)
  - `src/Api/DocumentEventHandlerLoader.php` (sběr registrací z module.jsonc)
  - `src/Core/Module/ModuleDefinition.php` — property `documentEventHandlers`
    (ř. 25) + parsing (ř. 120–144)
- Fire-pointy a konstrukce enginů:
  - `modules/economy/accounting/src/AccountingEngine.php` — `writeResult()`
    (commit), `clearDocument()`
  - `modules/economy/bank/src/BankTransactionAccountingEngine.php` —
    `writeResult()`, `clearTransaction()`
  - `modules/economy/accounting/src/DocsHeadsEventHandler.php:63`
    (`new AccountingEngine(...)`)
  - `modules/economy/accounting/src/AccountingController.php:52` (reaccount)
  - `modules/economy/bank/src/BankTransactionEventHandler.php:64`
  - `modules/economy/bank/src/BankController.php:119` (reaccount) — controller
    už přijímá `?DocumentEventDispatcher` (ř. 41)
- Plumbing dispatcheru (vzor, kde se staví/předává `DocumentEventDispatcher`):
  - `src/Command/DataSource/BankImportStatementCommand.php:86`
    (`DocumentEventHandlerLoader::load(...)` — CLI)
  - `src/Api/Controller/FormController.php` (ř. 143/515 přijímá dispatcher,
    ř. 218/527 ho předává do `new TableGateway`) — **dohledej, kde se pro API
    request flow staví** (kdo plní `$eventDispatcher` do FormControlleru) a
    mirror tam i journal dispatcher
  - appliery: `modules/core/exchange/src/Document/DocumentApplier.php` (ř. 133/167),
    `modules/core/exchange/src/Bank/BankStatementApplier.php` (ř. 60),
    `modules/economy/bank/src/Import/StatementImportService.php` (ř. 64/68)

## Scope

**Uvnitř:** 4 nové core třídy `Journal*`; rozšíření `ModuleDefinition`,
`AbstractDocumentEventHandler`, `DocumentEventDispatcher`,
`DocumentEventHandlerLoader`; emise z obou enginů; proplumbování dispatcheru
do 4 míst konstrukce enginu + bootstrap/migrační cesty; test mechanismu.

**Mimo:** jakákoliv saldo tabulka/logika/handler (Fáze 2b); UI.

## Co implementovat

### A. Core mechanismus (zrcadlo document events)

Nové soubory v `src/Core/Document/` (mirror přesně dle vzorů):

- **`JournalEventHandler.php`** — interface:
  ```php
  interface JournalEventHandler {
      /** Po commitu (pře)zápisu nebo vymazání deníku zdroje. */
      public function onJournalWritten(string $sourceKind, int $sourceId): void;
  }
  ```
- **`AbstractJournalEventHandler.php`** — `protected ?\Dibi\Connection $db`,
  `?ConfigRuntime $config`, `?DataSourceConfig $dsConfig` + settery (mirror
  `AbstractDocumentEventHandler`); prázdná default impl `onJournalWritten`.
- **`JournalEventDispatcher.php`** — mirror `DocumentEventDispatcher`:
  registrace `{class, events}` (bez `table` — journal events nejsou per-tabulka),
  lazy `instantiate()` + injekce služeb, metoda
  `dispatchJournalWritten(string $sourceKind, int $sourceId)`.
  **Error semantika jako `stateChanged`:** výjimku handleru **zaloguj a spolkni**
  (commit deníku už proběhl — saldo nesmí shodit účtování), další handlery běží
  dál. *(Rozhodnutí #1.)*
- **`src/Api/JournalEventHandlerLoader.php`** — mirror
  `DocumentEventHandlerLoader`: sesbírá `journalEventHandlers` z resolvovaných
  modulů → `JournalEventDispatcher`.

### B. Registrace v module.jsonc

- `ModuleDefinition.php`: přidat `public readonly array $journalEventHandlers = []`
  (vedle `documentEventHandlers`, ř. 25) + parsing (mirror ř. 120–144, ale
  registrace je `{class, events}` — `table` se nevyžaduje; `events` default
  `["journalWritten"]`).

### C. Emise z enginů

Oba enginy: přidat konstruktor param `?JournalEventDispatcher $journalEvents = null`
(default null → testy a místa bez salda fungují beze změny).

- **`AccountingEngine`**: po úspěšném `commit()` ve `writeResult()` (po
  transakci, ne uvnitř — viz Rozhodnutí #1) zavolat
  `$this->journalEvents?->dispatchJournalWritten('doc', $docHeadId)`. Stejně na
  konci `clearDocument()` (deník vymazán → saldo musí pohyby zdroje odebrat).
- **`BankTransactionAccountingEngine`**: analogicky
  `dispatchJournalWritten('bankTransaction', $txId)` ve `writeResult()` a
  `clearTransaction()`.

> `sourceKind` shodný s `economy_accounting_journal.source_kind`
> (`doc` | `bankTransaction`).

### D. Proplumbování dispatcheru

JournalEventDispatcher se staví **na stejných místech jako DocumentEventDispatcher**
(`JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config)`).

Cesta k handlerům dokladů (která konstruují engine):
- `DocumentEventHandlerLoader::load(...)` dostane nový param
  `?JournalEventDispatcher $journalEvents = null`, předá ho do
  `new DocumentEventDispatcher(...)`.
- `DocumentEventDispatcher` konstruktor přijme `?JournalEventDispatcher`, v
  `instantiate()` ho injektne do handleru přes nový setter.
- `AbstractDocumentEventHandler`: přidat `protected ?JournalEventDispatcher
  $journalEvents` + `setJournalEvents()`.
- `DocsHeadsEventHandler::engine()` →
  `new AccountingEngine($this->db, $this->config, $this->journalEvents)`.
- `BankTransactionEventHandler` → totéž s bankovním enginem.

Cesta přes reaccount controllery:
- `AccountingController` / `BankController`: přijmout `?JournalEventDispatcher`
  (jako už přijímají `?DocumentEventDispatcher`) a předat do enginu na ř. 52 /
  119.

Bootstrap a migrace — všude, kde se dnes staví/předává DocumentEventDispatcher,
postavit a protáhnout i JournalEventDispatcher:
- `BankImportStatementCommand:86` (CLI).
- API request flow plnící `FormController::$eventDispatcher` (dohledat build
  point a mirror).
- `DocumentApplier`, `BankStatementApplier`, `StatementImportService` — protáhnout
  do `DocumentEventHandlerLoader::load(..., $journalEvents)`, ať migrace
  (transakce vzniklá ve stavu 40 → `BankTransactionEventHandler` → engine)
  také vyšle `journalWritten`.

### E. Test

`tests/Unit/Module/Core/Document/JournalEventDispatcherTest.php` (vzor
existujících testů dispatcheru, pokud jsou) + integrační:

- registrovaný testovací `JournalEventHandler` dostane `('doc', id)` po
  přechodu dokladu do 40, po **reaccountu** (bez změny stavu) a po odchodu ze
  40 (clear).
- totéž `('bankTransaction', id)` pro transakci.
- výjimka v handleru `journalWritten` **neshodí** účtování (commit prošel,
  doklad je ve stavu 40 / `accounting_state` zapsaný).

## Hotovo když

- Engine po (pře)zápisu i vymazání deníku vyšle `journalWritten` se správným
  `(sourceKind, sourceId)` — ověřeno testem pro doklad i transakci, vč.
  reaccount cesty.
- Registrace `journalEventHandlers` v module.jsonc se načte
  (`JournalEventHandlerLoader`) a dispatchne.
- Výjimka journal handleru je zalogovaná a spolknutá; účtování nedotčené.
- Bez registrovaného handleru se nic neděje (žádná regrese účtování/banky);
  celá suite zelená (accounting + bank + core/document úzce přes `--filter`).

## Doporučené pořadí

1. 4 core třídy `Journal*` + `ModuleDefinition.journalEventHandlers`.
2. Emise v enginech (param + fire) — s default null nic nerozbije.
3. Plumbing: `AbstractDocumentEventHandler`/`DocumentEventDispatcher`/
   `DocumentEventHandlerLoader` → handlery → engine.
4. Reaccount controllery + bootstrap + migrační cesty.
5. Test mechanismu (vč. reaccount a clear).

## Rozhodnutí ✓

1. **Fire po commitu, výjimka se spolkne** (mirror `stateChanged`), ne
   synchronně v transakci deníku. Drží filozofii „účtování/saldo se neblokují":
   chyba saldo generování nesmí rollbacknout účetní zápis. Stálé saldo se
   dořeší re-derivací/alertem (Fáze 2b). *(Řeší otevřený bod `docs/accbal.md` §11.)*
2. Fire-point = **engine** (ne `stateChanged`, ne controller), aby reaccount
   bez změny stavu událost taky vyslal. *(David ✓ — rozhodnutí #8 dokumentu.)*
3. `journalEventHandlers` registrace je `{class, events}` bez `table` (events
   nejsou per-tabulka); `events` default `["journalWritten"]` — prostor pro
   budoucí journal události.
4. Default `?JournalEventDispatcher = null` v enginech → testy a non-saldo
   kontexty fungují beze změny.

## Otevřené body

- **Build point dispatcheru pro API flow** — `FormController` ho jen přijímá;
  dohledat, kde se pro request staví `DocumentEventDispatcher`, a postavit
  `JournalEventDispatcher` tamtéž. (Jediný loader call dnes je v
  `BankImportStatementCommand`.)
- **Sjednocení dvou dispatcherů** — zvážit, jestli `DocumentEventHandlerLoader`
  a `JournalEventHandlerLoader` nesloučit do jednoho průchodu modulů (drobná
  optimalizace, ne nutnost; teď radši dvě paralelní jasná zrcadla).
