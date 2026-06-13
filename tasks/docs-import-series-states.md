# Import dokladů: výběr číselné řady + cílové stavy 40/30

## Kontext

Revize importu dokladů ze starého Shipardu odhalila tři problémy, z nichž dva
mají kořen na této straně (třetí řeší párový task v old_shipard
`modules/imports/newShipard/tasks/10-docs-import-revision.md`):

1. **Všechny doklady končí v jedné řadě.** Kanonický formát
   `shpd.docs.document.v1` nenese identitu číselné řady a
   `DocumentApplier::resolveNumberSeriesFor()` bere první aktivní řadu pro
   `doc_type` (`ORDER BY id LIMIT 1`). U `invni` existují dvě řady
   (`doc_number_code` 1 = Faktury přijaté, 5 = Ostatní závazky) — všech 479
   importovaných dokladů skončilo v řadě s kódem 1.

2. **Doklady končí ve stavu 20 (Potvrzeno), ne 40 (V pořádku).** Schema
   omezuje `applyOptions.targetDocState` na enum `[10, 20]` (rozhodnutí z
   Fáze 05b, před vznikem účetnictví). Důsledek: výchozí akce formuláře u
   stavu 20 jde 20→10, což volá `releaseDocumentNumber()` a pojistka proti
   díře v sekvenci korektně odmítne každý doklad, který není poslední v řadě
   — importované doklady uprostřed řady tak nejdou uložit. Ve stavu 40 jde
   editace 40→80→40 bez uvolnění čísla.

Starý doklad svou řadu zná: `dbCounter` na hlavičce → cfg
`e10.docs.dbCounters.{docType}.{dbCounter}.docKeyId` dává kód, který
odpovídá `doc_number_code` (%C token) nové řady. Mapování je tedy
`(doc_type, doc_number_code)`.

Infrastruktura pro účtování při importu na 40 **z velké části existuje**:
`DocDocument::trackStateChange()` při create mimo Koncept nastaví transition
`old=0, new=X`, `TableGateway` ji po commitu dispatchne a
`DocsHeadsEventHandler::onStateChanged()` pro `newState=40` spustí
`AccountingEngine` (error-tolerantně: výjimka → `accounting_state=2` +
log, import se nezablokuje). Pro `newState=30` (Storno) se engine nespouští —
invariant „deník právě tehdy, když stav 40" platí.

**ALE — dispatch funguje jen ve formulářové cestě.** Exchange applier staví
`TransactionlessTableGateway` BEZ `DocumentEventDispatcher` (7. arg konstruktoru
zůstává `null`), `DocumentApplier::create()` žádný dispatcher nepřijímá a
`dispatchExchange()`/`dispatchAnalysis()` v `public/index.php` ho nethreadují.
Důsledek: `dispatchStateChanged()` při apply nevystřelí a doklad uložený rovnou
na 40 se NEZAÚČTUJE. Zapojení dispatcheru do exchange/analysis cesty je proto
součástí tohoto tasku (viz „Co implementovat" bod 4).

**Nález C — pohyb řádku (`operation`) blokuje stav 40.** Při přechodu do 40
běží `DocDocument::validateRowOperations`: každý item-řádek musí mít
`operation` (pohyb, cfgItem `docs.core.rowOperations`) povolený pro daný
docType. Kanonický formát ale pole pro pohyb neměl a applier ho nenastavoval —
všechny migrované item-řádky mají `operation = NULL`, proto doklady uvízly ve
stavu 20 (tam se pohyby nevalidují). Řešení: kanonický `rows[].operation`
(volitelný string), applier ho mapuje verbatim do `docs_core_rows.operation`;
runner ho musí posílat (invni: `purchase.goods`/`.services`/`.other`/`acc.entry`).
Bez pohybu se item-řádek na 40 nedostane (záměrně — odpovědnost runneru).
Storno (30) tím netrpí: stav 30 není v `[20,40,80]`, validace pohybů neběží.

## Cíl

Applier umí cílit konkrétní číselnou řadu podle kódu a přijímá cílové stavy
40 (V pořádku) a 30 (Storno). Import dokladu na 40 vede k zaúčtování,
import na 30 nikoliv.

## Návaznost

- Předchází: Fáze 05/05b (import dokladů + import-mód čísla), accounting
  phase 1–3 (engine, journal, event handler).
- Páruje se s: old_shipard `tasks/10-docs-import-revision.md` — DocsRunner
  začne posílat `numberSeriesCode`, stavy 40/30 **a `rows[].operation`**
  (pohyb řádku, viz Nález C). **Tento task se nasazuje první** (applier musí
  nové volby přijímat dřív, než je runner pošle).

## Před implementací přečti

- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` — blok
  `applyOptions` (~ř. 150–180)
- `modules/core/exchange/src/Document/DocumentApplier.php` —
  `resolveNumberSeriesFor()` (~ř. 931), `buildHeadPayload` okolí ř. 790–880
  (targetDocState, importNumber)
- `modules/core/exchange/src/Document/DocumentValidator.php` — validace
  `targetDocState` (~ř. 175)
- `modules/docs/core/src/DocDocument.php` — `trackStateChange()` (~ř. 236),
  `processStateTransition()` (~ř. 839), `applyImportNumber()` (~ř. 872)
- `modules/economy/accounting/src/DocsHeadsEventHandler.php`
- `modules/docs/core/config/docStates.jsonc` — stavový automat
  (10/20/80/40/30/90; Storno=30, 70 „V archívu" je pro doklady odstraněno)
- `docs/accounting.md` — invariant deníku

## Scope

Exchange applier + schema + zapojení dispatcheru (`public/index.php`,
`DocumentApplier::create`). Nesahat na: DocsRunner (old_shipard, párový
task), `applyImportNumber` (funguje správně — GREATEST counter sync per
(number_series, fiscal_year) zůstává), UI dokladů, stavový automat,
samotný `AccountingEngine` / `DocsHeadsEventHandler` (jen ho zapojit).

## Co implementovat

### 1. Schema: `applyOptions.numberSeriesCode` + rozšíření `targetDocState`

V `shpd.docs.document.v1.jsonc`:

- `"numberSeriesCode": { "type": "string", "minLength": 1 }` — volitelný
  kód řady (%C, odpovídá `docs_core_number_series.doc_number_code`).
  Description: výběr číselné řady v rámci doc_type; bez něj platí stávající
  chování (první aktivní řada).
- `"targetDocState": { "type": "integer", "enum": [10, 20, 40, 30] }`
  (30 = Storno).

### 2. DocumentApplier: resoluce řady podle kódu

`resolveNumberSeriesFor(string $docType, ?string $seriesCode)`:

- `$seriesCode !== null` → `WHERE doc_type = %s AND doc_number_code = %s
  AND docState IN (aktivní)`. **Nenalezeno → apply-level error** (doklad
  selže s jasnou hláškou „číselná řada doc_type=X kód=Y neexistuje"),
  žádný tichý fallback na první řadu.
- `$seriesCode === null` → stávající chování (první aktivní), beze změny
  (zpětná kompatibilita pro klienty, kteří kód neposílají).

### 3. Cílové stavy 40 a 30

- `DocumentValidator` target stav nikde nezamítá (jen `checkPartnerDocNumber`
  warninguje při ≥20) — brána je čistě schema enum, žádná změna validatoru
  není nutná.
- Ověřit průchod create-at-40: transition `old=0 → 40` se dispatchne,
  engine zaúčtuje, `accounting_state=1` (resp. 2 + alert při chybě).
  **Podmínka: dispatcher zapojen, viz bod 4.**
- Ověřit create-at-30: žádná transition do 40 → žádný deník.
- `importNumber` se přikládá i pro stav 30 (stornované doklady mají
  přidělené číslo a musí synchronizovat counter).
- Ověřeno: nic v create cestě nevaliduje docState proti `goto` automatu
  (`DocStateConfig::canTransition` se volá jen ve viewerech/formulářích) —
  create není přechod, není co povolovat.
- Caveat: create-at-40 BEZ `importNumber` by nedostal reálné číslo
  (`processStateTransition` umí jen 10→20). Pro migraci je `importNumber`
  vždy přítomen, takže OK.

### 4. Zapojení accounting dispatcheru do exchange cesty

- `DocumentApplier::create()` + konstruktor: přidat `?DocumentEventDispatcher
  $eventDispatcher = null`; `buildGateway('docs_core_heads', …)` ho předá heads
  gateway (persons/items ne).
- `public/index.php`: prothreadovat `$documentEventDispatcher` (ř. ~160) do
  `dispatchExchange()` a `dispatchAnalysis()` a do všech tří
  `DocumentApplier::create(...)` (ř. ~329 MCP draft, ~396 exchange, ~519 analysis).
- Engine běží uvnitř applierovy outer transakce (transactionless gateway →
  dispatch padne před no-op commitem), takže deník + `accounting_state` se
  commitnou atomicky s dokladem; výjimka enginu → handler ji chytí
  (`accounting_state=2`), import nepadá.

### 5. Pohyb řádku `rows[].operation` (Nález C)

- Schema: do `$defs.Row` přidat `"operation": { "type": ["string", "null"] }`
  (za `rowKind`). Promítnout i do `.json` a do inline kopie v AI profilu
  `default_czech_invoices.jsonc` (drift testy).
- `DocumentApplier::transformRows`: mapovat `row['operation']` → `operation`
  (verbatim; chybí → null přes array_filter). Žádný auto-default — pohyb dodá
  runner (jinak item-řádek neprojde na 40).

## Hotovo když

- [x] Schema přijme `numberSeriesCode` a `targetDocState` 40/30; starý
      payload (bez kódu, stav 20) projde beze změny chování.
- [x] Unit: resoluce řady — kód 5 u invni → řada „Ostatní závazky"; kód
      neexistující → error; bez kódu → první aktivní.
- [x] Integrace: apply dokladu `targetDocState=40` + `importNumber` +
      `rows[].operation` → doklad ve 40, správná řada, `doc_number`/
      `sequence_number` verbatim, counter = GREATEST, deník existuje,
      `accounting_state=1`. (ověřeno proti btpg: 3 řádky deníku)
- [x] Integrace: apply `targetDocState=30` → stav 30, číslo+counter ano,
      deník neexistuje.
- [x] Integrace: apply s neznámým `numberSeriesCode` → `number_series_not_found`
      (422), žádný doklad.
- [x] PHPUnit (celá suite zelená až na pre-existing
      `AIAnalyzerProvisionerTest`, mimo scope).

## Doporučené pořadí

1. Schema + numberSeriesCode (enum [10,20,40,30])
2. resolveNumberSeriesFor + error cesta (`number_series_not_found`, 422)
3. Zapojení dispatcheru do exchange/analysis (`DocumentApplier::create`, index.php)
4. `rows[].operation` (Nález C) — schema + profil + transformRows
5. Integrace stavů 40/30 + testy účtování; celé testy, commit po logických celcích

## Rozhodnutí ✓

- Řada se vybírá kódem `(doc_type, doc_number_code)`; chybějící řada =
  fail dokladu, ne fallback. (revize importu, 2026-06)
- Migrované doklady se importují do stavu 40 a **zaúčtují se novým
  enginem** (error-tolerantně). Stav 20 se pro migraci přestává používat.
- Storna (staré 4100) → stav **30 (Storno)**, s číslem, bez deníku.
  (Dřívější verze tasku uváděla 70 — to je „V archívu" a pro doklady
  neexistuje; opraveno 2026-06-13.)
- Dispatch účtování se do exchange applieru musí teprve zapojit — dnes tam
  `DocumentEventDispatcher` chybí, takže apply na 40 bez tohoto zapojení
  nezaúčtuje. (zjištěno 2026-06-13)
- Pohyb řádku se přenáší přes nové kanonické `rows[].operation`; applier ho
  mapuje verbatim, žádný auto-default — pohyb je odpovědnost runneru.
  (Nález C, 2026-06-13)
- `applyImportNumber` (GREATEST sync) zůstává beze změny.

## Otevřené body

- formatVersion: pole jsou volitelná (nedestruktivní rozšíření) — ověřit,
  zda validátor pinuje verzi; pokud ano, přijmout 1.0 i 1.1 a DocsRunner
  nechat posílat 1.0, dokud nové volby nepoužije.
