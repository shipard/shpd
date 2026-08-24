# Task: Zrušení stavu Potvrzeno (20) na Dokladech

**Stav:** hotovo
**Issue:** [#38](https://github.com/shipard/shpd/issues/38) — analýza a rozhodnutí D1–D7 v komentáři
**Návaznost:** protistrana ve starém Shipardu — `old_shipard`
`modules/imports/newShipard/tasks/28-import-bez-stavu-potvrzeno.md`
(implementovat **až po** této úloze — nová strana pak stav 20 odmítá schematem)

## Cíl

Stavový model dokladů se zjednodušuje na **Koncept (10) → V pořádku (40)**
(+ V opravě 80, Storno 30, Smazáno 90). Stav Potvrzeno (20) se ruší:
přiděloval číslo dokladu, ale nic dalšího — doklad zůstával editovatelný
a nešel tisknout. Číslo se nově přiděluje při přechodu 10 → 40.

Roli „editovatelný doklad s číslem" plně přebírá stav 80 (V opravě),
včetně návratu do Konceptu s uvolněním čísla (řeší i issue #22).

## Rozhodnutí (potvrzeno v #38)

- **D1** — stav 20 smazat z cfgItemu; `mainState` ostatních stavů
  **neměnit** (po 2 zůstává díra) → žádný backfill `docStateMain`
- **D2** — snapshoty se staví při vstupu do 40 a 80; **import mode je
  přeskakuje** (migrovaná data mají snapshoty NULL — stavět je z dnešních
  dat osob pro historické doklady by bylo věcně špatně)
- **D3** — release čísla se stěhuje z 20→10 na **80→10**
- **D3b** — nový hook pro per-dokumentové filtrování nabídky přechodů;
  přechod →10 se nenabízí, není-li doklad poslední v řadě (řeší #22)
- **D6** — `actionName` stavu 40 zůstává „V pořádku"
- **D7** — pre-flight: žádný DS nesmí mít řádky `docState = 20`

## Scope

### 1. Konfigurace — `modules/docs/core/config/docStates.jsonc`

- Smazat blok `"20"` (vč. úvodního komentáře „+ 20 Potvrzeno…").
- `10.goto`: `[20, 90]` → `[40, 90]`.
- `80.goto`: `[40, 30, 90]` → `[40, 30, 10, 90]` (D3).
- `mainState` hodnoty 10/80/40/30/90 beze změny (1/3/4/4/5).
- Po změně nutný rebuild kompilované cfg + `ds-upgrade` (docStates je
  cfgItem v `compiled.{cs,en}.json`).

### 2. `modules/docs/core/src/DocDocument.php`

- `validate()`: prahové pole `[20, 40, 80]` → `[40, 80]` (partner,
  vat_registration, řádky, exchange_rate, vlastní firma).
- `SNAPSHOT_STATES`: `[20, 80]` → `[40, 80]`.
- **Import mode flag:** `beforeSave` už konzumuje `_importNumber`;
  zavést `private bool $importMode` nastavený z `is_array($importNumber)`
  a `maintainSnapshots()` při něm early-returnovat (D2). Reset flagu na
  začátku `beforeSave` (instance se může použít opakovaně).
- `processStateTransition()`:
  - přidělení čísla: `[0,10] → [20,40]` se mění na `[0,10] → [40]`
  - release: `old === 20 && new === 10` → `old === 80 && new === 10`
- `releaseDocumentNumber()` beze změny logiky (kontrola „poslední
  v řadě" + dekrement counteru nad klíčem `(řada, fiskální rok)`
  z originálu funguje pro 80→10 shodně).
- Aktualizovat class-level komentář pipeline (bod 8: „assignNumber
  {0,10}→{40}, releaseNumber 80→10") a komentáře u dotčených metod.

### 3. Podtřídy faktur

- `modules/docs/invoicesOut/src/IssuedInvoiceDocument.php`:
  `[20, 40, 80]` → `[40, 80]` (bank_account povinný), komentáře.
- `modules/docs/invoicesIn/src/ReceivedInvoiceDocument.php`:
  `[20, 40, 80]` → `[40, 80]` (warning partner_bank), komentáře.

### 4. Hook filtrování přechodů (D3b)

- `src/Core/Document/Document.php`: nová metoda
  `public function filterStateTransitions(array $transitions, array $row): array`
  — default pass-through.
- `src/Api/Controller/CrudController.php` → `docStateOptions()`:
  načíst celý řádek (dnes jen stavový sloupec), přes DocumentRegistry
  získat Document instanci (vč. injektáže DB — stejně jako save cesta),
  výsledek `getAvailableTransitions()` prohnat
  `filterStateTransitions()`. Bez registrované Document třídy nebo bez
  DB se filtr přeskočí (pass-through, graceful degradace jako jinde).
- `DocDocument::filterStateTransitions()`: obsahuje-li nabídka přechod
  na stav 10 a doklad má `sequence_number`, ověřit
  `MAX(sequence_number)` per `(number_series, fiscal_year <=>)` —
  není-li doklad poslední, přechod →10 z nabídky vyřadit. Doklad bez
  čísla (defenzivně) přechod ponechá.
- Server-side bariéra (`DomainException` v `releaseDocumentNumber`)
  zůstává — UI jen přestane nabízet slepou cestu.

### 5. Exchange

- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`:
  `targetDocState` enum `[10, 20, 40, 30]` → `[10, 40, 30, 80]`;
  description u `importOwnBankAccount` („state 20+" → „state 40+").
  Stav **80 je parkovací cíl migrace** (old_shipard task 28 — D5a:
  nezaúčtovatelné cmnbkp s číslem): povolen **jen v kombinaci
  s `applyOptions.importNumber`**.
- `modules/core/exchange/src/Document/DocumentValidator.php`: guard —
  `targetDocState: 80` bez `applyOptions.importNumber` = chyba
  (`error`, kód např. `target_state_80_requires_import`). Mimo migraci
  tak 80 přes exchange dosažitelné není. `DocumentApplier` změnu
  nepotřebuje (`docState` je průchozí, číslo řeší `_importNumber`,
  `processStateTransition` na 0→80 číslo nepřiděluje).
- `modules/core/mail/profiles/czech_general.jsonc`: enum ~ř. 432
  → `[10, 40, 30]` — **bez 80** (profil je pro mail/AI flow, parkovací
  stav je výhradně migrační); description ~ř. 495. Po změně profilu
  na živých DS nutný `bin/shpd-ds ai-profile-reload --force`.
- `DocumentValidator::checkPartnerDocNumber()`: práh
  `(int) $targetState < 20` nahradit explicitně
  `(int) $targetState === 10` (warning platí pro 40, 30 i 80).

### 6. Dokumentace

- `docs/doc-states.md`: §8 tabulka `economy.docs.docStates` (bez 20,
  nové goto), odstavec „Stav Potvrzeno (20)" nahradit popisem release
  80→10 + filtrovacího hooku, §6 řádek `confirmed` — poznámku o
  dokladech nahradit odkazem na mail (`docStatesIncoming` styl používá
  dál), doplnit hook do §9/§10.
- `docs/docs-mvp.md`: stavová tabulka §, přechodová tabulka, sekce
  9.2/9.3 (přechody), pravidlo 20→10, validační matice §. Rozsáhlý
  historický dokument — aktualizovat věcně dotčené sekce, nepřepisovat
  celý.
- `docs/edit-forms.md`: zmínky Potvrdit/confirmed v popisu FormStateBar.

### 7. Bez dopadu (ověřeno)

- Účetnictví: `DocsHeadsEventHandler` zná jen 40; `AccountingEngine`,
  saldokonto, banka — bez referencí na 20.
- Frontend: FormStateBar/FormStateBadge čtou přechody z API;
  CSS `docState_confirmed` zůstává (používá mail).
- MCP (`MailDraftDocumentTool` → 10), `MessageProposalApplier`
  (default 10).

### 8. Dopady nalezené při implementaci (nad rámec zadání)

- **`ContentTagRuleCaptureHandler`** (exchange) a **`SupplierCodeCaptureHandler`**
  (mail) — oba guardovaly přechod 10→20; bez retargetu na 10→40 by učení
  content-tag pravidel a dodavatelských kódů tiše přestalo fungovat.
- **`RowHistoryEnricher::HISTORY_DOC_STATES`** `[20, 40]` → `[40, 80]`
  (zachovává sémantiku „doklad s číslem = naučená historie").
- **`FormController::buildDocStatesInfo()`** je druhý producent přechodů do
  UI (FormStateBar) — hook D3b aplikován i tam (meta + recalculate), přes
  sdílený `DocStateTransitionFilter`.
- **Exchange schema má kompilovanou variantu** `shpd.docs.document.v1.json`
  (SchemaLoader čte runtime `.json`) — editována synchronně s `.jsonc`.
- **Odchylka od zadání u profilu:** `czech_general.jsonc` nese enum
  `[10, 40, 30, 80]` (s 80), protože `ProfileSchemaDriftTest` vynucuje
  doslovnou kopii kanonického schematu. Bariérou proti 80 v mail/AI flow
  je validator guard `target_state_80_requires_import`.

## Testy

- `tests/Unit/Module/Docs/Core/DocDocumentNumberingTest.php`:
  - přidělení čísla — vzorový přechod 10→20 přepsat na 10→40
  - release testy — retarget originál `docState: 20` → `80`
  - guard „není poslední v řadě" beze změny logiky
- `tests/Unit/Module/Docs/Core/DocDocumentSnapshotsTest.php`:
  - `testMaintainSnapshotsBuildsOnFirstConfirmedTransition` — cílový
    stav 20 → 40
  - fallback test (stav z originálu 20) → 80
  - **nový:** import mode (`_importNumber`) snapshoty nestaví
- `tests/Unit/Module/Docs/Core/DocDocumentOrchestrationTest.php`,
  `DocDocumentTrackStateChangeTest.php`,
  `DocRowOperationsValidateTest.php` — vzorové stavy 20 → 40/80
  dle sémantiky testu.
- `tests/Unit/Module/Docs/InvoicesOut/IssuedInvoiceDocumentTest.php` —
  komentář + prahové stavy.
- **Nové testy:**
  - přechod 10→40 v jednom kroku: přidělí číslo **a** postaví snapshoty
  - `filterStateTransitions`: →10 vyřazen u neposledního dokladu,
    ponechán u posledního, pass-through bez čísla
- PHPUnit s úzkými `--filter` (široké filtry timeoutují),
  `timeout_sec=120`.

## Pre-flight / nasazení (D7)

Před `ds-upgrade` na hostovaných DS ověřit:

```sql
SELECT COUNT(*) FROM docs_core_heads WHERE docState = 20;
```

Očekáváno 0 všude (alfa ověřena: 4/4 DS čisté). Nález ≠ 0 → vyřešit
ručně před nasazením (přepnout na 40, resp. 10 dle stavu dokladu).

## Commity

1. `docs.core`: cfgItem + DocDocument + podtřídy faktur + testy
2. `core.document`: hook `filterStateTransitions` + CrudController
   + DocDocument implementace + testy (D3b, closes #22)
3. `core.exchange` + `core.mail`: schema, profil, validator
4. dokumentace

## Hotovo když

- [x] `docStates.jsonc` bez stavu 20, goto 10→[40,90], 80→[40,30,10,90]
- [x] přechod 10→40 přidělí číslo, postaví snapshoty, zaúčtuje
      (event handler beze změny)
- [x] import mode (`_importNumber`) snapshoty nestaví
- [x] 80→10 uvolní číslo posledního dokladu v řadě; neposlední padá
      `DomainException` a přechod se v UI nenabízí (issue #22 zavřít)
- [x] exchange schema + AI profil odmítají `targetDocState: 20`
- [x] `targetDocState: 80` projde jen s `applyOptions.importNumber`,
      jinak chyba validace (+ test)
- [x] všechny dotčené testy zelené (celá Unit sada, 4379 testů)
- [x] doc-states.md, docs-mvp.md, edit-forms.md (+ exchange-format.md,
      features.md) aktualizovány
- [ ] pre-flight SELECT na všech hostovaných DS = 0 (deploy krok — alfa
      ověřena 4/4; před `ds-upgrade` na hostovaných DS zopakovat)
