# Task: MCP server — draft nástroj mail_draft_document (Fáze 3)

## Kontext

První **zápisový** nástroj katalogu. Doručuje původní headline: agent z
analyzované došlé pošty připraví koncept dokladu, který člověk jen zreviduje.

Designová diskuze zjistila, že apply flow pošty **už existuje a brzdu má
vestavěnou**:

- `AnalysisController::applyExtracted` bere `extracted_json` (kanonický
  `shpd.docs.document.v1`) a pouští ho přes `DocumentApplier`. Vytvořený doklad
  vzniká jako **Koncept** — `applyOptions.targetDocState` defaultuje na `10`.
- Reference (dodavatel/odběratel/položky/banka) řeší `_resolve` + přepínač
  `autoCreateMode` (`safe` vs `strict`).
- V `safe` módu `DocumentApplier::resolveOne()` u reference s `userAction=null`,
  kterou nelze napárovat na existující ani bezpečně autovytvořit (`safetyGuardOk`),
  vrátí **`unresolved_required`** a `apply()` **selže** — nezaloží se nic.
- Po úspěšném apply se extrahovaný doklad označí `applied` → zpráva 30→40.
  Audit vestavěn: `source.kind='aiExtraction'`, `applied_by`.

**Uzavřená rozhodnutí (varianta B + refactor):**

- Nástroj zakládá doklad jako **Koncept** (`targetDocState=10`); finalizaci
  (10→20→40) dělá vždy člověk přes stavový automat dokladu v existujícím
  vieweru. AI nikdy nefinalizuje.
- **`autoCreateMode='safe'`** napevno — AI jen linkuje existující master data,
  **nikdy nezakládá** nové dodavatele/položky/banky. Reference vyžadující
  rozhodnutí → koncept se nezaloží, nástroj nahlásí, co dořešit ručně.
- Apply jádro se **vytáhne z `AnalysisController` do sdílené služby**, aby HTTP
  endpoint i MCP nástroj jely jedním kódem.

## Návaznost

- Staví na Fázích 1–2 (skelet, `McpTool`/registry/context, obálka).
- Refaktoruje `AnalysisController::applyExtracted` (Fáze 3c pošty) — **chování
  HTTP endpointu se nesmí změnit** (existující testy musí zůstat zelené).
- Konzumuje `mail_list_pending` (Fáze 2): agent z něj má `ref` zprávy a
  `pending_extracted_count`; tento nástroj na úrovni zprávy ty doklady založí.

## Před implementací přečti

- **`src/Api/Controller/AnalysisController.php`** — `applyExtracted()` (~ř. 1122)
  je zdroj refaktoru: injekce `source.extractedDoc`/`kind`, merge `_resolve`
  userActions, `autoCreateMode` (`safe`/`strict`), `targetDocState` (default 10),
  volání `applier->apply()`, recovery cesty (`target_row_ndx` set,
  already-applied), `updateExtractedStatus()` → `afterPersist` auto-transition
  30→40. **Toto jádro vytáhnout do služby.**
- **`modules/core/exchange/src/Document/DocumentApplier.php`** — `create()`
  factory (jak se staví z `ConfigRuntime`), `apply()`/`preview()`, `resolveOne()`
  (chování `safe`/`unresolved_required`/`safetyGuardOk`), `FORMAT_ID`/`_VERSION`.
- **`modules/core/exchange/src/Common/ApplyResult.php`** — `{success, canonical,
  savedId, errorCode, errorMessage, statusCode}`.
- **`public/index.php`** — `dispatchExchange`/`dispatchAnalysis` ukazují přesné
  wiring `DocumentApplier::create(...)` (vyžaduje `ConfigRuntime`); `dispatchMcp`
  sem doplní stavbu služby + registraci nástroje.
- **`modules/core/mail/src/ExtractedDocumentDocument.php`** — status konstanty
  (10/20/30 akční, 40 applied, 50 rejected, 60 superseded, 70 ai_failed) a
  `afterPersist` auto-transition.
- **`modules/core/mail/src/Mcp/MailListPendingTool.php`** + **`PersonsSearchTool.php`**
  — vzor MCP nástroje a obálky.
- **`src/Api/Mcp/McpInvocationContext.php`** + **`McpToolRegistry.php`** —
  rozšíření o injektovanou závislost (viz bod 3).

## Scope

**V rozsahu:**

- Refactor: extrakce apply jádra z `AnalysisController::applyExtracted` do
  sdílené služby (`ExtractedDocumentApplier` v `modules/core/mail/src/`).
- `AnalysisController::applyExtracted` přepsán jako tenká HTTP slupka nad
  službou — beze změny chování a kontraktu.
- Nový MCP nástroj `mail_draft_document` (write-tier) + wiring v `dispatchMcp`.
- Testy: služba, nástroj, regrese HTTP endpointu.

**Mimo rozsah:**

- `autoCreateMode='strict'` / zakládání master dat AI (varianta C) — nedělat.
- Finalizace / účtování dokladu — AI nikdy nemění docState za hranici Konceptu.
- `_resolve` rozhodnutí od AI — nástroj posílá `userAction=null` (jen
  auto-link + safe autocreate). Interaktivní resolve zůstává člověku v UI.
- Read-tier `mail_preview_extracted` / `mail_get_message` — případný pozdější
  follow-up (viz Otevřené body).
- Změny `mail_list_pending`.

## Co implementovat

### 1. Refactor — sdílená služba `ExtractedDocumentApplier`

Nová třída `modules/core/mail/src/ExtractedDocumentApplier.php`, namespace
`Shipard\Module\Core\Mail\`. Encapsuluje **celé** dnešní jádro
`applyExtracted()` (HTTP-agnostické):

```php
final class ExtractedDocumentApplier
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly DocumentApplier $applier,
    ) {}

    /**
     * Aplikuje jeden extrahovaný doklad → vytvoří doklad v cílové tabulce
     * (default Koncept) a označí extracted jako applied (spustí 30→40).
     *
     * @param array<string,mixed>|null $clientResolveFlat  {path: userAction} nebo null
     * @param array<string,mixed> $applyOptionsOverride     autoCreateMode / targetDocState
     */
    public function apply(
        int $extractedNdx,
        ?int $userId,
        ?array $clientResolveFlat,
        array $applyOptionsOverride = [],
    ): ExtractedApplyOutcome;
}
```

`ExtractedApplyOutcome` (readonly) nese vše, co dnes endpoint vrací i
chybové cesty rozlišitelně:

```php
final class ExtractedApplyOutcome
{
    public bool $ok;
    public ?int $savedDocId;       // id vzniklého dokladu (Koncept)
    public int $messageNdx;
    public int $extractedNdx;
    public ?string $errorCode;     // 'unresolved_required' | 'ai_output_invalid' | 'invalid_state' | ...
    public ?string $errorMessage;
    public ?array $canonical;      // s _resolve.issues (pro nahlášení, co dořešit)
    public bool $idempotent;       // už bylo applied
}
```

Logika přesně podle dnešního `applyExtracted` (zachovat všechny větve):
`ai_failed` → chyba `ai_output_invalid`; `target_row_ndx>0` / already-applied →
idempotentní/recovery; ne-pending status → `invalid_state`; jinak parse
canonical, inject `source`, merge `_resolve`, `autoCreateMode`/`targetDocState`,
`applier->apply()`; při úspěchu `updateExtractedStatus(...APPLIED)` →
`afterPersist` 30→40. **`updateExtractedStatus` + helpery (`expandUserActions`,
`mergeUserActions`, `completeApplied`) přesunout do služby**; controller je už
nevolá přímo.

### 2. `AnalysisController::applyExtracted` → tenká slupka

Controller nadále vlastní HTTP kontrakt (parse body `_resolve`/`applyOptions`,
auth, mapování na `Response`), ale tělo deleguje na službu:

```php
public function applyExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
{
    if (!$auth->isAuthenticated) return Response::error('UNAUTHORIZED', 'Authentication required', 401);
    if ($this->applier === null) {
        return $this->updateExtractedStatusFallback(...); // ponechat legacy fallback bez applieru
    }
    $body = is_array($request->getBody()) ? $request->getBody() : [];
    $service = new ExtractedDocumentApplier($this->db, $this->applier);
    $outcome = $service->apply(
        $extractedNdx,
        $auth->userId,
        array_key_exists('_resolve', $body) && is_array($body['_resolve']) ? $body['_resolve'] : null,
        is_array($body['applyOptions'] ?? null) ? $body['applyOptions'] : [],
    );
    return $this->outcomeToResponse($outcome); // stejné payloady/HTTP statusy jako dnes
}
```

**Kritérium:** existující testy `applyExtracted` (apply happy path, ai_failed,
already-applied/recovery, invalid_state, unresolved) projdou **beze změny**.
Pozn.: `autoCreateMode` default zůstává odvozený z přítomnosti `_resolve` klíče
(safe/strict) — pro HTTP nezměněno; MCP nástroj override-uje explicitně (bod 3).

### 3. MCP nástroj `mail_draft_document`

`modules/core/mail/src/Mcp/MailDraftDocumentTool.php`, namespace
`Shipard\Module\Core\Mail\Mcp\`. Injektovaná závislost na službě:

```php
public function __construct(private readonly ?ExtractedDocumentApplier $applier) {}
```

**`name()`** → `mail_draft_document`

**`description()`** (LLM-facing):
> „Z analyzované došlé pošty založí KONCEPT dokladu (faktury) z extrahovaných
> dokumentů zprávy. Doklad vznikne jako koncept k revizi — AI ho NIKDY
> nefinalizuje ani neúčtuje. Existující dodavatele a položky napáruje; nové
> NEzakládá — pokud reference vyžaduje rozhodnutí, koncept k ní nezaloží a
> nahlásí, co je třeba dořešit ručně v aplikaci. `message_id` získáš z
> `mail_list_pending` (zprávy s `pending_extracted_count > 0`). Nepoužívej na
> zprávy bez analýzy."

**`inputSchema()`**:
```json
{
  "type": "object",
  "properties": {
    "message_id": { "type": "integer", "description": "ID zprávy z mail_list_pending" },
    "extracted_document_id": { "type": "integer", "description": "Volitelně: založit jen tento jeden extrahovaný doklad; jinak všechny čekající ze zprávy" }
  },
  "required": ["message_id"]
}
```

**`call($args, $ctx)`**:
1. Pokud `$this->applier === null` (ConfigRuntime nebyl při wiringu) → obálka se
   `summary` „Zakládání konceptů není v tomto kontextu dostupné." a prázdnými
   items (nepadat).
2. Vyber čekající extrahované doklady zprávy: `SELECT id, doc_type, confidence,
   status FROM core_mail_extracted_documents WHERE message = :message_id` —
   pokud `extracted_document_id` zadán, zúžit na něj; jinak jen akční statusy
   (10/20/30). `ai_failed`/`applied`/`rejected`/`superseded` přeskoč s poznámkou.
3. Pro každý zavolej `$this->applier->apply($extractedNdx, $ctx->auth->userId,
   null /* žádný _resolve */, ['autoCreateMode' => 'safe', 'targetDocState' => 10])`.
4. Posbírej per-doc výsledky do obálky:

```php
return [
  'summary' => "Z pošty #{$msgId}: založeno {$drafted} konceptů" .
               ($needsResolve ? ", {$needsResolve} čeká na ruční dořešení" : "") .
               ($skipped ? ", {$skipped} přeskočeno" : "") . ".",
  'items' => $perDoc,   // viz níže
  'pagination' => null,
];
```

`items` položka per extrahovaný doklad:
- úspěch: `{extracted_ref:{type:'extracted_document',id}, drafted:true, document_ref:{type:'document', id:savedDocId}, doc_type}`
- `unresolved_required`: `{extracted_ref, drafted:false, needs_resolution:true, reason:"Reference vyžadují rozhodnutí — dořeš v aplikaci", unresolved:[ ...z canonical._resolve.issues: cesty/popisy ]}`
- přeskočeno (ai_failed/applied/…): `{extracted_ref, drafted:false, skipped:true, reason:"..."}`

(Žádné syrové sloupce; `unresolved` jen kurátorovaný seznam cest + lidských
popisů, ať agent ví, co chybí.)

### 4. Wiring v `dispatchMcp`

Postav `DocumentApplier` jako v `dispatchExchange`/`dispatchAnalysis` (vyžaduje
`ConfigRuntime`); z něj službu; tu injektuj do nástroje. Read nástroje zůstávají
bez argumentů.

```php
$draftApplier = $configRuntime !== null
    ? new \Shipard\Module\Core\Mail\ExtractedDocumentApplier(
        $db,
        \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(/* …jako dispatchExchange… */),
      )
    : null;

$registry->register(new \Shipard\Module\Core\Mail\Mcp\MailDraftDocumentTool($draftApplier));
```

(Kontext `McpInvocationContext` se nemění — závislost jde konstruktorem nástroje,
request-data zůstává v kontextu. Nástroj si `userId` bere z `$ctx->auth`.)

### 5. Testy

- **Služba** `ExtractedDocumentApplier`: úspěšný apply → Koncept (`docState=10`)
  + extracted `applied` + zpráva 30→40; `unresolved_required` (reference bez
  matche v safe) → `ok=false`, doklad nevznikne, zpráva zůstane 30; `ai_failed`
  → `ai_output_invalid`; already-applied → idempotent.
- **Regrese HTTP**: stávající `applyExtracted` testy zelené beze změny.
- **Nástroj** `mail_draft_document`: `tools/list` ho obsahuje; zpráva s jedním
  čistým extracted dokladem → `drafted:true` + `document_ref`; zpráva s
  nevyřešitelnou referencí → `drafted:false, needs_resolution`, žádný doklad;
  `extracted_document_id` zúží na jeden; `ai_failed` přeskočen; bez ConfigRuntime
  → graceful obálka.
- **Bezpečnost**: ověř, že nástroj posílá `autoCreateMode='safe'` a
  `targetDocState=10` (žádné master data nevzniknou, doklad je Koncept).

## Hotovo když

1. `ExtractedDocumentApplier` nese apply jádro; `AnalysisController::applyExtracted`
   je tenká slupka a jeho HTTP chování/kontrakt se nezměnily (testy zelené).
2. `mail_draft_document` je v `tools/list` a z analyzované zprávy zakládá
   **Koncept** dokladu(ů) přes sdílenou službu.
3. Nástroj jede `autoCreateMode='safe'`, `targetDocState=10` — AI nikdy
   nezakládá master data ani nefinalizuje doklad.
4. Reference vyžadující rozhodnutí → koncept se nezaloží, vrátí se
   `needs_resolution` s popisem, co dořešit; zpráva zůstane nezpracovaná.
5. Úspěšný draft → doklad Koncept + extracted `applied` + zpráva 30→40; audit
   `source.kind='aiExtraction'`, `applied_by` = MCP uživatel.
6. Testy (služba, nástroj, regrese HTTP) procházejí.

## Doporučené pořadí implementace

1. Refactor: vytáhnout jádro do `ExtractedDocumentApplier` + `ExtractedApplyOutcome`,
   controller přepsat na slupku, **spustit existující testy** (regrese first).
2. `mail_draft_document` (enumerace čekajících + apply per doc + obálka) +
   wiring v `dispatchMcp`.
3. Testy nástroje + bezpečnostní asserce (safe / targetDocState=10).

## Rozhodnutí k designu (potvrzená)

1. ✓ **Varianta B** — nástroj zakládá Koncept, finalizace je vždy na člověku.
2. ✓ **`autoCreateMode='safe'` napevno** — AI linkuje jen existující master
   data, nikdy nezakládá nové; nevyřešitelná reference → nahlásit, nezaložit.
3. ✓ **`targetDocState=10` (Koncept)** — brzda přes stavový automat dokladu
   (10→20→40) v existujícím vieweru/`detail.actions`.
4. ✓ **Refactor do sdílené služby** — HTTP endpoint i MCP nástroj jedním kódem,
   chování endpointu beze změny (regrese kryta testy).
5. ✓ **Klíčování přes `message_id`** (+ volitelně `extracted_document_id`) —
   navazuje na `mail_list_pending`, který dává `ref` zprávy; agent nepotřebuje
   znát id jednotlivých extrahovaných dokladů předem.
6. ✓ **Závislost konstruktorem nástroje**, `McpInvocationContext` beze změny.

## Otevřené body (k ověření, neblokující)

- **Přesný tvar `_resolve.issues`** v `canonical` (po neúspěšném resolve) —
  ověřit při psaní, ať `unresolved[]` mapuje srozumitelné cesty + popisy.
- **`DocumentApplier::create(...)` signatura** — zkopírovat 1:1 z
  `dispatchExchange`/`dispatchAnalysis`.
- **`safetyGuardOk()`** rozhoduje, kdy i safe mód autovytvoří (např. položky) —
  zděděné chování, nástroj ho nemění; jen ověřit, že nevede k zakládání
  dodavatele bez vědomí člověka (pokud ano, zúžit override).
- **Read-tier `mail_preview_extracted` / `mail_get_message`** (náhled před
  draftem, výpis extrahovaných dokladů s id) — případný pozdější follow-up,
  pokud agentovi bude chybět granularita.
