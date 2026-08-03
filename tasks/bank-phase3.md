# Banka — Fáze 3: účetní mikroengine + UI účtování

**Stav:** hotovo

> **Stav: ✅ Hotovo.** Commity: `20ae31d` (extrakce sdíleného
> `AccountMaskResolver`), `3fdeb54` (W1 `BankTransactionAccountingEngine`),
> `595720d` (W2 `BankTransactionEventHandler` + lifecycle), `08e5ae5`
> (W5 alert `BankAccountingErrorsCheck`), `fae743d` (W3 endpoint Přeúčtovat +
> W4 UI tab Zaúčtování).
>
> Pozn.: `BankTransactionDocument` nově sleduje přechod `docState`
> (`trackStateChange`), aby `TableGateway` dispatchoval `stateChanged` (base
> `Document` to nedělá). Na test DS bylo nutné ručně uvolnit
> `economy_accounting_journal.doc_head` na NULL (`ds-upgrade` NOT NULL→NULL
> nerelaxuje; fresh DS to má správně).

## Kontext

Fáze 1 dala datový model a generalizovaný deník, Fáze 2 plní transakce
importem (stav **Nová (10)**, `operation` default `payment.in`/`payment.out`).
Fáze 3 transakce **účtuje**: při přechodu do stavu **Zaúčtováno (40)** vygeneruje
řádky účetního deníku, při odchodu/smazání je uklidí. Účtuje se bankovní strana
(221xxx) + protistrana dle `operation` (clearing 261200/261300 pro nespárované,
reálný účet pro poplatky/úroky) — přesně dle `docs/bank.md` §6.

**Klíčové zjištění (ověřeno čtením kódu):** spouštěč účtování je už dnes plně
generický. `TableGateway` je instanciovaný per-tabulka a po commitu volá
`DocumentEventDispatcher::dispatchStateChanged($this->tableId, …)` při každém
stavovém přechodu (a `dispatchBeforeDelete` uvnitř mazací transakce).
`documentEventHandlers` v `module.jsonc` se registrují přes `{table, class,
events}` na libovolnou tabulku. **Fáze 3 tedy nesahá do `core`** — jen
zaregistruje handler na `economy_bank_transactions`, stejně jako účetnictví na
`docs_core_heads`.

Šest pracovních balíčků:

- **W1** — `BankTransactionAccountingEngine` (mikroengine)
- **W2** — `BankTransactionEventHandler` + registrace
- **W3** — REST endpoint `/_bank/reaccount` + `BankController`
- **W4** — UI tab Zaúčtování + akce Přeúčtovat (`BankTransactionsViewer`)
- **W5** — alert check chyb účtování
- **W6** — testy

## Návaznost

- Návrhový dokument `docs/bank.md` §6 (mikroengine, dvě strany, clearing účty,
  nulová kontrola, lifecycle) — **závazný**.
- Vzor je účetnictví dokladů: `DocsHeadsEventHandler` + `AccountingEngine`
  (`accountDocument`/`clearDocument`/`writeResult`) — **zrcadlíme**, ne
  přepisujeme. Bankovní engine je jednodušší (dva řádky stejné částky → vždy
  vyrovnané, žádná penny reconciliation).
- Staví na: `economy_bank_transactions` (`operation`, `direction`, `amount`,
  `amount_dom`, `currency`, `date_transaction`, `partner`, `bank_account`,
  `accounting_state`/`accounting_messages` — Fáze 1), generalizovaný deník
  (`source_kind`, `bank_transaction` FK — Fáze 1), `txOperations` (`cat`
  per pohyb — Fáze 1), předpis `accountingRules.cz.jsonc` sekce `accounts`
  s kategoriemi `bank.*` → 261200/261300/568/662/562 (Fáze 1), clearing
  účty v seedu osnovy (Fáze 1), `bank_account.accounting_account` (221xxx,
  Fáze 1).
- Saldo zůstává odložené — Fáze 3 účtuje fakturové úhrady na clearing;
  přegenerace clearing → 311/321 přijde s saldem (mikroengine se pak rozšíří
  o dohledání platby/účtu — `docs/bank.md` §6.5).

## Před implementací přečti

- `modules/economy/accounting/src/AccountingEngine.php` — **hlavní vzor**:
  `accountDocument()` (load → kroky → group → `writeResult`), `writeResult()`
  (DELETE + INSERT deníku + update stavu/hlášení v transakci — **přesný seznam
  sloupců INSERTu**), `clearDocument()`, `resolveMask()` (LIKE, `account_level
  = 4`, aktivní, dle data), error-tolerantní `is_error` řádky + `addMessage`,
  stav 1 (OK) / 2 (cokoliv v messages)
- `modules/economy/accounting/src/DocsHeadsEventHandler.php` — **vzor handleru**:
  `onStateChanged` (do 40 → engine, ze 40 → clear), `onBeforeDelete`,
  `markEngineError` (catch → log + accounting_state 2)
- `modules/economy/accounting/src/AccountsLookup.php` — sdílené dohledání
  účtu dle masky (W1 použít, ne duplikovat resolveMask)
- `modules/economy/accounting/src/AccountingController.php` +
  `src/Api/Router.php` (~ř. 176) — **vzor endpointu** `/_accounting/reaccount`
- `modules/docs/core/src/DocsHeadsViewer.php` — `buildAccountingTab()` +
  `accountingJournalTable()` + akce `reaccount` (~ř. 300–460) — **vzor UI tabu**
- `modules/economy/accounting/src/Checks/AccountingErrorsCheck.php` — **vzor
  alert checku** (W5)
- `modules/economy/bank/src/BankTransactionsViewer.php` — `renderDetail()`
  (sem přidat tab), `getToolbarActions()`; `BankTransactionDocument.php`
- `modules/economy/bank/config/txOperations.jsonc` (`cat` per pohyb),
  `modules/economy/accounting/config/accountingRules.cz.jsonc` (sekce
  `accounts`, kategorie `bank.*`)
- `modules/economy/bank/extensions/economy_codebooks_bank_accounts.jsonc`
  (`accounting_account`), `economy_accounting_journal.jsonc` (`source_kind`,
  `bank_transaction`)
- `modules/economy/codebooks/tables/economy_codebooks_fiscal_months.jsonc`
  (`fiscal_year`, `date_begin`, `date_end`) — W1 dohledání fiskálního období
- `docs/bank.md` §6, `docs/accounting.md` §7 (lifecycle, error filozofie)

## Scope

### V scope

- mikroengine: transakce ve stavu 40 → 2 řádky deníku (banka + protistrana),
  idempotentní DELETE+INSERT, `accounting_state`/`accounting_messages`
- event handler na `economy_bank_transactions` (stateChanged + beforeDelete)
- REST endpoint Přeúčtovat + UI tab Zaúčtování v detailu transakce
- alert na transakce ve stavu 40 s `accounting_state = 2`
- integrační testy

### Mimo scope

- **saldo** párování a přegenerace clearing → 311/321 — pozdější fáze
- **auto-klasifikace** `operation` (poplatek/úrok dle protistrany) — `operation`
  se zatím nastavuje importem (default dle směru) nebo ručně ve formuláři
- **převod mezi vlastními účty** (protiúčet je náš jiný účet → 261100) —
  zatím účtovat jako ostatní na clearing (viz Otevřené body)
- účtování výpisu (`economy_bank_statements`) — výpis se neúčtuje, je evidence
- migrace — Fáze 4

---

## Co implementovat

Vše v `economy.bank`, namespace `Shipard\Module\Economy\Bank\`.

### W1 — `BankTransactionAccountingEngine`

`src/BankTransactionAccountingEngine.php`. Konstruktor jako `AccountingEngine`
(`\Dibi\Connection $db`, `?ConfigRuntime $config`). Per-run `$messages`.

**W1.1 `accountTransaction(int $txId): array`** (vzor `accountDocument`):

1. Načíst transakci JOIN bankovní účet:
   ```sql
   SELECT t.*, ba.accounting_account, ba.currency AS account_currency
   FROM economy_bank_transactions t
   JOIN economy_codebooks_bank_accounts ba ON ba.id = t.bank_account
   WHERE t.id = %i
   ```
   Nenalezeno → `\DomainException`.
2. **Fiskální období** z `date_transaction`: dohledat
   `economy_codebooks_fiscal_months` kde `date_begin <= date_transaction <=
   date_end`; z něj `fiscal_year` (+ id měsíce). Nenalezeno → message
   `fiscal_period_missing` + `writeResult` s prázdným deníkem (vzor: doklad
   bez období negeneruje deník). **Reprezentaci `fiscal_year`/`fiscal_month`
   v řádku deníku sjednotit s tím, co ukládá `docs_core_heads`** (ověřit, zda
   hodnota nebo FK — řádek deníku musí být konzistentní napříč zdroji).
3. **Bankovní strana:** účet z `accounting_account` (FK → fetch `{id, number}`
   z `economy_accounting_accounts`). Prázdné/neexistuje/neaktivní → chybový
   řádek (`account_number` `221???`, `is_error`, message
   `bank_account_not_found`).
4. **Protistrana:** `operation` (prázdné → default dle `direction`:
   1→`payment.in`, 2→`payment.out`) → `cat` z cfgItem
   `economy.bank.txOperations` → maska z předpisu `accounts` (kde `cat`
   sedí) → `AccountsLookup` dle `date_transaction`. Nenalezeno → chybový
   řádek (maska s `?`, `is_error`, message `account_not_found`).
5. **Strany a částky:**
   - `direction = 1` (příjem): banka **MD** (side 0), protistrana **DAL**
     (side 1)
   - `direction = 2` (výdaj): banka **DAL** (side 1), protistrana **MD**
     (side 0)
   - `money_dr`/`money_cr` = `amount_dom` (domácí); `money_dr_cur`/
     `money_cr_cur` = `amount` (měna transakce); `currency` = měna transakce
   - obě strany stejná částka → deník je z principu vyrovnaný (žádná penny
     reconciliation), přesto na konci ověřit `Σ MD == Σ DAL` (pojistka,
     message `unbalanced` při neshodě)
6. **Zápis** přes `writeResult` (W1.2).

**W1.2 `writeResult`** (vzor stejnojmenné metody, ale klíč `bank_transaction`):
DELETE + INSERT + update stavu v jedné transakci.

- `DELETE FROM economy_accounting_journal WHERE bank_transaction = %i`
- INSERT každý řádek se **stejnou sadou sloupců jako doklad**, s rozdíly:
  - `source_kind = 'bankTransaction'`, `bank_transaction = txId`,
    `doc_head = NULL`
  - `doc_type = NULL`, `doc_number =` číslo navázaného výpisu (`statement` →
    `statement_number`) nebo `NULL` (traceabilita)
  - `accounting_date = date_transaction`, `fiscal_year`/`fiscal_month` z W1.1,
    `currency` = měna transakce
  - `account`/`account_number`/`is_error`/`operation`/`money_*`/`partner`/
    `text` jako doklad
  - `text` = popisek pohybu (`txOperations[operation].name:cs`) +
    `counterparty_name`/symboly, zkrátit na 200
- `UPDATE economy_bank_transactions SET accounting_state = (messages ? 2 : 1),
  accounting_messages = …` (vzor doklad)

**W1.3 `clearTransaction(int $txId)`** (vzor `clearDocument`): DELETE řádků
deníku transakce + `accounting_state = 0`, `accounting_messages = NULL`.

### W2 — Event handler

**W2.1** `src/BankTransactionEventHandler.php extends
AbstractDocumentEventHandler` (vzor `DocsHeadsEventHandler`):

- `onStateChanged`: `newState === 40` → `engine()->accountTransaction($id)`
  v try/catch (chyba → log + `markEngineError` na `economy_bank_transactions`:
  `accounting_state = 2`, message `engine_error`); `oldState === 40` →
  `engine()->clearTransaction($id)`
- `onBeforeDelete`: `DELETE FROM economy_accounting_journal WHERE
  bank_transaction = %i`
- `engine()` → `new BankTransactionAccountingEngine($this->db, $this->config)`

**W2.2** Registrace v `modules/economy/bank/module.jsonc`:

```jsonc
"documentEventHandlers": [
    {
        "table": "economy_bank_transactions",
        "class": "Shipard\\Module\\Economy\\Bank\\BankTransactionEventHandler",
        "events": ["stateChanged", "beforeDelete"]
    }
]
```

### W3 — REST endpoint Přeúčtovat

**W3.1** `src/BankController.php` rozšířit (už existuje z Fáze 2 —
`importStatement`) o `reaccount(Request): Response` (vzor
`AccountingController::reaccount`): body `{transactionId}`; transakce musí být
ve stavu 40 (jinak 422); `new BankTransactionAccountingEngine(...)
->accountTransaction(id)`; vrátit `{accountingState, messages}`.

**W3.2** Route v `src/Api/Router.php`: `POST /_bank/reaccount` →
`Route('bank', 'reaccount')` (vedle `/_bank/import-statement`).

### W4 — UI tab Zaúčtování

**W4.1** `BankTransactionsViewer::renderDetail()` rozšířit o tab **Zaúčtování**
(vzor `DocsHeadsViewer::buildAccountingTab` + `accountingJournalTable`):

- stav účtování (`accounting_state` label) + hlášení (`accounting_messages`)
- tabulka řádků deníku:
  `SELECT account_number, text, money_dr, money_cr, currency, is_error,
   partner FROM economy_accounting_journal WHERE bank_transaction = %i
   ORDER BY id` — sloupce Účet / Text / MD / DAL (chybové řádky zvýraznit
   dle `is_error`)
- akce **Přeúčtovat** (vzor doklad: tlačítko → `POST /_bank/reaccount`
  `{transactionId}` → refresh detailu). Bez vazby na `accounting_state`
  (jde i u OK transakce po opravě rozvrhu).

### W5 — Alert check chyb účtování

**W5.1** `src/Checks/BankAccountingErrorsCheck.php` (vzor
`AccountingErrorsCheck`): transakce s `docState = 40` a `accounting_state = 2`,
jeden finding per transakce (`finding_key = id`, subject tableId **414**),
severity `warning`, hláška z `accounting_messages`. Reconciler auto-resolvuje,
jakmile transakce přestane vyhovovat (přeúčtováno OK / opustila 40).

**W5.2** Registrace v `module.jsonc` `alertChecks` (vedle
`StatementReconciliationCheck`; interval např. `15m`, tag `bank`).

### W6 — Testy

Integrační (vzor `tests/Integration/Accounting/`):

- **W6.1** Příjem nespárovaný (`payment.in`): transakce → stav 40 → 2 řádky
  deníku, banka 221 MD + 261200 DAL, stejné částky, `source_kind =
  bankTransaction`, `bank_transaction = id`, `accounting_state = 1`.
- **W6.2** Výdaj poplatek (`fee.out`): 568 MD + banka 221 DAL,
  `accounting_state = 1`.
- **W6.3** Idempotence: opětovné přeúčtování → stejný počet řádků (DELETE+INSERT).
- **W6.4** Lifecycle: 40 → 80 smaže řádky deníku + `accounting_state = 0`;
  smazání transakce ve stavu 40 → řádky deníku zmizí (beforeDelete).
- **W6.5** Chybový stav: účet `accounting_account` nevyplněný / maska bez účtu
  → chybový řádek + `accounting_state = 2` + alert (BankAccountingErrorsCheck).
- **W6.6** Nulová kontrola clearingu: zaúčtovat příjem i výdaj → na 261200/261300
  nenulový obrat; (po budoucím saldu by se vynuloval — teď jen ověřit, že
  clearing nese přesně nespárované).
- **W6.7** Vyrovnanost: každá transakce → `Σ MD == Σ DAL`.

## Hotovo když

> Všechny body splněny ✅

1. Transakce přechodem do stavu 40 vygeneruje 2 vyrovnané řádky deníku
   (banka 221xxx + protistrana dle `operation`); `source_kind =
   bankTransaction`, `bank_transaction` vyplněné, `doc_head` NULL.
2. Nespárované platby jdou na 261200 (příjem) / 261300 (výdaj); poplatky
   (568), úroky (662/562) na reálný účet.
3. Přechod ze 40 i smazání transakce uklidí řádky deníku; `accounting_state`
   se vynuluje.
4. Nedohledaný účet neblokuje přechod do 40 — vznikne chybový řádek,
   `accounting_state = 2`, alert (subject 414), který se po opravě
   auto-resolvuje.
5. Detail transakce má tab Zaúčtování (stav + řádky deníku) a funkční akci
   Přeúčtovat (`POST /_bank/reaccount`).
6. Idempotence: opakované účtování/přeúčtování nezdvojuje řádky.
7. Existující účtování dokladů beze změny (sdílený deník, `source_kind = doc`).
8. Integrační testy W6 zelené; existující testy neporušené.

## Doporučené pořadí

1. W1 (engine) + W6.1/W6.2/W6.7 (čistě testovatelné přímým voláním
   `accountTransaction`)
2. W2 (handler + registrace) + W6.3/W6.4 (lifecycle přes stavové přechody)
3. W5 (alert) + W6.5
4. W3 (endpoint) → W4 (UI tab + akce)
5. W6.6 + doladění; commit per balíček (engine / handler / endpoint+UI / alert)

## Rozhodnutí ✓

- Spouštěč je generický (`TableGateway` + `DocumentEventDispatcher`) — Fáze 3
  jen registruje `documentEventHandlers` na `economy_bank_transactions`,
  **žádný zásah do `core`**.
- Vlastní `BankTransactionAccountingEngine` (jiný tvar zdroje), ale sdílí
  `AccountsLookup` a čte sekci `accounts` téhož předpisu — princip „účet se
  nikde nezadává" zachován.
- Dva řádky stejné částky → vždy vyrovnané; žádná penny reconciliation.
- Banka z `bank_account.accounting_account` (221xxx); protistrana z
  `operation` → `cat` → maska. `operation` prázdné → default dle `direction`.
- Fakturové úhrady (`payment.in/out`) na clearing 261200/261300; saldo je
  později přegeneruje na 311/321 (DELETE+INSERT, clearing řádek zmizí).
- Error-tolerantní: účtování neblokuje stav 40; chyba → `accounting_state = 2`
  + chybový řádek deníku + alert.
- Fiskální období transakce se dohledá z `date_transaction`
  (`economy_codebooks_fiscal_months`), reprezentace v deníku sjednocena
  s `docs_core_heads`.

## Otevřené body

- **`fiscal_year`/`fiscal_month` v deníku** — ověřit přesnou reprezentaci na
  `docs_core_heads` (hodnota vs FK) a sjednotit; případně zvážit doplnit tyto
  computed sloupce i na `economy_bank_transactions` (konzistence s doklady +
  filtrování transakcí dle období) — to je ale schema změna, spíš samostatně.
- **Převod mezi vlastními účty** — protiúčet = náš jiný účet: zatím clearing
  jako ostatní; detekce + 261100 (a párování obou stran převodu) je rozšíření.
- **Text řádku deníku** — formát (pohyb + protistrana + VS?) doladit dle
  čitelnosti v deníku.
- **Měna ≠ domácí** (cizoměnový účet) — `amount_dom` plní import (Fáze 2);
  engine ho jen použije. FX rozdíly mimo scope (saldo/exch-diff).
