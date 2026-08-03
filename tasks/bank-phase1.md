# Banka — Fáze 1: datový model + generalizace deníku

**Stav:** hotovo

> **Stav: ✅ Hotovo.** Commity: `1691c4a` (modul + tabulky + cfgItemy + formy),
> `3cf1592` (W4 účet + ID v ebankingu), `61f1273` (W5 generalizace deníku —
> polymorfní zdroj), `fb4a8d5` (W7 clearing 261200/261300 + `bank.*`),
> `d4821a7` (W8 viewery), `7d93971` (testy).

## Kontext

Začínáme implementovat modul `economy.bank` podle návrhu v `docs/bank.md`.
Bankovní transakce je prvotřídní záznam (ne řádek dokladu), výpis je nepovinná
evidenční/kontrolní vrstva. Fáze 1 klade **datový základ** — žádný import,
žádný účetní mikroengine, žádné stahování přes API. Jen: tabulky, rozšíření
číselníku účtů, příprava účetního deníku na druhý zdroj zápisů, číselníky
stavů/pohybů, seed clearing účtů a základní viewery.

- **Fáze 2** = import výpisu ze souboru + deduplikace (port parserů).
- **Fáze 3** = `BankTransactionAccountingEngine` + UI účtování.
- **Fáze 4** = migrace ze starého Shipardu.
- API konektory a saldokonto jsou samostatné pozdější fáze (mimo tento návrh).

Fáze 1 má osm pracovních balíčků:

- **W1** — modul `economy.bank` (skeleton + registrace)
- **W2** — tabulka `economy_bank_transactions` (+ document class, form, .md)
- **W3** — tabulka `economy_bank_statements` (+ document class, form, .md)
- **W4** — extension číselníku účtů (`accounting_account`, `ebanking_id`)
- **W5** — generalizace účetního deníku (polymorfní zdroj)
- **W6** — cfgItems `txStates` + `txOperations`
- **W7** — seed clearing účtů + mapování kategorií v předpisu
- **W8** — viewery transakcí a výpisů

## Návaznost

- Návrhový dokument: `docs/bank.md` — **závazný**, tento task ho rozpracovává
  do kroků (sekce 3 datový model, 5 stavy, 6.2/6.3 pohyby a clearing účty,
  10 log rozhodnutí).
- `docs/accounting.md` (sekce 6 deník, 7 engine) — deník generalizujeme, ne
  přepisujeme; engine se ve Fázi 1 jen drobně upraví (zápis `source_kind`).
- Účtový rozvrh (`economy_accounting_accounts`) i číselník bankovních spojení
  (`economy_codebooks_bank_accounts`) už existují — W4/W7 na ně navazují.
- Fáze 2/3 budou číst: `bank_account.accounting_account` (mikroengine),
  `external_id`/`fingerprint` (dedup), `operation` (účtování), `txStates`
  (lifecycle). Proto musí Fáze 1 tyto sloupce/configy zavést přesně.

## Před implementací přečti

- `docs/bank.md` — celý
- `docs/accounting.md` — sekce 6 (deník), 7 (engine/lifecycle)
- `modules/economy/accounting/tables/economy_accounting_journal.jsonc` —
  tabulka, kterou generalizujeme
- `modules/economy/accounting/src/AccountingEngine.php` — metoda
  `writeResult()` (INSERT do deníku — sem přidat `source_kind`), `clearDocument()`
- `modules/economy/accounting/src/DocsHeadsEventHandler.php` — lifecycle vzor
  (Fáze 3 si podle něj udělá vlastní handler — teď jen kontext)
- `modules/economy/accounting/module.jsonc` — vzor registrace modulu
  (tables, extensions, viewers, forms, documentClasses, config, alertChecks)
- `modules/economy/codebooks/tables/economy_codebooks_bank_accounts.jsonc`
  + `.md` — tabulka, kterou rozšiřujeme (W4); `idx_iban` je tam připravený
  „pro budoucí SEPA modul" — to jsme my
- `modules/economy/accounting/extensions/economy_items.jsonc` — vzor extension
- `modules/economy/accounting/src/AccountDocument.php` — vzor document class
  (`validate()` + `beforeSave()`)
- `modules/economy/codebooks/src/BankAccountDocument.php` — vzor
  (default-per-currency uniqueness v `afterPersist`)
- `modules/economy/accounting/src/JournalViewer.php` — vzor read-only viewer
  (selectRows/renderRow/renderDetail/getFilters); pozn.: akce „Otevřít doklad"
  už je null-safe (`if ($docHead > 0)`), takže řádky bez `doc_head` ji prostě
  nenabídnou — generalizace deníku viewer nerozbije
- `modules/economy/accounting/forms/economy_accounting_accounts.jsonc` — vzor form
- `modules/core/mail/config/docStatesIncoming.jsonc` — vzor vlastní sady
  docStates (W6 `txStates`)
- `modules/docs/core/config/rowOperations.jsonc` — vzor cfgItem pohybů
  (W6 `txOperations`)
- `modules/economy/accounting/config/accountChartDefault.jsonc` (řada 261)
  + `accountChartNpo.jsonc` + `modules/economy/accounting/src/AccountChartProvisioner.php`
  — seed osnovy (W7); provisioner je idempotentní, jen se doplní záznamy
- `modules/economy/accounting/config/accountingRules.cz.jsonc` — sekce
  `accounts` (W7 mapování kategorií `bank.*`)
- `docs/table-definitions.md` sekce 10 (bezpečné změny `ds-upgrade`) +
  sekce o tableId (`bin/shpd-server next-table-id`)
- `modules/install/base/module.jsonc` — sem se registruje nový modul (W1)
- `docs/doc-states.md`, `docs/modules.md` (extensions), `docs/edit-forms.md`

## Scope

### V scope

- nový modul `economy.bank` + registrace v `install.base`
- tabulky `economy_bank_transactions`, `economy_bank_statements` (+ document
  classes, forms, `.md` docs)
- extension `accounting_account` + `ebanking_id` na číselník bankovních spojení
- generalizace deníku: `source_kind` + `bank_transaction` + `doc_head` nullable
  + cfgItem `economy.accounting.journalSources`
- cfgItems `economy.bank.txStates`, `economy.bank.txOperations`
- seed clearing účtů (261200/261300) + mapování `bank.*` kategorií v předpisu
- read-only-ish viewery transakcí a výpisů

### Mimo scope

- import výpisu, parsery, deduplikace, fingerprint logika — **Fáze 2**
  (sloupce `external_id`/`fingerprint` se v F1 jen zavedou)
- `BankTransactionAccountingEngine`, zobecnění `documentEventHandlers`,
  tab Zaúčtování, akce Přeúčtovat, alert na `accounting_state = 2` — **Fáze 3**
- migrace, výměnný formát `shpd.bank.statement.v1`, runner — **Fáze 4**
- API konektory: sloupce `connector_kind` / `connector_config` / `sync_cursor`
  se v F1 **NEZAVÁDÍ** (přidají se s API fází — `ADD COLUMN` je bezpečná
  operace); kódovat šifrované secrety teď nemá smysl
- auto-klasifikace `operation`, převody mezi vlastními účty, kurzové rozdíly
- saldokonto a přegenerace clearing → 311/321

---

## Co implementovat

### W1 — Modul `economy.bank`

**W1.1** Adresář `modules/economy/bank/` se strukturou jako `economy.accounting`:
`module.jsonc`, `tables/`, `extensions/`, `forms/`, `src/`, `config/`.
Namespace tříd `Shipard\Module\Economy\Bank\`.

**W1.2** `module.jsonc`:

```jsonc
{
    "id": "economy.bank",
    "name": "Bank",
    "name:cs": "Banka",
    "name:en": "Bank",
    "description:cs": "Bankovní transakce a výpisy",
    "dependencies": ["core.system", "economy.accounting", "economy.codebooks", "docs.core", "core.attachments"],
    "tables": ["economy_bank_transactions", "economy_bank_statements"],
    "extensions": ["economy_codebooks_bank_accounts", "economy_accounting_journal"],
    "documentClasses": [
        {"table": "economy_bank_transactions", "class": "Shipard\\Module\\Economy\\Bank\\BankTransactionDocument"},
        {"table": "economy_bank_statements",   "class": "Shipard\\Module\\Economy\\Bank\\BankStatementDocument"}
    ],
    "viewers": [ /* W8 */ ],
    "forms": [
        {"table": "economy_bank_transactions", "id": "economy.bank.transactions"},
        {"table": "economy_bank_statements",   "id": "economy.bank.statements"}
    ],
    "config": [
        {"id": "economy.bank.txStates",     "file": "config/txStates.jsonc"},
        {"id": "economy.bank.txOperations", "file": "config/txOperations.jsonc"}
    ]
}
```

**W1.3** Registrovat `"economy.bank"` v `modules/install/base/module.jsonc`
do `dependencies` (za `economy.accounting`).

### W2 — Tabulka `economy_bank_transactions`

**W2.1** `modules/economy/bank/tables/economy_bank_transactions.jsonc`.
tableId alokovat přes `bin/shpd-server next-table-id` (core rozsah; očekávané
okolí 41x/42x — **nehardcodovat naslepo**). docStates: `economy.bank.txStates`
(`stateColumn` `docState`, `mainColumn` `docStateMain`, cfgItem
`economy.bank.txStates`). Sloupce přesně dle `docs/bank.md` §3.1, s těmito
upřesněními:

- `direction` — `enumInt`, cfgItem `economy.bank.txDirections` (malý config:
  `{"1": {"name:cs": "Příjem"}, "2": {"name:cs": "Výdaj"}}`) nebo inline; not null.
- `fingerprint` — **nullable** ve Fázi 1 (plní až ingestion ve Fázi 2;
  MariaDB povoluje více NULL v unique indexu, takže test inserty bez
  fingerprintu nekolidují). `external_id` rovněž nullable.
- `accounting_state` / `accounting_messages` — system, sdílí cfgItem
  `economy.accounting.accountingStates` (jako hlavička dokladu).
- `operation` — enumString 40, cfgItem `economy.bank.txOperations`, nullable.

Indexy: unique `(bank_account, external_id)`, unique `(bank_account, fingerprint)`,
index `(bank_account, date_transaction)`, `(partner)`, `(statement)`,
`(docStateMain, date_transaction)`.

**W2.2** `src/BankTransactionDocument.php` (extends `Document`) — `validate()`:

- `direction` ∈ {1, 2} (povinné)
- `amount` > 0 (vždy kladná; směr drží `direction`)
- `currency` neprázdné, `bank_account` vyplněné, `date_transaction` vyplněné
- `amount_dom` vyplněné (>= 0)

`beforeSave()`: doplnit `amount_dom = round(amount × exchange_rate, 2)`, pokud
chybí a `exchange_rate` je vyplněný (u domácí měny `exchange_rate = 1`).
Fingerprint **nepočítat** tady — to je ingestion concern (Fáze 2).

**W2.3** `forms/economy_bank_transactions.jsonc` — minimální edit form
(vzor `economy_accounting_accounts.jsonc`): editovatelné `operation` + partner
+ poznámka/`message`; ostatní (částka, symboly, protiúčet, data) read-only
prezentačně (transakce vzniká importem, ne ručně). Žádný „nový" záznam z UI
(viz W8 — toolbar bez Add).

**W2.4** `tables/economy_bank_transactions.md` — popis tabulky dle konvence
(`economy_codebooks_bank_accounts.md` jako vzor): účel, skupiny sloupců,
pravidla (dedup klíče, vztah `external_id` vs `fingerprint`), související soubory.

### W3 — Tabulka `economy_bank_statements`

**W3.1** `tables/economy_bank_statements.jsonc`, tableId přes `next-table-id`.
docStates: `core.system.docStatesArchive`. Sloupce dle `docs/bank.md` §3.2
(`bank_account`, `statement_number`, `period_start/end`, `opening_balance`,
`closing_balance`, `currency`, `reconciliation_state`). PDF výpisu se připojí
přes `core.attachments` (tab `attachments` ve formu — vzor existující integrace
příloh; samotná rekonciliace je Fáze 2). Indexy: `(bank_account, period_end)`,
`(docStateMain, period_end)`.

**W3.2** `src/BankStatementDocument.php` — `validate()`: `bank_account`
povinné, `period_start <= period_end`, `currency` neprázdné.

**W3.3** `forms/economy_bank_statements.jsonc` — minimální form + tab příloh.

**W3.4** `tables/economy_bank_statements.md`.

### W4 — Extension číselníku bankovních spojení

**W4.1** `modules/economy/bank/extensions/economy_codebooks_bank_accounts.jsonc`
(soubor se jmenuje dle cílové tabulky — vzor `economy_items.jsonc`). Přidat:

```jsonc
{
    "table": "economy_codebooks_bank_accounts",
    "columns": [
        {"id": "accounting_account", "name:cs": "Účet", "type": "int",
            "nullable": true, "reference": "economy_accounting_accounts", "group": "settings"},
        {"id": "ebanking_id", "name:cs": "ID v ebankingu", "type": "varchar",
            "length": 80, "nullable": true, "group": "account"}
    ]
}
```

`connector_*` / `sync_cursor` zde **nejsou** (mimo scope F1, viz výše).

**W4.2** Pole `accounting_account` doplnit do formu číselníku
(`modules/economy/codebooks/forms/economy_codebooks_bank_accounts.jsonc`) —
picker omezený na analytiky `221xxx` (`account_level = 4`, prefix `221`),
aktivní záznamy. Pokud LookupInput deklarativní filtr prefixu neumí, vynutit
validací v `BankAccountDocument` (odkazovaný účet musí mít `account_level = 4`
a `number LIKE '221%'`) — stejný kompromis jako u `accounting_account` na items
ve Fázi 1 účetnictví. `ebanking_id` jako prostý input.

### W5 — Generalizace účetního deníku (polymorfní zdroj)

Cíl: deník unese zápisy z dokladu i z bankovní transakce. Rozdělení podle
směru závislostí — generická infrastruktura do `economy.accounting`, bankovně
specifický FK jako extension z `economy.bank`.

**W5.1 (economy.accounting)** — `economy_accounting_journal.jsonc`:

- přidat sloupec `source_kind` (enumString 20, not null, default `"doc"`,
  cfgItem `economy.accounting.journalSources`) hned za `id`
- změnit `doc_head` na `"nullable": true`
- přidat index `idx_source` na `(source_kind)`

Nový config `modules/economy/accounting/config/journalSources.jsonc`
→ cfgItem `economy.accounting.journalSources`:

```jsonc
{
    "doc":             {"name:cs": "Doklad",            "name:en": "Document"},
    "bankTransaction": {"name:cs": "Bankovní transakce", "name:en": "Bank transaction"}
}
```

Registrovat config v `economy.accounting/module.jsonc`. (Label
`bankTransaction` je jen i18n string — nezakládá závislost accounting → bank.)

**W5.2 (economy.accounting)** — `AccountingEngine::writeResult()`: do INSERT
do `economy_accounting_journal` přidat `'source_kind' => 'doc'` (explicitně,
i když default pokrývá). Žádná jiná změna enginu ve Fázi 1.

**W5.3 (economy.accounting)** — **nullability `doc_head`.** `ds-upgrade`
provádí jen rozšíření typu, **NE** `NOT NULL → nullable` (viz
`table-definitions.md` §10). Deník je ale čistý derivát (vždy
přegenerovatelný), takže:

- na fresh `ds-reset` se tabulka vytvoří rovnou správně (`doc_head` nullable) — OK
- pro existující datasource přidat do `DsUpgradeCommand` (nebo upgrade
  poznámky) **jednorázový krok**: `DROP TABLE economy_accounting_journal`
  před re-upgradem (znovu vznikne z definice nullable), pak přeúčtovat doklady
  ve stavu 40. Detekce: pokud `information_schema` hlásí `doc_head` jako
  `NOT NULL`, dropni a nech `ds-upgrade` znovu vytvořit. Reálně to „kousne"
  až ve Fázi 3 (kdy se vkládají řádky s `doc_head = NULL`), ale vyřeš to
  už teď, ať Fáze 3 nemusí.

**W5.4 (economy.bank)** — `modules/economy/bank/extensions/economy_accounting_journal.jsonc`:

```jsonc
{
    "table": "economy_accounting_journal",
    "columns": [
        {"id": "bank_transaction", "name:cs": "Bankovní transakce", "type": "int",
            "nullable": true, "reference": "economy_bank_transactions"}
    ],
    "indexes": [
        {"id": "idx_bank_transaction", "type": "index", "columns": [{"column": "bank_transaction"}]}
    ]
}
```

Tím směřuje závislost správně: `economy.bank` → `economy.accounting`.
Invariant (zatím dokumentační, vynutí ho mikroengine ve Fázi 3): vyplněno
právě jedno z (`doc_head`, `bank_transaction`) dle `source_kind`.

### W6 — cfgItems stavů a pohybů transakce

**W6.1** `config/txStates.jsonc` → `economy.bank.txStates` (vzor
`docStatesIncoming.jsonc`), dle `docs/bank.md` §5:

| docState | stateName:cs | stateStyle | mainState | viewGroup | readOnly | goto |
|---|---|---|---|---|---|---|
| 10 | Nová | concept | 1 | active | — | 40, 80, 90 |
| 80 | V opravě | edit | 2 | active | — | 40, 90 |
| 40 | Zaúčtováno | done | 3 | active | 1 | 80, 90 |
| 90 | Smazáno | trash | 5 | trash | 1 | 80 |

(actionName / :en doplnit dle vzoru. `closeForm` dle potřeby.)

**W6.2** `config/txOperations.jsonc` → `economy.bank.txOperations` (vzor
`rowOperations.jsonc`), dle `docs/bank.md` §6.2: `payment.in`, `payment.out`,
`fee.out`, `interest.in`, `interest.out` — každý s `name:cs/:en`, `direction`
a `cat` (kategorie pro mikroengine). Default operace dle `direction` doplní
ingestion/UI ve Fázi 2/3 — config jen definuje sadu.

### W7 — Seed clearing účtů + mapování v předpisu

**W7.1** Do `accountChartDefault.jsonc` přidat za řadu 261 dva analytické účty:

```jsonc
{"number":"261200","name":"Nespárované platby — příjmy","short_name":"Nespárované příjmy","account_kind":0},
{"number":"261300","name":"Nespárované platby — výdaje","short_name":"Nespárované výdaje","account_kind":0}
```

`account_level`/`g1/g2/g3` dopočítá `AccountDocument::deriveStructure()`.
Ověřit `accountChartNpo.jsonc` — pokud obsahuje řadu 261, přidat tytéž účty
i tam. (`account_kind` 0 = Aktiva pro konzistenci s 261100; alternativa 5
„Aktivně pasivní" — viz Otevřené body.)

**W7.2** Do `accountingRules.cz.jsonc`, sekce `accounts`, přidat mapování
kategorií (konzumuje mikroengine ve Fázi 3; teď neškodná konfigurace):

```jsonc
{"cat": "bank.unmatched.in",  "accountMask": "261200"},
{"cat": "bank.unmatched.out", "accountMask": "261300"},
{"cat": "bank.fee",           "accountMask": "568"},
{"cat": "bank.interest.in",   "accountMask": "662"},
{"cat": "bank.interest.out",  "accountMask": "562"}
```

Ověřit, že případný test „každá `accountMask` v předpisu má účet v seed osnově"
projde (568/662/562 v default osnově existují; 261200/261300 doplňuje W7.1).
Pozn.: `VatAnalyticsCompletenessTest` se týká jen 343 analytik — bank kategorie
ho neovlivní.

### W8 — Viewery

**W8.1** `src/BankTransactionsViewer.php` (extends `TableViewer`, vzor
`JournalViewer` + `AccountsViewer`): seznam (datum, částka se směrem,
protistrana/partner, symboly, stav účtování — `is_error` styl), detail
(properties: částka v obou měnách, symboly, protiúčet, partner, operation,
stav). docStates taby přes `$docStatesCfgItem = 'economy.bank.txStates'`.
**Bez Add akce** (`getToolbarActions` bez „nový" — záznam vzniká importem);
edit `operation`/stavů povolen. Tab/akce Zaúčtování = Fáze 3 (zatím nepřidávat).

**W8.2** `src/BankStatementsViewer.php`: seznam (perioda, číslo, zůstatky,
`reconciliation_state`), detail (zůstatky, perioda, přílohy). docStates
`core.system.docStatesArchive`.

**W8.3** Registrovat oba viewery v `economy.bank/module.jsonc` (`viewers`).

---

## Hotovo když

> Všechny body splněny ✅

1. `ds-upgrade` na **fresh** DS projde; vzniknou tabulky
   `economy_bank_transactions`, `economy_bank_statements`, extension sloupce
   na `economy_codebooks_bank_accounts` (`accounting_account`, `ebanking_id`)
   a na `economy_accounting_journal` (`source_kind`, `bank_transaction`),
   `doc_head` je nullable.
2. Na existujícím DS s `doc_head NOT NULL` proběhne W5.3 (drop+recreate
   deníku) a `doc_head` je po upgradu nullable; přeúčtování existujících
   dokladů znovu naplní deník se `source_kind = 'doc'`.
3. Existující účtování dokladů funguje beze změny chování — řádky deníku mají
   `source_kind = 'doc'`, `doc_head` vyplněné. Integrační test účtování
   (`tests/Integration/Accounting/`) zelený.
4. Lze vložit (integračním testem / přímým insertem) bankovní transakci ve
   stavu Nová; validace odmítne `direction` mimo {1,2}, `amount <= 0`,
   chybějící `bank_account`/`currency`/`date_transaction`.
5. Bankovní spojení má ve formu pole Účet (picker omezený na 221xxx) a
   ID v ebankingu.
6. Viewer transakcí i výpisů je v navigaci, zobrazí seznam a detail, nabízí
   stavové přechody, **nenabízí** vytvoření nového záznamu.
7. Seed osnovy obsahuje 261200/261300; provisioner je idempotentní (druhý
   běh `existing++`, ne duplicita). Předpis `accounts` má `bank.*` kategorie.
8. PHPUnit s úzkým filtrem (`--filter 'Bank|Journal|Account'`) zelený;
   existující testy neporušené.

## Doporučené pořadí

1. W1 (modul + registrace) → `ds-upgrade` (prázdný, ověří načtení modulu)
2. W6 (cfgItems — potřebné pro docStates/enumString sloupce tabulek)
3. W2 + W3 (tabulky + document classes + .md) → `ds-upgrade`
4. W4 (extension číselníku + form)
5. W5 (generalizace deníku: W5.1/5.2 accounting, W5.4 bank extension, W5.3
   nullability) → `ds-upgrade` (+ ověřit re-accounting dokladu)
6. W7 (seed + předpis) → `ds-upgrade` (provisioner)
7. W8 (viewery)
8. Testy, commit per balíček (modul / tabulky / extension / deník / seed / viewery)

## Rozhodnutí ✓

- Dedikované tabulky `economy_bank_transactions` + `economy_bank_statements`;
  transakce je prvotřídní záznam, výpis nepovinná evidence.
- `direction` (1/2) + vždy kladná `amount`; částka v měně transakce i domácí.
- Generalizace deníku: generický `source_kind` (default `doc`) + nullable
  `doc_head` patří do `economy.accounting`; FK `bank_transaction` přidá
  `economy.bank` jako **extension** (správný směr závislosti).
- `doc_head` nullability řešena drop+recreate deníku (derivát) — `ds-upgrade`
  to sám neumí.
- `fingerprint`/`external_id` nullable ve Fázi 1; dedup a garantovaný
  fingerprint = Fáze 2.
- `connector_*` / `sync_cursor` se ve Fázi 1 nezavádí (API fáze; `ADD COLUMN`
  je bezpečné dodat později).
- Dva clearing účty 261200/261300 (`account_kind` 0), mapování kategorií
  `bank.*` v předpisu už ve Fázi 1 (neškodná konfigurace pro budoucí engine).
- Viewery bez „nový" — transakce/výpisy vznikají importem/migrací.

## Otevřené body

- **`account_kind` clearing účtů** — 0 (Aktiva, konzistence s 261) vs. 5
  (Aktivně pasivní, sémanticky přesnější pro účet, co flipuje stranu). Pro
  nulovou kontrolu nerozhoduje; potvrdit s Davidem.
- **Picker prefixu 221xxx** — pokud LookupInput deklarativní filtr neumí,
  zůstane jen validace (stejně jako u `accounting_account` na items).
- **NPO osnova** — ověřit, zda `accountChartNpo.jsonc` má řadu 261 a zda tam
  clearing účty patří (neziskovka má taky banku → spíš ano).
- **Detekce existujícího `NOT NULL doc_head`** v upgrade kroku — implementovat
  čistě (information_schema), nebo se spolehnout na to, že alfa datasources
  se stejně resetují přes `ds-reset`? (Doporučení: implementovat detekci,
  ať to není křehké.)
