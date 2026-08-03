# docStateMain — centralizace dopočtu do persistenční vrstvy

**Stav:** hotovo

## Kontext

Import ze starého shipardu (a obecně každá zápisová cesta přes exchange Applier) zakládá
záznamy rovnou na cílovém `docState`, ale `docStateMain` zůstává na defaultu sloupce (`1`).
Dopočet `docStateMain` z cfgItemu (`DocStateConfig::getMainState()`) totiž žije jen v HTTP
controllerech (`CrudController`/`FormController`, ručně v `MailController`/`AnalysisController`/
`ChatController`) — ne v persistenční vrstvě `Document`/`TableGateway`, kterou Appliery
používají. Gateway zapisuje payload „as-is".

Potvrzeno na datech (re-import ještě neproběhl):

- `base_persons_persons`: 2808× `docState=40` → `docStateMain=1` (má být `3`); `10`→1 správně
  (shodou = default); `70`→4 správně (prošel post-apply PATCHem přes CrudController).
- `docs_core_heads`: 1578× `docState=40`→1 (má být `4`), `20`→1 (má být `2`), `30` Storno→1
  (má být `4`). Všechny ne-`10` stavy sedí na defaultu.
- `core_mail_incoming_messages`: konzistentní a správně — pošta jde přes `/_mail/import`
  (`MailController::importMessage`), který si `docStateMain` dopočítává sám. **Mimo rozsah.**
- `economy_bank_transactions`: má `docStates`, importuje se přes `StatementImportService`
  (Gateway) → latentně stejná chyba; oprava ji pokryje.

Příčina: `docStateMain` se dopočítává na úrovni controlleru místo persistenční vrstvy → cesty
mimo controller (Appliery) ho míjí.

## Návaznost

- Doc-states systém — viz `docs/doc-states.md` §9 (aktualizováno tímto úkolem: dopočet se
  přesouvá do `TableGateway`).
- Exchange Appliery: `PersonApplier`, `DocumentApplier`, `ItemApplier`.
- `StatementImportService` (economy.bank).
- Po implementaci: David dělá `ds-reset` na zdrojích + kompletní re-import → data se zapíšou
  rovnou správně. **Žádný datový backfill (D2).**

## Před implementací přečti

- `src/Core/Document/TableGateway.php` — `saveDocument()`, konstruktor
- `src/Core/Document/DocStateConfig.php` — `fromCfgItem()`, `getMainState()`
- `src/Core/Document/DocStatesDefinition.php` — `stateColumn` / `mainColumn` / `cfgItem`
- `src/Core/Database/TableDefinition.php` — `->docStates`
- `src/Api/Controller/CrudController.php` — `initDocState()`/`processDocState()` (vzor, ať se
  chování shoduje)
- `modules/core/exchange/src/Person/PersonApplier.php` — `buildGateway()`
- `modules/core/exchange/src/Document/DocumentApplier.php` — `buildGateway()`
- `modules/core/exchange/src/Item/ItemApplier.php` — `buildGateway()`
- `modules/economy/bank/src/Import/StatementImportService.php` — `create()`
- `src/Api/Controller/FormController.php` — konstrukce `TableGateway` (ř. ~218, ~527)

## Scope

**V rozsahu:** centralizace dopočtu `docStateMain` do `TableGateway::saveDocument()` +
protažení `DocStatesDefinition` na všech 6 konstrukčních míst `TableGateway`.

**Mimo rozsah:**

- Přímé `$dibi->insert` cesty (`MailController`, `AnalysisController`, `ChatController`) — nejdou
  přes Gateway, dopočítávají si samy.
- Odstranění redundantního dopočtu z controllerů (D4 — ponechat jako pojistku).
- Datový backfill (řeší re-import, D2).
- `KindResolver` (viz Otevřené body).

## Co implementovat

1. **`TableGateway::__construct`** — přidat nullable parametr `?DocStatesDefinition $docStates = null`
   **na konec** seznamu (za `$eventDispatcher`), ať se nerozbijí existující poziční volání.
   Uložit do property.

2. **`TableGateway::saveDocument()`** — hned po `$doc->beforeSave($data, $originalData)` a
   **před** oddělením child dat vložit dopočet:

   ```php
   // Odvození docStateMain z cfgItemu — jediné místo pravdy pro všechny
   // zápisové cesty přes Document/Gateway (import Applier i FormController).
   if ($this->docStates !== null && $this->config !== null) {
       $stateCol = $this->docStates->stateColumn;
       $mainCol  = $this->docStates->mainColumn;
       if (array_key_exists($stateCol, $data) && $data[$stateCol] !== null) {
           $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStates->cfgItem));
           $data[$mainCol] = $cfg->getMainState((int) $data[$stateCol]);
       }
   }
   ```

   Platí pro insert i update. `TransactionlessTableGateway` `saveDocument` nepřepisuje →
   Appliery dopočet zdědí automaticky.

3. **Zadrátovat `->docStates`** na 6 konstrukčních místech:
   - `PersonApplier::buildGateway` → `$tables[$tableName]?->docStates`
   - `DocumentApplier` (buildGateway, ř. ~174) → dtto
   - `ItemApplier` (buildGateway, ř. ~137) → dtto
   - `StatementImportService::create` (ř. ~68) → `$tables[self::TABLE_TX]?->docStates`
   - `FormController` (ř. ~218 a ~527) → `$def->docStates` (ověř, že `$def` je v daném scope)

## Hotovo když

- Osoba přes `PersonApplier` s `targetDocState=40` má po insertu `docStateMain=3` (bez
  post-apply PATCHe).
- Doklad přes `DocumentApplier`: `docState=40`→`docStateMain=4`, `30` (Storno)→`4`, `20`→`2`.
- Bankovní transakce přes `StatementImportService` má `docStateMain` dle
  `economy_bank_transactions` docStates.
- `FormController` create/update dál funguje (`docStateMain` správně; controllerový dopočet je
  teď redundantní, ale neškodný — obě cesty dají stejnou hodnotu).
- Bez `docStates` / bez `ConfigRuntime` se dopočet tiše přeskočí (žádný fatal; graceful
  degradace).
- Nový unit test: `TableGateway::saveDocument()` s `DocStatesDefinition` odvodí `docStateMain` —
  na insertu i na (idempotentním) recompute při update z plného řádku.
- Existující testy procházejí.

## Doporučené pořadí

1. `TableGateway` — konstruktor + hook v `saveDocument()`.
2. Unit test na `TableGateway` (červená → zelená).
3. Zadrátovat 3 Appliery + `StatementImportService` + `FormController`.
4. `ds-upgrade` **není** potřeba kvůli configu — jde o čisté PHP, docStates cfgItemy jsou už
   zkompilované.
5. Smoke test: založit jednu osobu i doklad přes apply endpoint a ověřit `docStateMain`. Pak
   předat Davidovi: `ds-reset` na zdrojích + kompletní re-import.

## Rozhodnutí ✓

- **D1:** Varianta A — centralizovat dopočet do persistenční vrstvy (`TableGateway::saveDocument`),
  ne cíleně do jednotlivých Applierů. ✓
- **D2:** Žádný datový backfill; David dělá `ds-reset` + kompletní re-import. ✓
- **D3:** Dopočítat `docStateMain` vždy, když je stavový sloupec v payloadu a není null (insert i
  update; idempotentní). Bez `docStates`/`config` → tiše přeskočit. ✓
- **D4:** Redundantní dopočet v controllerech (`CrudController`/`FormController`/`MailController`/
  `AnalysisController`/`ChatController`) ponechat; neodstraňovat. ✓

## Otevřené body

- `KindResolver` (`modules/core/exchange/src/Resolve/KindResolver.php`, ř. ~153–154) nastavuje
  `docState=40, docStateMain=2` v createPayloadu mimo cestu přes `TableGateway`. Pokud „kinds"
  jedou na `core.system.docStatesArchive`, mělo by být `3`. Prověřit a případně opravit zvlášť
  (mimo tento úkol).
- Živé `/_mail/incoming` (`MailController::insertIncomingMessage`) spoléhá na default
  `docStateMain=1` pro stav `10` — dnes korektní; latentní, kdyby živá pošta začala vznikat
  v jiném stavu. Mimo rozsah.
