# Účtování dokladů — Fáze 2: deník a engine

## Kontext

Fáze 1 (pohyby, `_dom` sloupce, `accounting_account` na položkách) je hotová
a v DB jsou po každém přepočtu aktuální computed hodnoty řádků. Fáze 2 staví
vlastní účtování: obecný hook mechanismus na události dokumentů, tabulku
účetního deníku, deklarativní účtovací předpis CZ a `AccountingEngine`,
který při přechodu dokladu do stavu 40 (V pořádku) vygeneruje řádky deníku.

Čtyři pracovní balíčky:

- **W1** — mechanismus `documentEventHandlers` (core)
- **W2** — tabulka deníku + extension účetního stavu na hlavičce
- **W3** — účtovací předpis CZ + `AccountingEngine`
- **W4** — event handler, endpoint Přeúčtovat, alert check

## Návaznost

- Návrhový dokument: `docs/accounting.md` — **závazný**. Sekce 4 (předpis),
  5 (dohledávání účtů), 6 (deník), 7 (engine a lifecycle). Tento task
  doplňuje jen implementační detaily zjištěné z kódu.
- Vyžaduje hotovou Fázi 1 (`tasks/accounting-phase1.md`) — splněno.
- Fáze 3 (UI) naváže na: řádky deníku per doklad, `accounting_messages`,
  endpoint Přeúčtovat (tlačítko přijde ve Fázi 3, endpoint vzniká teď).

## Před implementací přečti

- `docs/accounting.md` — sekce 4–8 + log rozhodnutí
- `src/Core/Document/TableGateway.php` — `saveDocument` (afterPersist /
  commit / afterSave pořadí), `deleteDocument` — sem přijde dispatch
- `modules/docs/core/src/DocDocument.php` — `trackStateChange`,
  `processStateTransition` (kde a jak je detekovaná změna stavu)
- `modules/docs/core/src/DocRowsDocument.php` — `recomputeHeader` (tato
  cesta gateway obchází → eventy se z ní nevyvolávají, stav se tam nemění)
- registry dokumentů (`$this->registry->getDocument(...)` v gateway) — vzor
  pro registry handlerů; kompilace `module.jsonc` polí do cfg
- `modules/base/persons/module.jsonc` pole `alertChecks` +
  `modules/base/persons/src/Checks/MissingOwnPersonCheck.php` a
  `modules/docs/core/src/Checks/StaleInRepairCheck.php` + `docs/alerts.md`
  — vzor alert checku (per-record varianta)
- `modules/economy/accounting/src/AccountsLookup.php` — existující lookup
  nad rozvrhem; pokud sedí, použít/rozšířit pro dohledávání masek
- `modules/economy/accounting/extensions/economy_items.jsonc` — vzor
  extension (pozn.: soubory extensions jsou pojmenované podle cílové
  tabulky bez prefixu — drž tuto konvenci, ne `ext-*` z accounting.md)
- `docs/rest-api.md` + `modules/core/attachments/src/AttachmentController.php`
  — konvence endpointů (`/_accounting/*`)
- `modules/docs/core/src/OwnCompanyResolver.php` — zjištění země vlastní
  firmy pro výběr předpisu
- `docs/table-definitions.md` — definice tabulky bez docStates

## Scope

### V scope

- interface + registrace + dispatch `documentEventHandlers`
- `economy_accounting_journal` (tableId **413**, bez docStates)
- extension `docs_core_heads`: `accounting_state`, `accounting_messages`
  + config `accountingStates.jsonc`
- `accountingRules.cz.jsonc` + `AccountingEngine` + dohledávání účtů
- `DocsHeadsEventHandler` (vstup/výstup ze stavu 40, beforeDelete)
- endpoint `POST /_accounting/reaccount`
- alert check na doklady s chybou účtování
- testy (jednotkové na engine, integrační na lifecycle)

### Mimo scope

- UI (sekce Zaúčtování v detailu, viewer deníku, tlačítko Přeúčtovat) — Fáze 3
- saldokonto, sklad, další docTypes, DPH analytiky per vatCode, reporty
  (obratová předvaha) — viz `docs/accounting.md` sekce 10

---

## Co implementovat

### W1 — Mechanismus documentEventHandlers

**W1.1 Interface** — `src/Core/Document/DocumentEventHandler.php`:

```php
interface DocumentEventHandler
{
    /** Po commitu uložení, pokud se změnil docState (volá se po afterSave). */
    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void;

    /** Uvnitř delete transakce, před mazáním child tabulek. */
    public function onBeforeDelete(string $tableId, array $data): void;
}
```

**W1.2 Registrace** — nové pole `documentEventHandlers` v `module.jsonc`
(kompilace do cfg po vzoru `alertChecks`/document classes):

```jsonc
"documentEventHandlers": [
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Economy\\Accounting\\DocsHeadsEventHandler",
        "events": ["stateChanged", "beforeDelete"]
    }
]
```

Doplnit do `docs/modules.md` (tabulka polí module.jsonc + krátká sekce).

**W1.3 Dispatch v TableGateway**:

- `stateChanged`: v `saveDocument` **po** `$doc->afterSave($data)` —
  tj. po commitu; handler si transakce řídí sám. Dispatch jen pokud se
  docState reálně změnil — informaci o přechodu (old/new) musí poskytnout
  Document; `DocDocument::trackStateChange` změnu už detekuje, zpřístupni
  ji gateway (getter na Document, např. `getStateTransition(): ?array`,
  base Document vrací null). Žádné spoléhání na porovnávání `$originalData`
  v gateway.
- `beforeDelete`: v `deleteDocument` **uvnitř** transakce, před
  `deleteChildren` (handler maže závislá data — FK deníku by jinak
  zablokoval delete).
- Instanciace handlerů přes registry + injektáž služeb stejně jako u
  dokumentů (`injectDocServices`).
- Výjimka v handleru `stateChanged` nesmí shodit odpověď uloženého dokladu
  (commit už proběhl): catch + `ErrorLogger::logException`. U
  `beforeDelete` naopak výjimku propagovat (transakce se rollbackne).

**W1.4 Test** — fake handler v testu: počítadlo volání, ověřit, že se volá
jen při změně stavu, se správným old/new, a že `beforeDelete` běží v
transakci (rollback při výjimce nechá dokument i children netknuté).

### W2 — Deník a stav účtování

**W2.1 Tabulka** —
`modules/economy/accounting/tables/economy_accounting_journal.jsonc`,
tableId **413**, sloupce a indexy přesně podle `docs/accounting.md`
sekce 6. Bez docStates. FK: `doc_head` → `docs_core_heads`, `account` →
`economy_accounting_accounts` (nullable), `partner` → `base_persons_persons`
(nullable), `fiscal_year`/`fiscal_month` → economy_codebooks tabulky.
Zápis výhradně enginem — tabulka nepotřebuje document class ani form.

**W2.2 Extension hlavičky** —
`modules/economy/accounting/extensions/docs_core_heads.jsonc`:
`accounting_state` (enumInt, default 0, system, cfgItem
`economy.accounting.accountingStates`), `accounting_messages` (json,
nullable, system). Config `accountingStates.jsonc` podle accounting.md
sekce 3.3. Do `module.jsonc` přidat dependency `docs.core`.

### W3 — Předpis a engine

**W3.1 Předpis** —
`modules/economy/accounting/config/accountingRules.cz.jsonc` — obsah
**přesně** podle `docs/accounting.md` sekce 4 (categories / accounts /
documents pro invno + invni). cfgItem pojmenuj podle konvence configů
modulu (per-country suffix, např. `economy.accounting.accountingRules.cz`);
engine předpis hledá podle země vlastní firmy (`OwnCompanyResolver`,
lowercase), fallback `cz`.

**W3.2 AccountingEngine** —
`modules/economy/accounting/src/AccountingEngine.php`. Vstup: id dokladu
(hlavička + řádky + vat_recap si načte z DB). Výstup: zapsané řádky deníku
+ aktualizované `accounting_state`/`accounting_messages`. Algoritmus
přesně podle `docs/accounting.md` 7.3, dohledávání účtů podle sekce 5.
Implementační upřesnění:

- celé `DELETE` starého deníku dokladu + `INSERT` nových řádků + update
  hlavičky v jedné transakci
- částky: vždy pár (dom, cur) podle tabulky zdrojů v accounting.md
  sekci 4; `currency` řádku deníku = `doc_currency` hlavičky; nulové
  částky (obě 0) → řádek se negeneruje
- seskupení klíčem `(side, account_number, partner, operation)`; sčítají
  se dom i cur částky; text z prvního řádku skupiny
- dohledání masky: zvaž reuse `AccountsLookup`; SQL podmínky:
  `number LIKE {mask}%`, `account_level = 4`, aktivní `docStateMain`,
  `valid_from`/`valid_to` vůči `accounting_date`, `ORDER BY number LIMIT 1`
- nenalezený účet: `account = NULL`, `account_number` = maska doplněná
  `?` na 6 znaků, `is_error = 1` + message `account_not_found`
- `accountSrc: "item"`: účet z `economy_items.accounting_account` položky
  řádku; chybí-li položka typu 2 nebo účet → chybový řádek + message
  `item_account_missing`
- kontroly po seskupení: `round(Σ dr, 2) == round(Σ cr, 2)` jinak
  `unbalanced`; prázdný deník → `empty_journal`; chybí
  `fiscal_year`/`fiscal_month` → `fiscal_period_missing` (deník se
  negeneruje); předpis pro docType nenalezen → `rules_not_found`
- `accounting_state`: 1 pokud bez messages, 2 pokud cokoliv v messages;
  formát messages `[{"code": "...", "message": "...", "rowId": <id řádku
  dokladu|null>}]`, texty česky

**W3.3 Testy enginu** — jednotkové, s integračním datasource
(`SHIPARD_INTEGRATION_DS_PATH`), úzký filtr. Scénáře:

1. invno CZK: služby 1 000 + 21 % → 602 DAL 1000, 343 DAL 210,
   311 MD 1210; vyrovnáno; state 1; `*_cur == *_dom`
2. invni CZK: tři pohyby (504/518/548) + DPH → správné strany, MD = DAL
3. invno EUR (kurz z Fáze 1 testů): dom z `_dom` sloupců, cur z měnových;
   obě bilancují
4. zaokrouhlení: kladné → 648 (invno) / 548 (invni); záporné →
   reverseSign větev
5. `acc.entry` s položkou typu 2 a účtem → účet z položky; bez účtu →
   chybový řádek + `item_account_missing` + state 2
6. maska bez účtu v rozvrhu (dočasně archivuj 311xxx) → `311???`,
   `is_error = 1`, `account_not_found`, state 2; ostatní řádky zapsané
7. seskupení: dva řádky `sale.services` se stejným partnerem → jeden
   řádek deníku se součtem
8. idempotence: druhé spuštění enginu nezdvojí řádky

### W4 — Lifecycle, endpoint, alert

**W4.1 Handler** —
`modules/economy/accounting/src/DocsHeadsEventHandler.php` +
registrace v `module.jsonc`:

- `onStateChanged`: `newState == 40` → spustit engine; `oldState == 40 &&
  newState != 40` → smazat deník dokladu, `accounting_state = 0`,
  `accounting_messages = NULL`. Jiné přechody ignorovat.
- neočekávaná výjimka enginu: catch → `ErrorLogger` + `accounting_state=2`
  + message `{"code": "engine_error"}` — uložení dokladu nikdy nepadá
  kvůli účtování
- `onBeforeDelete`: smazat řádky deníku dokladu

**W4.2 Endpoint** — `POST /_accounting/reaccount`, body `{"docId": N}`,
controller `AccountingController` po vzoru `AttachmentController`
(routing, auth). Podmínky: doklad existuje a `docState == 40`, jinak 4xx
s chybou. Akce: spustí engine, vrátí `{accountingState, messages}`.
Zdokumentovat v `docs/rest-api.md`.

**W4.3 Alert check** — inline v `module.jsonc` `economy.accounting`,
per-record check (vzor existujících Checks):

- id `economy.accounting.accounting_errors`, severity `warning`
- záběr: `docs_core_heads` kde `docState = 40 AND accounting_state = 2`;
  jeden alert per doklad, text s číslem dokladu a prvním message
- rozpuštění: doklad už podmínce nevyhovuje (přeúčtováno OK / opustil 40)
- POZOR (viz learnings): chyba běhu checku nesmí rozpustit existující
  alerty — resolve jen při úspěšném průchodu

**W4.4 Dokumentace** — `docs/accounting.md`: doplnit sekci "Stav
implementace" (Fáze 2 hotová, co je Fáze 3); `docs/modules.md`:
`documentEventHandlers`; `docs/rest-api.md`: endpoint.

---

## Hotovo když

1. `ds-upgrade` projde: tabulka 413, extension sloupce na heads.
2. End-to-end na dev datasource: faktura vydaná (CZK, 2 řádky služby +
   DPH) → přechod do 40 → v deníku 3 řádky (602/343/311), MD = DAL,
   `accounting_state = 1`. Přechod do 80 → deník prázdný, state 0.
   Znovu do 40 → deník znovu, nezdvojený.
3. Cizoměnová faktura: řádky deníku mají vyplněné a bilancující dom i cur
   částky, `currency` = měna dokladu.
4. Chybový scénář: archivovaný účet → po (pře)účtování `504???`/`311???`
   řádek, state 2, alert se objeví; oprava rozvrhu + `POST
   /_accounting/reaccount` → state 1, alert rozpuštěný.
5. Storno dokladu ve stavu 40 → deník smazaný. Smazání dokladu (90 →
   delete) projde — FK deníku neblokuje.
6. `documentEventHandlers` má test s fake handlerem (W1.4); engine testy
   W3.3 zelené; úzké filtry (`--filter 'Accounting|Journal|EventHandler'`).
7. Existující testy dokladů a alertů neporušené.

## Doporučené pořadí

1. W1 (mechanismus + test) → commit
2. W2 (tabulka + extension + configy) → ds-upgrade → commit
3. W3 (předpis + engine + testy) → commit
4. W4 (handler + endpoint + alert + dokumentace) → commit

## Rozhodnutí ✓

- Dispatch `stateChanged` po commitu a po `afterSave`; old/new stav
  poskytuje Document (getter), gateway nic nedopočítává.
- Výjimka enginu/handleru při uložení: zalogovat + state 2, nikdy
  neshodit uložení dokladu. U `beforeDelete` se výjimka propaguje.
- Deník bez document class, bez formu, bez docStates — zapisuje jen engine.
- Engine = delete + insert v transakci, idempotentní.
- Seskupení `(side, account_number, partner, operation)`.
- Endpoint `/_accounting/reaccount` vzniká už ve Fázi 2 (UI tlačítko ve 3).
- Konvence extension souborů: podle cílové tabulky
  (`extensions/docs_core_heads.jsonc`), ne `ext-*` — odchylka od
  accounting.md, drž realitu kódu (a oprav zmínku v accounting.md).

## Otevřené body

- Přesné pojmenování per-country cfgItem (`accountingRules.cz` vs.
  `accountingRulesCz`) — podle toho, co kompilace configů dovolí; engine
  ať má lookup zapouzdřený na jednom místě.
- Kde přesně Document expone stavový přechod (getter vs. virtuální pole)
  — zvol konzistentně s `trackStateChange`, ať to jde použít i pro
  budoucí handlery (sklad).
- Per-record alert API (klíčování alertů na záznam) — drž se přesně toho,
  co `core.alerts` umí; pokud per-record klíč chybí, řeš jedním souhrnným
  alertem se seznamem dokladů a poznamenej to do tasku Fáze 3.
