# Banka — Fáze 4 (nová strana): výměnný formát + applier pro migraci výpisů

> **Stav: ✅ Hotovo** (nová strana). Commity:
> - `b9e19dd` — W1 schéma `shpd.bank.statement.v1` (.jsonc+.json) + W5.1
> - `fd1f72a` — W2 refaktor `StatementImportService` (`applyParsedStatement`
>   přes dokumentovou vrstvu) + W5.6 regrese souborového importu
> - `7dc37f7` — W3 `BankStatementApplier` + W4 `ExchangeController`/routes/DI +
>   W5.2–W5.5
>
> Runner staré strany (`old_shipard:.../11-bank-statements.md`) je samostatný
> navazující úkol — nasadit až po této (nové) straně.
>
> **Odchylka od návrhu:** apply jádro používá core `TableGateway`, ne exchange
> `TransactionlessTableGateway` — jinak by `economy.bank` muselo záviset na
> `core.exchange` (cyklus). `applyParsedStatement` vlastní vnější transakci,
> vnořené begin/commit gatewaye i účetního enginu jsou savepointy (dibi).
> `createMissingPartner` je proplumbovaný, ale auto-create osoby zatím no-op.

## Kontext

Závěrečná fáze modulu `economy.bank`: migrace bankovních výpisů a transakcí ze
starého Shipardu. Stará a nová instance běží na **oddělených hostech** (žádný
sdílený filesystem), takže migrace jede jako u dokladů/osob/položek — přes
**výměnný formát** posílaný HTTP přes `ExchangeClient` z runneru na staré
straně na **apply endpoint** nové strany.

Tento task je **nová strana** (`nov_shipard`): definuje formát
`shpd.bank.statement.v1`, applier, který ho aplikuje, a jeho zapojení do
`ExchangeController`. **Musí být nasazen dříve** než task staré strany
(`old_shipard:modules/imports/newShipard/tasks/11-bank-statements.md`) — nová
strana musí umět formát přijmout, než ho stará začne posílat (stejný vztah jako
u docs-import revize).

**Klíčové rozhodnutí:** applier **znovupoužije apply jádro
`StatementImportService` z Fáze 2** (dohledání účtu, dedup, vznik transakcí,
reconciliace, partner). Migrace a souborový import tak konvergují do téže
logiky — liší se jen vstup (parsovaný soubor vs. kanonický payload → tatáž
`ParsedStatement`). `core.exchange` už dnes závisí na doménových modulech
(`docs.core`, `base.persons`), takže závislost na `economy.bank` je v souladu
se vzorem.

Migrované transakce se **zaúčtují novým enginem** (Fáze 3): applier je u
„hotových" výpisů vytvoří rovnou ve stavu **40** přes dokumentovou vrstvu →
`BankTransactionEventHandler` zaúčtuje na clearing (261200/261300). **Párování
(saldo) se nemigruje** — `docs/bank.md` §7.

Pět pracovních balíčků:

- **W1** — schéma `shpd.bank.statement.v1`
- **W2** — apply jádro `StatementImportService` přístupné i z exchange
- **W3** — `BankStatementApplier`
- **W4** — `ExchangeController` + routes
- **W5** — testy

## Návaznost

- `docs/bank.md` §7 (migrace) + §4 (ingestion — sdílené apply jádro).
- Staví na Fázi 2 (`StatementImportService`, `ParsedStatement`/
  `ParsedTransaction`, dedup, `PartnerResolver`, reconciliace) a Fázi 3
  (`BankTransactionEventHandler` účtuje při přechodu do 40).
- Vzor: `core.exchange` flow dokladů (`DocumentApplier` + `ExchangeController`
  + schema `shpd.docs.document.v1`).
- Stará strana (runner) je samostatný task v `old_shipard` — **deploy až po
  tomto**.

## Před implementací přečti

- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` — **vzor schématu**
- `modules/core/exchange/src/Document/DocumentApplier.php` — **vzor applieru**:
  `validate`/`preview`/`apply`, `FORMAT_ID`/`FORMAT_VERSION`, „applier nikdy
  nesahá pod dokumentovou vrstvu" (vznik přes `TransactionlessTableGateway` →
  fire event handleru), `ApplyResult`, použití resolverů
  (`PartyResolver`, `BankAccountResolver`)
- `modules/core/exchange/src/Common/ApplyResult.php`,
  `Common/TransactionlessTableGateway.php`, `Schema/SchemaLoader.php`,
  `Schema/SchemaValidator.php`
- `src/Api/Controller/ExchangeController.php` — dispatch flow (Document
  povinný, Person/Item volitelně injektované) + `respond()` + `extractPayload()`
- `src/Api/Router.php` (~ř. 160–168, 395–412) — routes
  `/_exchange/{flow}/{type}/{validate|preview|apply}`
- `modules/economy/bank/src/Import/StatementImportService.php` — **apply jádro
  k znovupoužití** (W2); `ParsedStatement.php`, `ParsedTransaction.php`,
  `PartnerResolver.php`
- `modules/economy/bank/src/BankTransactionAccountingEngine.php`,
  `BankTransactionEventHandler.php` — vznik transakce ve stavu 40 účtuje
- `modules/economy/bank/tables/economy_bank_transactions.jsonc`,
  `economy_bank_statements.jsonc` — cílové tabulky
- `docs/exchange-format.md` (pipeline validate/preview/apply)

## Scope

### V scope

- JSON schéma `shpd.bank.statement.v1` (hlavička výpisu + pole transakcí)
- `BankStatementApplier` (validate/preview/apply) znovupoužívající apply jádro
  `StatementImportService`
- zapojení do `ExchangeController` + routes `/_exchange/bank/statement/*`
- migrované transakce „hotových" výpisů vznikají ve stavu 40 (zaúčtují se);
  konceptové ve stavu 10
- testy (schema, apply, dedup idempotence, účtování přes vznik ve 40)

### Mimo scope

- **runner na staré straně** — samostatný task `old_shipard`
- **párování / saldo** — transakce jdou na clearing (Fáze 3); přegenerace na
  311/321 je pozdější fáze
- **migrace příloh PDF** — řeší runner staré strany (AttachmentImporter);
  applier jen přijme případnou referenci, samotný binární upload jde mimo
  exchange JSON (vzor `07a-attachments-client`)

---

## Co implementovat

### W1 — Schéma `shpd.bank.statement.v1`

`modules/core/exchange/schemas/shpd.bank.statement.v1.jsonc` (vzor docs
schématu). Struktura kanonického payloadu:

```jsonc
{
    "format": "shpd.bank.statement",
    "formatVersion": "1.0",
    "source": { "kind": "import.oldShipard", "raw": { "oldNdx": 0 } },

    // náš účet — id v cílovém systému (runner ho zná z LocalIdMap
    // ENTITY_BANK_ACCOUNT); applier ho použije přímo (vzor importOwnBankAccount)
    "bankAccountId": 0,

    "statement": {
        "statementNumber": "…",        // nebo null
        "periodStart": "YYYY-MM-DD",
        "periodEnd": "YYYY-MM-DD",
        "openingBalance": 0.0,
        "closingBalance": 0.0,
        "currency": "CZK"
    },

    "transactions": [
        {
            "externalId": "…",          // stabilní ID; migrace ho odvodí z old ndx
            "amount": 0.0,              // ZNAMÉNKOVÁ (− = výdaj)
            "dateTransaction": "YYYY-MM-DD",
            "dateValue": "YYYY-MM-DD",  // nebo null
            "counterpartyAccount": "…", // nebo null
            "counterpartyName": "…",    // nebo null
            "symbol1": "…", "symbol2": "…", "symbol3": "…",
            "message": "…",
            "operation": null           // null → default dle směru (payment.in/out)
        }
    ],

    "applyOptions": {
        "targetState": 40,              // 40 = zaúčtovat, 10 = koncept
        "createMissingPartner": false
    }
}
```

Schéma registrovat ve `SchemaLoader` (vzor docs/persons/items).

### W2 — Apply jádro `StatementImportService` přístupné z exchange

**W2.1** Ověřit/refaktorovat `StatementImportService` (Fáze 2) tak, aby apply
jádro bylo volatelné nezávisle na parsování souboru:

```php
public function applyParsedStatement(
    ParsedStatement $stmt,
    int $bankAccountId,
    int $targetState = 10,
    bool $createMissingPartner = false
): StatementImportResult
```

Tj. vyčlenit z `import()` část **po** parsování (dohledání/ověření účtu →
find/create výpisu → per transakce: směr+částka, `amount_dom`, dedup
`external_id`/`fingerprint`, vznik **přes dokumentovou vrstvu** ve stavu
`targetState`, partner → reconciliace) do `applyParsedStatement`. `import()`
(souborová cesta) ji pak jen zavolá s `targetState = 10`.

**W2.2 (kritické pro účtování):** vznik transakce musí jít **přes
dokumentovou vrstvu** (`TableGateway`/document save), ne raw insert — jen tak
přechod do stavu 40 spustí `BankTransactionEventHandler` → účtování. Ověřit,
jak Fáze 2 transakce vkládá; pokud raw insertem, převést na dokumentovou
vrstvu (viz Otevřené body).

### W3 — `BankStatementApplier`

`modules/core/exchange/src/Bank/BankStatementApplier.php` (vzor
`DocumentApplier`; `FORMAT_ID = 'shpd.bank.statement'`, `FORMAT_VERSION = '1'`):

- `validate(payload)` — schema + `SchemaValidator`, bez zápisu
- `preview(payload)` — validate + dohledání (existuje účet `bankAccountId`?
  kolik transakcí by se vytvořilo / přeskočilo dle dedup) bez zápisu
- `apply(payload)` — validate → převést kanonický payload na `ParsedStatement`
  + `ParsedTransaction[]` (přímé mapování polí W1) → zavolat
  `StatementImportService::applyParsedStatement($stmt, $payload.bankAccountId,
  $payload.applyOptions.targetState, …)` → vrátit `ApplyResult`
  (`savedStatementId` + souhrn created/skipped/unmatched/reconciliation)
- žádná vlastní create logika — vše delegovat na apply jádro (W2)

### W4 — `ExchangeController` + routes

**W4.1** `ExchangeController`: přidat volitelně injektovaný
`?BankStatementApplier $bankApplier` (vzor person/item) + metody
`validateBankStatement`/`previewBankStatement`/`applyBankStatement`
(`respond(..., 'savedStatementId')`); chybí-li applier → `bankFlowUnavailable()`.

**W4.2** `src/Api/Router.php`: routes
`/_exchange/bank/statement/{validate|preview|apply}` →
`Route('exchange', 'validateBankStatement'|…)` (vzor docs/persons/items
~ř. 160–168, 395–412).

**W4.3** Zaregistrovat applier v DI (kde se skládá `ExchangeController`).

### W5 — Testy

- **W5.1** Schema: validní payload projde, nevalidní (chybí `bankAccountId`,
  záporná perioda) selže.
- **W5.2** Apply: payload s 1 výpisem + N transakcí, `targetState = 40` →
  vznikne výpis + transakce ve stavu 40, **zaúčtované** (řádky deníku,
  `source_kind = bankTransaction`, banka + clearing).
- **W5.3** Dedup idempotence: druhý apply téhož payloadu → 0 nových transakcí.
- **W5.4** `targetState = 10` → transakce ve stavu Nová, bez deníku.
- **W5.5** Reconciliace: payload se sedícími zůstatky → `reconciliation_state = 1`.
- **W5.6** Souborový import (Fáze 2) přes refaktorované jádro stále funguje
  (regrese — `applyParsedStatement` s `targetState = 10`).

## Hotovo když

1. ✅ Endpoint `POST /_exchange/bank/statement/apply` přijme `shpd.bank.statement.v1`,
   vytvoří výpis + transakce; `targetState = 40` je rovnou zaúčtuje (clearing).
2. ✅ Dedup je idempotentní (opakovaný apply nezdvojuje); `external_id`/`fingerprint`
   funguje napříč migrací i souborovým importem.
3. ✅ Souborový import z Fáze 2 funguje beze změny chování (sdílené apply jádro).
4. ✅ `validate`/`preview` neprovádí zápis; `preview` hlásí počty.
5. ✅ Testy W5 zelené (W5.1 schema 35/35, W5.2–5.5 applier 6/6, W5.6 import 9/9);
   existující exchange (411 unit) + bank (19 integ.) testy neporušené.

## Doporučené pořadí

1. W1 (schéma) + W5.1
2. W2 (refaktor apply jádra) + W5.6 (regrese souborového importu)
3. W3 (applier) + W4 (controller/routes) + W5.2/W5.3/W5.4/W5.5
4. Commit per balíček (schema / service-refactor / applier+controller)

## Rozhodnutí ✓

- Migrace přes výměnný formát + HTTP apply (oddělené hosty), vzor docs.
- Applier znovupoužívá apply jádro `StatementImportService` — migrace a
  souborový import konvergují do téže logiky (`applyParsedStatement`).
- Náš účet jde jako `bankAccountId` (runner ho zná z `LocalIdMap`), applier
  ho použije přímo (vzor `importOwnBankAccount`).
- „Hotové" výpisy → transakce ve stavu 40 přes dokumentovou vrstvu → zaúčtují
  se novým enginem na clearing; konceptové → stav 10. Párování (saldo) se
  nemigruje.
- `BankStatementApplier` žije v `core.exchange` (smí záviset na `economy.bank`,
  jako už závisí na `docs.core`).

## Otevřené body

- **Vznik transakce přes dokumentovou vrstvu** — ověřit, jak Fáze 2 vkládá
  transakce (raw insert vs. document save). Pro spuštění účtování při
  `targetState = 40` musí jít přes dokumentovou vrstvu; pokud Fáze 2 dělá raw
  insert, W2.2 to převede (a souborový import tím získá konzistentní chování).
- **Výkon hromadné migrace** — každá transakce ve stavu 40 spustí engine
  (synchronně). Pro tisíce transakcí zvážit dávkové účtování (import ve stavu
  10 + jeden „account all" krok). Zatím per-transakce; optimalizace později.
- **Partner** — apply jádro řeší partnera přes `PartnerResolver` (protiúčet);
  staré napárování `person` se nepřenáší (saldo-nezávislé, viz `docs/bank.md`).
  Případné přenesení old person jako hint je rozšíření.
