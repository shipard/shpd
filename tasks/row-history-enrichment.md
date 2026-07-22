# Obohacení řádků extrahovaných dokladů z historie (Row History Enrichment)

**Status:** Design uzavřen (D1–D9 potvrzeno), připraveno k implementaci
**Cíl:** Řádky AI-extrahovaných dokladů (item + vat_code + account) se automaticky
předvyplní podle historie vystavených dokladů téhož dodavatele. Uživatel u
opakovaných faktur (internet, nájem, …) položky nepřiřazuje ručně — systém je
navrhne deterministicky, bez šablon a bez dalšího LLM volání.

---

## Návaznost

- **Vrstva 0 + 3** z brainstormu „AI párování položek" — deterministická
  historie + uzavření smyčky. Vrstva 1 (kontext dodavatele do extrakčního
  promptu) a Vrstva 2 (AI-autorovaná pravidla s per-pravidlo auto žebříkem)
  jsou samostatné follow-up designy, **mimo scope**.
- Staví na: `DocumentApplier` resolve pipeline (ItemResolver probe 1
  `ourCode`), canonical `shpd.docs.document.v1`, extracted-docs flow
  (`AnalysisController` `/result`, `previewExtracted`,
  `ExtractedDocumentApplier`), `economy_items_supplier_codes` (probe 2),
  lineage sloupce `docs_core_heads.source_kind` / `source_extracted_doc`.
- Klíčové poznatky z průzkumu:
  - `AccountingEngine` čte `economy_items.accounting_account` — účet typicky
    následuje položku; řádkový `account` je override.
  - `docs_core_rows.vat_code` je varchar cfg klíč (přímo canonical hodnota);
    `docs_core_rows.account` je FK → překlad na číslo účtu;
    `docs_core_rows.item` je FK → překlad na `economy_items.code`.
  - Paměť je emergentní: finalizované doklady v `docs_core_rows` **jsou**
    naučená mapování — žádná nová tabulka (D8).

## Potvrzená rozhodnutí

| D | Rozhodnutí |
|---|---|
| D1 | Návrh = trojice z historického řádku: `item.ourCode`, `vat.code`, `account`. Prázdný historický `account` se nepropisuje. Vyplněný AI `vat.code` se nepřepisuje. |
| D2 | Čistá funkce `canonical → canonical`; volá se při `/result` (persist), `previewExtracted` (fresh) a `apply` (fresh, autoritativně). |
| D3 | Enrichment jen doplňuje prázdné. Priorita: userAction pin > AI extrakce > historie. |
| D4 | Historie = řádky dokladů téhož partnera, stejný `doc_type`, `docState >= 20` (Koncepty vyloučeny — guard proti učení z vlastních chyb), nejnovější první, limit ~200 řádků. Partner nedohledán → tichý skip. |
| D5 | Normalizace description (lowercase, strip číslic/datumů/částek/interpunkce, collapse whitespace). Stupeň 0: exact match nenormalizovaného textu → `high`. Stupeň 1: exact match normalizovaného → `high`. Stupeň 2: Jaccard token-set ≥ 0.6 → `medium`, nejnovější řádek. Jinak bez návrhu. *Pozn.: doplněn stupeň 3 „dominantní položka partnera" (`historyDominantItem` / `low`) jako fallback bez textového signálu, viz `enrichment-dominant-item.md`.* |
| D6 | Výstup per řádek do `_resolve.rows[i].enrichment` — `_resolve` má `additionalProperties: true`, žádná změna schématu ani bump verze formátu. |
| D7 | Status extracted dokumentu: stávající odvození z confidence, navíc **strop na `pending_review` (20)**, pokud existuje řádek bez itemu (AI `item.ourCode` prázdné && enrichment nenavrhl). Povyšování statusu (auto) až s Vrstvou 2. |
| D8 | Žádná pattern tabulka. Jediný explicitní zápis: upsert `economy_items_supplier_codes` při přechodu dokladu 10→20 s lineage `aiExtraction`, pro řádky s extrahovaným `item.supplierCode` a přiřazenou položkou. |
| D9 | Vrstvy 1 a 2 mimo scope. |

## Scope

**In:**
1. Nová služba `RowHistoryEnricher` (`modules/core/exchange/src/Enrich/`).
2. Zapojení do tří míst: `/result`, `previewExtracted`, `ExtractedDocumentApplier::apply`.
3. Strop statusu při `/result` (D7).
4. Event handler pro zpětný zápis supplier codes (D8) v `core.mail`.
5. Unit testy všech částí.

**Out:**
- Změny JSON schématu / bump formátu (nepotřebné, viz D6).
- Frontend badge „napárováno z dokladu č. X" (samostatný follow-up task —
  data v `_resolve.rows[i].enrichment` už budou k dispozici).
- Vrstva 1 (prompt kontext), Vrstva 2 (pravidla, auto režim per pravidlo).
- Embeddingy, LLM volání, pattern tabulky.

---

## Změny soubor po souboru

### 1. `modules/core/exchange/src/Enrich/RowHistoryEnricher.php` (nový)

```php
final class RowHistoryEnricher
{
    public function __construct(
        private readonly Connection $db,
        private readonly PartyResolver $partyResolver,
    ) {}

    /** Čistá funkce: vrátí canonical s doplněnými řádky + _resolve.rows[i].enrichment. */
    public function enrich(array $canonical): array;
}
```

Kroky `enrich()`:

1. **Counterparty:** podle `selfParty` vyber protistranu (`customer` → blok
   `supplier`, `supplier` → blok `customer`; null → skip). Resolve přes
   `PartyResolver`; status != `matched` → vrátit canonical beze změny
   (tichý skip, D4). Žádné side-efekty, žádné issues.
2. **Historie:** jeden dotaz —
   ```sql
   SELECT r.description, r.item, r.vat_code, r.account, i.code AS item_code,
          a.number AS account_number, h.id AS doc_head
   FROM docs_core_rows r
   JOIN docs_core_heads h ON h.id = r.doc_head
   LEFT JOIN economy_items i ON i.id = r.item
   LEFT JOIN economy_accounting_accounts a ON a.id = r.account
   WHERE h.partner = :personId AND h.doc_type = :docType
     AND h.docState >= 20 AND h.docState NOT IN (80, 90)
     AND r.item IS NOT NULL
   ORDER BY h.id DESC
   LIMIT 200
   ```
   (přesné stavy: zahrnout 20/40/70, vyloučit 80 V opravě a 90 Smazáno;
   ověřit proti `docs.core.docStates` při implementaci).
3. **Match per řádek** (jen řádky, kde `item.ourCode` je prázdné — D3):
   text řádku = `row.description ?? row.item.description ?? row.item.name`
   (stejný fallback jako `transformRows`). *Pozn.: nahrazeno — matchuje se
   přes všechny kandidátní texty tier-major, viz
   `enrichment-row-text-candidates.md`.* Stupně dle D5; kandidáti s
   itemem ve stavu mimo `ACTIVE_STATES` (10/40/80) se přeskočí (položka
   mezitím smazána/archivována → nenavrhovat).
4. **Propsání návrhu** (D1): `row.item.ourCode = item_code`;
   `row.vat.code = vat_code` jen pokud `row.vat.code` prázdné;
   `row.account = account_number` jen pokud `row.account` prázdné a
   historický account není NULL.
5. **Audit blok** do `_resolve.rows[i].enrichment`:
   ```jsonc
   {
     "matchedBy":  "historyExactRaw" | "historyExactNorm" | "historyFuzzy" | null,
     "confidence": "high" | "medium" | null,
     "matchedText": "…",  // vyhrávající kandidátní text (originální tvar), null bez zásahu
     "sourceDocId": 12345,
     "suggested": { "ourCode": "…", "vatCode": "…", "account": "…" }  // co reálně doplnil
   }
   ```
   Blok se zapisuje **vždy** (i `matchedBy: null` u nenapárovaných řádků a
   u řádků přeskočených kvůli vyplněnému `ourCode` — UI pak umí spočítat
   souhrn). Idempotence: opakovaný běh blok přepíše (fresh run, D2).

Determinismus: žádné `NOW()`, žádná náhoda — stejná DB + stejný vstup =
stejný výstup.

### 2. `src/Api/Controller/AnalysisController.php`

- **`/result` flow (`validateAndStoreCanonical` + insert loop, ~ř. 779–818):**
  po úspěšné schema validaci canonical spustit `enricher->enrich()`; do
  `extracted_json` se ukládá **obohacený** canonical (D2 persist). Strop
  statusu (D7): pomocná metoda `capStatusByRowCoverage(int $status, array $canonical): int`
  — pokud status je `READY_TO_APPLY` a existuje řádek s
  `item.ourCode` prázdným && `_resolve.rows[i].enrichment.matchedBy === null`
  → vrátit `PENDING_REVIEW`. Účetní doklady (`docType` bez item řádků,
  operation `acc.record`) ze stropu vyjmout — řádek bez itemu je tam validní;
  strop aplikovat jen na řádky, kde `rowKind`/`operation` item předpokládá
  (default doc řádky).
- **`previewExtracted` (~ř. 1462):** po server-controlled injection `source`
  a před `$this->applier->preview($canonical)` spustit fresh
  `enricher->enrich()` (přepíše persistnutý enrichment blok — D2b).
- **Konstrukce:** enricher postavit vedle applieru (stejná místa, kde vzniká
  `DocumentApplier` — `PartyResolver` se sestaví stejně jako v
  `DocumentApplier::create`). Applier `null` (deployment bez exchange) →
  enricher `null` → všechna volání se přeskočí (graceful degradation jako
  dnes u preview).
- Selhání enricheru **nesmí** shodit `/result` (analyzer by zprávu retryoval):
  try/catch + `ErrorLogger`, pokračovat s neobohaceným canonical.

### 3. `modules/core/mail/src/ExtractedDocumentApplier.php`

- Konstruktor: `+ private readonly ?RowHistoryEnricher $enricher = null`.
- V `apply()` po parse canonical + injection `source`, **před** merge
  `clientResolveFlat`: fresh `enrich()` (D2c). Pořadí zaručuje D3 — enrichment
  doplní jen prázdná pole řádků, klientovy userActions se mergují až po něm
  a v reconcile fázi DocumentApplieru mají absolutní přednost.
- Volající místa (`AnalysisController::applyExtracted`, MCP
  `mail_draft_document` wiring v `public/index.php`) předají instanci.

### 4. `modules/core/mail/src/SupplierCodeCaptureHandler.php` (nový, D8)

- `extends AbstractDocumentEventHandler`, `onStateChanged` pro
  `docs_core_heads`, reaguje jen na `oldState === 10 && newState === 20`
  a `source_kind === 'aiExtraction'` (resp. `source_extracted_doc > 0`).
- Načte `extracted_json` zdrojového extracted dokumentu, projde canonical
  řádky s vyplněným `item.supplierCode`, spáruje s finálními
  `docs_core_rows` přes `order_pos`, a pro řádky s přiřazeným itemem
  provede `INSERT IGNORE` do `economy_items_supplier_codes`
  `(person = h.partner, item, supplier_code, supplier_name = item.name z canonical, created)`.
- Výjimky: dispatcher je po commitu loguje a polyká (stávající sémantika
  `stateChanged`) — capture je best-effort, nikdy neblokuje vystavení.

### 5. `modules/core/mail/module.jsonc`

- Registrace handleru do `documentEventHandlers`
  (vzor: `modules/economy/accounting/module.jsonc` ř. ~71):
  `{ table: "docs_core_heads", class: "…\\SupplierCodeCaptureHandler", events: ["stateChanged"] }`.
- Po změně: rebuild compiled cfg + `ds-upgrade` (dva kroky, standardní workflow).

### 6. Dokumentace

- `docs/ai.md` §3/§4 — zmínka o enrichment kroku (jedna věta + odkaz).
- `modules/core/mail/docs/ai-analysis.md` — nová sekce „Obohacení řádků
  z historie" (flow, D-tabulka, tvar `_resolve.rows[i].enrichment`).
- `docs/README.md` / `tasks/README.md` — **neaktualizovat** (Davidova režie).

---

## Testy

PHPUnit, vzory z `tests/Unit/Module/Core/Exchange/`:

1. **`Enrich/RowHistoryEnricherTest.php`** (nový):
   - normalizace: datumy/období/částky se odstraní; „Internet 500M 6/2026"
     ~ „Internet 500M 7/2026" → exact-norm match;
   - „Linka 500" vs „Linka 1000" → po normalizaci kolize → ověřit, že
     stupeň 0 (raw exact) má přednost a fuzzy práh je nepustí pod `high`;
   - D3: vyplněný `item.ourCode` → řádek se nedotkne, blok `matchedBy: null`
     + poznámka skip;
   - D1: vyplněný AI `vat.code` se nepřepíše; prázdný historický account
     se nepropíše;
   - partner unmatched → canonical beze změny;
   - kandidát s itemem v docState 90 → přeskočen;
   - determinismus: dvojí běh = identický výstup.
2. **`AnalysisControllerResultTest`** (rozšíření stávajících result testů):
   - persist obohaceného canonical;
   - D7 strop: ready confidence + řádek bez návrhu → status 20;
   - všechny řádky pokryté → status dle confidence beze změny;
   - enricher vyhodí výjimku → `/result` projde, canonical neobohacen.
3. **`ExtractedDocumentApplierTest`** (rozšíření): enrichment běží před
   merge userActions; pin `useExisting` na řádku vítězí nad enrichment
   návrhem (reconcile).
4. **`SupplierCodeCaptureHandlerTest`** (nový): 10→20 s lineage → upsert;
   bez `supplierCode` → no-op; opakovaný přechod → INSERT IGNORE idempotence;
   ne-aiExtraction doklad → no-op.

Filtry úzké (`--filter RowHistoryEnricher` apod.) — široké filtry způsobují
timeouty.

---

## Commit strategie

1. **Commit 1:** `RowHistoryEnricher` + unit testy (čistá služba, bez wiring).
2. **Commit 2:** wiring do `/result` + `previewExtracted` +
   `ExtractedDocumentApplier` + strop statusu (D7) + testy controlleru/applieru.
3. **Commit 3:** `SupplierCodeCaptureHandler` + registrace v `module.jsonc`
   (schema/cfg změna jde první v rámci commitu) + testy + dokumentace.

---

## Hotovo když

- [ ] `RowHistoryEnricher::enrich()` je deterministická čistá funkce, pokrytá
      unit testy včetně normalizačních edge cases.
- [ ] `/result` persistuje obohacený `extracted_json`; selhání enricheru
      `/result` neshodí.
- [ ] `previewExtracted` i `apply` spouštějí fresh enrichment; userAction
      piny mají přednost.
- [ ] Status extracted dokumentu se stropuje na `pending_review` při
      nepokrytém řádku (jen doc typy s item řádky).
- [ ] Přechod 10→20 dokladu s lineage `aiExtraction` upsertuje
      `economy_items_supplier_codes` z extrahovaných `supplierCode`.
- [ ] `_resolve.rows[i].enrichment` má dokumentovaný tvar
      (ai-analysis.md) a žádná změna `shpd.docs.document.v1` schématu
      nebyla potřeba.
- [ ] Všechny nové/rozšířené testy zelené s úzkými filtry.
- [ ] Opakovaná faktura od známého dodavatele projde end-to-end: extrakce →
      enrichment → preview ukazuje předvyplněné trojice → apply → Koncept
      má správné položky bez ručního zásahu.
