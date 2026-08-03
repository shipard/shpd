# Task: Exchange Format — Fáze 2: Napojení AI analyzeru

**Stav:** hotovo

## Kontext

Pokračujeme z **Fáze 1** (`tasks/exchange-format-phase1.md` — hotovo).
Modul `core.exchange` vystavuje tři endpointy (`/validate`, `/preview`,
`/apply`) a má funkční `DocumentApplier`, který umí transformovat
canonical `shpd.docs.document.v1` na uložený doklad v `docs_core_heads`.

V této fázi napojujeme **AI analyzer** na exchange formát:

- AI vrátí přímo canonical strukturu (místo dnešního ad-hoc JSONu).
- Server validuje AI výstup proti canonical schématu; invalidní výstup
  se uloží jako `status=70 (ai_failed)`.
- Tlačítko **"Použít"** v UI detail panelu zprávy (existující
  `POST /_mail/extracted-documents/{ndx}/apply`) přestává jen měnit
  status — nyní volá `DocumentApplier::apply()` a skutečně vytvoří
  doklad v `docs_core_heads`. Lineage (`source_extracted_doc`,
  `target_row_ndx`) se propojí oboustranně.

Před implementací **přečti**:

- `docs/exchange-format.md` — kompletně, zejména sekce 5 (struktura
  canonical), 8 (resolve pravidla), 10 (apply pipeline), 12 (lineage).
- `modules/core/exchange/README.md` — aktuální stav Fáze 1 s
  curl příklady a omezeními.
- `modules/core/exchange/src/Document/DocumentApplier.php` — kompletně,
  hlavně `apply()`, `reconcile()`, `resolveOne()`, `writeLineage()`,
  konstantní mapy (DOC_TYPE_MAP, VAT_MODE_MAP, atd.).
- `modules/core/mail/docs/ai-analysis.md` — AI pull-based protokol,
  stavy zprávy a extracted documents.
- `modules/core/mail/docs/ai-prompts.md` — současný prompt a output
  schema.
- `modules/core/mail/profiles/default_czech_invoices.jsonc` — to
  budeme přepisovat.
- `src/Api/Controller/AnalysisController.php` — kompletně:
  - `result()` (~ř. 470) — sem patří validace AI canonical
  - `applyExtracted()` + `updateExtractedStatus()` (~ř. 835, 900) —
    zásadní rewrite

Vzorové soubory pro implementaci:

- `modules/core/exchange/src/Document/DocumentApplier.php` — applier
  je existující, jen upravíme `reconcile()` (autoCreateMode) a
  rozdělíme `writeLineage()`.
- `modules/core/exchange/src/Schema/SchemaValidator.php` — používá se
  v `AnalysisController::result` pro validaci AI výstupu.
- `modules/core/exchange/schemas/shpd.docs.document.v1.{json,jsonc}` —
  zdroj pravdy pro canonical schema; profile output_schema bude jeho
  inline kopie.

## Cíl Fáze 2

Po dokončení této fáze platí:

- `default_czech_invoices` profil v DB má `prompt_version = "v2.0.0"`,
  `prompt_template` instruuje AI k vrácení canonical
  `shpd.docs.document.v1`, `output_schema` je struktura
  `{overall_confidence, documents: [{doc_type, source_attachment_ndxs,
  confidence, fields: <canonical>}]}` — pole `fields` je inline kopie
  canonical schema.
- Při deploy: `bin/shpd-ds ai-profile-reload --force` načte v2.0.0 do
  DB. Bootstrap nového DS (`ds-upgrade` na čisté DB) vytvoří profil
  rovnou ve v2.0.0.
- `AnalysisController::result()` validuje každý
  `extracted_documents[].extracted_json` proti canonical schématu.
  Valid → uloží normálně, status podle confidence. Invalid → uloží
  s `status=70 (ai_failed)`, `extracted_json` zabalí do
  `{"_validationError": ..., "_validationIssues": [...], "_rawOutput": ...}`.
- `AnalysisController::applyExtracted()` nahrazuje pouhou změnu
  statusu plným voláním `DocumentApplier::apply()`. Před voláním
  doplní `source.extractedDoc = $extractedNdx` a
  `source.kind = "aiExtraction"` (pokud chybí). Po úspěchu:
  - applier vytvořil doklad v `docs_core_heads`
  - lineage propsána (oba směry: `docs_core_heads.source_extracted_doc`
    + `core_mail_extracted_documents.target_table_id/target_row_ndx`)
  - `extracted_document.status` přejde na 40 (applied) přes
    `ExtractedDocumentDocument` flow, který trigeruje auto-transition
    zprávy 30→40 přes `afterPersist`
- `DocumentApplier` podporuje `applyOptions.autoCreateMode` se třemi
  hodnotami:
  - `"strict"` (default) — `canCreate` bez `userAction` → 422
    `unresolved_required`
  - `"safe"` — autocreate jen pokud `createPayload` má dost
    identifikátorů (Party: `company_id`; Item: `name`; BankAccount:
    `iban` nebo `account_number`)
  - `"liberal"` — autocreate vždy
- `applyExtracted` posílá applieru `{autoCreateMode: "safe", targetDocState: 10}`.
- E2E test: AI analyzer (mock) pošle `POST /result` s canonical
  payloadem → extracted_document vznikne se statusem 20 (pending_review)
  → admin GET detail zprávy, klikne "Použít" → `POST /apply` →
  v DB existuje doc head + rows + vat_recap, partner buď napárován
  nebo vytvořen, extracted_document.status = 40, zpráva přešla na 40
  (pokud byla poslední pending).

## Návaznost

- Závisí na: Fáze 1 (`exchange-format-phase1.md` — hotovo), `core.mail`
  Fáze 3a (hotovo).
- **Fáze 2 je čistě backend.** Žádné frontend úpravy. Existující
  tlačítka "Použít" / "Zamítnout" v `IncomingMessagesViewer` tabu
  "Extrahované dokumenty" už volají správné endpointy — mění se jen
  chování serveru po stisku.
- Navazující **Fáze 3** = frontend náhled canonical dokumentu s
  `_resolve` interakcí (rozhodování o `userAction` pro `canCreate` /
  `ambiguous`). Tehdy `applyExtracted` může přepnout default na
  `autoCreateMode = "strict"` a vyžadovat explicit user input.

## Scope

### V rozsahu

#### Profile v2.0.0
- Přepis `modules/core/mail/profiles/default_czech_invoices.jsonc`:
  - `prompt_version` → `"v2.0.0"`
  - `prompt_template` → nová verze (viz "Implementace" sekce)
  - `output_schema` → nový wrapper s `fields` jako inline kopie
    canonical schema
  - `supported_doc_types` → zachovat (`["invoiceReceived", "creditNote", "other"]`)
  - `confidence_thresholds` → zachovat
- Aktualizace `modules/core/mail/docs/ai-prompts.md` — sekce "Default
  prompt" a "Output schema" pro v2.0.0.

#### `DocumentApplier` — autoCreateMode + lineage split
- Přidat parametr `applyOptions.autoCreateMode` do `apply()` (čteno
  z `$canonical['applyOptions']` nebo default `"strict"`).
- Upravit `reconcile()` / `resolveOne()` aby respektoval autoCreateMode
  podle pravidel popsaných v "Architektonická rozhodnutí".
- Rozdělit `writeLineage()` na:
  - `writeLineageTargets()` — uvnitř Apply transakce, nastaví
    `target_table_id`, `target_row_ndx`. **NEMĚNÍ** `status` ani
    `applied_at`.
  - Status update se přesouvá do `AnalysisController::applyExtracted`
    (důvod viz "Lineage targets vs status update split").
- Idempotency check v `apply()` — pokud canonical má
  `source.extractedDoc` a ten extracted_document už má
  `target_row_ndx != null`, vrátit ten existující doc id bez nového
  save (idempotent re-click).

#### `AnalysisController::result()`
- Před `INSERT` do `core_mail_extracted_documents`: validace
  `$doc['extracted_json']` proti canonical schématu přes
  `SchemaValidator::validate(..., 'shpd.docs.document', '1')`.
- Valid → uloží jako dnes, `status` podle `mapConfidenceToStatus`.
- Invalid → uloží s `status = 70 (ai_failed)`, `extracted_json`
  obalený do `{"_validationError": "<short msg>", "_validationIssues":
  [<schema issues>], "_rawOutput": <raw AI output>}` (utf-8 safe
  json_encode), `confidence` zachovat z AI výstupu (užitečné pro
  forensiku).
- Konstruktor `AnalysisController` rozšířit o `SchemaValidator`
  (nebo lazy factory přes `SchemaLoader::default()`).

#### `AnalysisController::applyExtracted()` — rewrite
- Načti `extracted_document` row.
- Validate state: musí být v `pendingStates` (10/20/30). Jinak 409.
- Pokud `status == 70 (ai_failed)` → odmítnout 422 `ai_output_invalid`
  s message "AI extrakce neproběhla úspěšně, použij reanalýzu".
- Parse `extracted_json` jako canonical. Pokud parse selže (corrupted
  data v DB) → 500.
- Doplnit do canonical (pokud chybí):
  - `source.extractedDoc = $extractedNdx`
  - `source.kind = "aiExtraction"` (pokud chybí; preference původní
    hodnotě z AI)
  - `applyOptions = {"autoCreateMode": "safe", "targetDocState": 10}`
    (přepiš existující applyOptions z canonical pro tento flow —
    důvěryhodný zdroj je server, ne AI/klient)
- Volej `DocumentApplier::apply($canonical)`.
- Pokud applier vrátí `success = false`:
  - Forward error response (code + message + details = enriched
    canonical) s HTTP status z applieru.
  - **Neměň** `extracted_document.status`.
- Pokud applier vrátí `success = true`:
  - Spusť existující `updateExtractedStatus($extractedNdx, $userId,
    STATUS_APPLIED, null)` flow — to vyvolá
    `ExtractedDocumentDocument::beforeSave` (nastaví `applied_at`,
    `applied_by`) a `afterPersist` (auto-transition zprávy 30→40
    pokud poslední pending).
  - Vrať `Response::success([savedDocId, canonical, extractedNdx,
    messageNdx])`.

#### Konstruktor injection
- `DocumentApplier::create()` factory neměnit (zůstává Fáze 1).
- `AnalysisController::__construct` rozšířit o:
  - `SchemaValidator $schemaValidator` (pro result validation)
  - `DocumentApplier $applier` (pro applyExtracted)
- Update `public/index.php` aby je do controlleru vstrkl.

#### Testy
- `tests/Unit/Module/Core/Exchange/Document/DocumentApplierTest.php`
  rozšířit:
  - autoCreateMode `strict` (existující chování — `canCreate` bez
    userAction = error)
  - autoCreateMode `safe` s plnými identifikátory → autocreate OK
  - autoCreateMode `safe` s chybějícím company_id na supplier →
    `unresolved_required`
  - autoCreateMode `safe` s chybějícím name na item → `unresolved_required`
  - autoCreateMode `liberal` autocreates always
  - Idempotency: druhé volání apply na canonical s
    `source.extractedDoc` mířícím na již-applied extracted_document
    vrátí stejný savedDocId, neuloží duplicit
- `tests/Unit/Api/Controller/AnalysisControllerResultTest.php`:
  - Valid canonical → uloží, status podle confidence
  - Invalid canonical (chybí required field) → uloží s status=70,
    `_validationError` + `_rawOutput` v JSON
  - Mixed: 2 documents v jednom result, 1 valid + 1 invalid → oba
    uloženy s adekvátními statusy
- `tests/Unit/Api/Controller/AnalysisControllerApplyExtractedTest.php`:
  - Pending state → invoke applier → success → status=40, lineage
    propsána
  - ai_failed state (70) → 422 `ai_output_invalid`
  - Already applied state (40) → 409
  - Applier returns `unresolved_required` (např. canCreate item bez
    name) → 422 forwarded, status zůstává na pendingu
  - Applier returns `validation_failed` (např. chybí issue_date) →
    422 forwarded
  - Last pending document → message auto-transition 30→40 verified
- `tests/Integration/Exchange/AiExtractedDocumentApplyTest.php` —
  end-to-end:
  - Mock AI result (vlož extracted_document s canonical v
    extracted_json) → POST applyExtracted → assert doc_head row,
    rows, vat_recap, partner, lineage oboustranně
- `tests/Unit/Module/Core/Mail/AIAnalyzerProvisionerTest.php`:
  - Provisioner na čisté DS vytvoří profile s `prompt_version =
    v2.0.0`
  - Existující profile se nepřepíše (skipped_reason)
- Nový test souborový `tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php`:
  - Načte canonical schema z `modules/core/exchange/schemas/shpd.docs.document.v1.json`
  - Načte `default_czech_invoices.jsonc` profile
  - Verifikuje `output_schema.properties.documents.items.properties.fields
    === <canonical schema>`
  - Test selže, pokud někdo updatuje canonical schema a zapomene
    zaktualizovat profile

### Mimo rozsah

- **Frontend úpravy** — zero. Tlačítka "Použít" / "Zamítnout" zůstávají
  jak jsou, chování backendu se mění transparentně.
- **Auto-apply pro `ready_to_apply` (status 10) bez kliku uživatele** —
  vyžadovalo by per-DS nebo per-profile setting + background worker.
  Samostatná iterace po Fázi 3.
- **Update vnějšího repozitáře `ai_analyzer`** — žádné změny kódu
  analyzeru nejsou potřeba. Analyzer si při `/claim` pulluje
  `prompt_template` + `output_schema` z DB; po reload profilu v2.0.0
  dostane novou instrukci automaticky. Mechanické mapování
  `AI.documents[].fields` → API `extracted_documents[].extracted_json`
  v analyzeru zůstává beze změny (jen "fields" má nový shape).
- **Frontend Fáze 3** — vizualizace canonical, `_resolve` UI pro
  `canCreate` / `ambiguous` rozhodování.
- **`autoCreateMode = "liberal"` v reálném použití** — implementuje se
  pro úplnost, ale v aplikaci se default nikdy nepoužije (jen jako
  testovací cesta nebo budoucí B2B import).
- **Validace lineage směru `docs_core_heads → core_mail_extracted_documents`** —
  applier dnes plní `docs_core_heads.source_extracted_doc` z
  `canonical.source.extractedDoc`. Žádná SQL FK kontrola — pokud
  extracted_document neexistuje, prostě se neudělá lineage update v
  opačném směru. To je OK (Fáze 1 design).

## Architektonická rozhodnutí

### autoCreateMode

Tři režimy řízené přes `applyOptions.autoCreateMode`:

| Mode | Chování pro `canCreate` bez `userAction` |
|------|-------------------------------------------|
| `strict` (default) | Error `unresolved_required` |
| `safe` | Autocreate pokud `createPayload` splňuje per-tabulka guard |
| `liberal` | Autocreate vždy |

**Per-tabulka safety guard pro `safe`:**

| Reference | Required v `createPayload` |
|-----------|---------------------------|
| Party (`base_persons_persons`) | `company_id` non-empty |
| Item (`economy_items`) | `name` non-empty |
| BankAccount (`base_persons_bank_accounts`) | `iban` OR `account_number` non-empty |

Implementace v `DocumentApplier::resolveOne()`:

```php
if ($userAction === null) {
    if ($status === 'matched') {
        return $fresh['matchedId'] ?? null;
    }

    if ($status === 'canCreate') {
        $mode = $this->applyOptionsCache['autoCreateMode'] ?? 'strict';
        if ($mode === 'liberal'
            || ($mode === 'safe' && $this->safetyGuardOk($existsTable, $fresh))
        ) {
            // Equivalent to userAction === 'create' — schedule side-create.
            return null; // null = no resolved id yet; caller adds to plan.partyCreates / etc.
        }
        // Fall through to unresolved_required.
    }

    if (in_array($status, ['canCreate', 'ambiguous', 'notFound'], true)) {
        $plan['errorCode'] = 'unresolved_required';
        $plan['errorMessage'] = "Reference „{$path}" vyžaduje rozhodnutí (userAction).";
        $issues[] = [/* ... */];
    }
    return null;
}
```

Caller v `reconcile()` musí umět rozlišit "userAction == null + safe
autocreate" od "userAction == null + plain matched". Doporučená
strategie: extrahovat z `resolveOne()` pomocný flag `$shouldSideCreate`
(nebo druhý return), který indikuje "naplánuj create i bez explicit
userAction". Implementační detail.

`safetyGuardOk(string $existsTable, array $fresh): bool` je čistá
function — vrátí true/false podle existence required polí v
`$fresh['createPayload']`.

### Invalid AI canonical → status 70

AI může vrátit malformed JSON nebo JSON nesplňující schema (e.g.
chybí `format`, neplatný `docType`). Server **NEodmítá** result —
uloží jako `ai_failed`. Důvody:

1. AI retry to nezachrání (smyčka, plýtvání tokeny).
2. UI má smysluplnou prezentaci: "AI selhala při extrakci" — uživatel
   vidí raw output, může pořídit ručně nebo zkusit reanalyze s jiným
   profilem/modelem.
3. Audit: failed attempts jsou dohledatelné v `extracted_documents`.

**Wrapper struktura** pro `extracted_json` při invalid:

```json
{
  "_validationError": "Canonical schema validation failed",
  "_validationIssues": [
    {"severity": "error", "path": "format", "code": "required", "message": "..."},
    {"severity": "error", "path": "supplier.country", "code": "minLength", "message": "..."}
  ],
  "_rawOutput": { /* whatever AI returned */ }
}
```

Pole s prefixem `_` jasně signalizují "meta wrapper, ne canonical
content". UI Fáze 3 to detekuje a renderuje speciálně.

### Profile `output_schema` je inline kopie canonical schema

Analyzer dostává `output_schema` v `/claim` response jako kompletní
JSON Schema. Nepodporuje `$ref` resolve napříč soubory. Takže:

- `default_czech_invoices.jsonc` má `output_schema` jako kompletní
  inline strukturu s wrapperem `{overall_confidence, documents: [...]}`,
  kde `fields` je **inline kopie obsahu**
  `modules/core/exchange/schemas/shpd.docs.document.v1.json`.

**Drift hlídá test `ProfileSchemaDriftTest`** — porovnává
`output_schema.properties.documents.items.properties.fields` s
canonical schema souborem. Když někdo updatuje canonical a zapomene
zaktualizovat profile, test selže s jasnou hláškou.

V budoucnu (Fáze 3 nebo později) lze přidat CLI příkaz
`shpd-ds ai-profile-sync-schema`, který drift opraví automaticky.
V této fázi to nepotřebujeme.

### Lineage targets vs status update split

`DocumentApplier::writeLineage()` (Fáze 1) měnil `target_table_id`,
`target_row_ndx`, `status`, `applied_at` jedním SQL UPDATE. Problém:
obejde `ExtractedDocumentDocument` Document hook, takže
auto-transition zprávy 30→40 (v `afterPersist`) **nepoběží**.

**Rozdělení odpovědnosti:**

- `DocumentApplier::writeLineageTargets()` (uvnitř Apply transakce):
  jen `target_table_id`, `target_row_ndx`. Stačí přímý SQL UPDATE.
  Tyto jsou "kam doklad šel".
- `AnalysisController::applyExtracted()` po úspěšném apply: zavolá
  existující `updateExtractedStatus($extractedNdx, $userId,
  STATUS_APPLIED, null)`, který má svoji DB transakci a běží přes
  `ExtractedDocumentDocument::beforeSave` (nastaví `applied_at`,
  `applied_by`) + `afterPersist` (auto-transition zprávy).

**Trade-off**: dvě oddělené transakce — applier uloží doklad +
lineage targets, controller pak status update v separátní transakci.
Pokud po commit applieru padne status update, doklad je v DB
(správně), extracted_document zůstal pending. Uživatel klikne znovu
→ idempotency check v applieru rozpozná, že target_row_ndx je
vyplněn, vrátí ten existující savedDocId, status update doběhne.

Idempotency: viz "Idempotency apply" níže.

### doc_type mapping

`AIBackend` (= AI vendor) dostává v profile prompt instrukci, aby
vrátil `canonical.docType` jako jeden ze:

- `"invoiceReceived"` (přijatá faktura)
- `"invoiceIssued"` (vystavená — pro vydávané faktury z faktur. systémů)
- `"creditNote"` (dobropis)
- `"other"` (cokoli jiného — reklamy, newslettery atd.)

Z toho `invoiceReceived` a `invoiceIssued` projdou applierem
(`DOC_TYPE_MAP` v applieru: `invoiceReceived → invni`,
`invoiceIssued → invno`). Pro `creditNote` zatím applier mapping
nemá — applier vrátí `other_doc_type` warning a uloží extracted_document
ale **applier ho neuloží jako doklad** (pokus o apply by selhal,
applier vrátí 422 `unsupported_doc_type`). To je očekávané chování
pro Fázi 2 — credit notes přijdou v navazujícím tasku.

Pro `other` (= nedoklad, např. reklamní e-mail) by AI mělo vrátit
`canonical.documents = []` (prázdné pole na top-level AI response),
takže žádný extracted_document nevznikne. Pokud AI omylem vrátí
`other` v canonical, applier ho odmítne — UI prezentuje "AI
nesprávně klasifikovala, ignoruj nebo zamítni".

### Idempotency apply

Když uživatel klikne "Použít" dvakrát po sobě (race, network retry,
F5 v browseru):

1. Při prvním kliku applier:
   - apply succeeds → savedDocId vrácen
   - extracted_document.target_row_ndx = savedDocId, status=40
2. Při druhém kliku applier:
   - canonical má `source.extractedDoc = $extractedNdx`
   - applier čte `core_mail_extracted_documents.target_row_ndx` pro
     ten extractedNdx; vrátí existing savedDocId bez nového save
   - controller status update: idempotent (status už 40)

**Implementace check v applieru** (na začátku `apply()` před schema
validation):

```php
$extractedNdx = $canonical['source']['extractedDoc'] ?? null;
if (is_int($extractedNdx) && $extractedNdx > 0) {
    $row = $this->db->fetch(
        'SELECT [target_row_ndx], [status]
         FROM [core_mail_extracted_documents]
         WHERE [id] = %i',
        $extractedNdx,
    );
    if ($row !== null
        && !empty($row['target_row_ndx'])
        && (int) $row['status'] === 40
    ) {
        // Already applied — return enriched canonical with savedDocId.
        $existingId = (int) $row['target_row_ndx'];
        // Build minimal enriched canonical; resolve není potřeba.
        $enriched = $canonical;
        $enriched['savedDocId'] = $existingId;
        $enriched['_resolve'] = [
            'summary' => ['status' => 'alreadyApplied', /* ... */],
        ];
        return ApplyResult::ok($enriched, $existingId);
    }
}
```

Cost: jeden lookup row navíc per apply, jen pokud canonical má
extractedDoc. Acceptable.

## Implementace

### Profile `default_czech_invoices.jsonc` v2.0.0

**Nový `prompt_template`:**

```text
Jsi asistent pro zpracování došlé pošty českých firem. Tvým úkolem je
analyzovat přílohy e-mailové zprávy a extrahovat z nich strukturovaná
data o přijatých dokladech (faktury, dobropisy).

KONTEXT ZPRÁVY:
- Předmět: {{ message.subject }}
- Odesílatel: {{ message.sender_email }}{% if message.sender_name %} ({{ message.sender_name }}){% endif %}
- Tělo zprávy: {{ message.body_plain }}

PŘÍLOHY ({{ attachments|length }}):
{% for att in attachments %}- #{{ att.ndx }}: {{ att.filename }} ({{ att.mime_type }}, {{ att.size_human }})
{% endfor %}

ÚKOL:
Pro každou přílohu rozhodni, zda jde o přijatou fakturu (invoiceReceived),
dobropis (creditNote) nebo jiný dokument (other). Z faktury/dobropisu
extrahuj všechna pole, která jsi schopen identifikovat, do kanonické
struktury "shpd.docs.document.v1".

PRAVIDLA:
- Pole, která nelze určit, VYNECHEJ (neuhaduj, neimprovizuj). Lepší
  chybějící pole než špatná hodnota.
- Identifikátory (IČO, DIČ, VAT ID, IBAN, EAN, SKU) přebírej beze změny,
  pokud je vidíš na dokladu.
- Datumy formátuj jako ISO 8601 (YYYY-MM-DD).
- Měny jako ISO 4217 uppercase ("CZK", "EUR").
- Země jako ISO 3166-1 alpha-2 lowercase ("cz", "sk", "de").
- selfParty je vždy "customer" (jsme příjemce dokladu).
- source.kind je vždy "aiExtraction".
- source.confidence je tvoje jistota kvalitou extrakce 0.0–1.0.
- source.promptVersion je "v2.0.0".
- Pokud žádná příloha není dokladem, vrať prázdné pole documents.

VRAŤ POUZE JSON v této struktuře (žádný úvodní text, žádné markdown
bloky):

{
  "overall_confidence": 0.92,
  "documents": [
    {
      "doc_type": "invoiceReceived",
      "source_attachment_ndxs": [123],
      "confidence": 0.95,
      "fields": {
        "format": "shpd.docs.document",
        "formatVersion": "1.0",
        "source": {
          "kind": "aiExtraction",
          "extractedAt": "<ISO timestamp from current time>",
          "confidence": 0.95,
          "promptVersion": "v2.0.0"
        },
        "docType": "invoiceReceived",
        "docNumber": "<supplier's invoice number>",
        "docText": "<short description>",
        "selfParty": "customer",
        "supplier": {
          "name": "Dodavatel s.r.o.",
          "country": "cz",
          "companyId": "12345678",
          "taxId": "CZ12345678",
          "vatId": "CZ12345678",
          "address": {
            "street": "Hlavní", "houseNumber": "1",
            "city": "Praha", "zip": "11000", "country": "cz"
          },
          "contact": {"email": "...", "phone": "..."},
          "bankAccount": {
            "accountNumber": "1234567890/0100",
            "iban": "CZ65...", "bic": "...",
            "currency": "CZK"
          }
        },
        "customer": null,
        "dates": {
          "issueDate": "2026-04-15",
          "dueDate": "2026-04-29",
          "accountingDate": "2026-04-15",
          "taxPointDate": "2026-04-15",
          "vatObligationDate": "2026-04-15"
        },
        "currency": "CZK",
        "exchangeRate": null,
        "vat": {
          "mode": "fromBase",
          "place": "domestic",
          "registrationCountry": "cz"
        },
        "payment": {
          "method": "bankTransfer",
          "variableSymbol": "...",
          "specificSymbol": null,
          "constantSymbol": null
        },
        "notes": {
          "internal": null,
          "onDocument": "..."
        },
        "rows": [
          {
            "rowKind": "item",
            "orderPos": 1,
            "item": {
              "supplierCode": "KONZ-001",
              "name": "Konzultace",
              "description": "Hodinová sazba"
            },
            "unit": "h",
            "quantity": 10,
            "unitPrice": 1033.06,
            "totalPrice": 10330.58,
            "priceCalcMode": "fromUnitPrice",
            "discountPct": null,
            "discountAmount": null,
            "vat": {"code": "cz-110", "pct": 21}
          }
        ],
        "vatRecap": [
          {
            "vatCode": "cz-110", "vatPct": 21,
            "base": 10330.58, "tax": 2169.42, "total": 12500.00,
            "isReversePair": false
          }
        ],
        "totals": {
          "totalBase": 10330.58,
          "totalVat": 2169.42,
          "totalAmount": 12500.00,
          "totalRounding": 0.00
        },
        "attachments": [
          {
            "filename": "faktura-001.pdf",
            "mimeType": "application/pdf",
            "kind": "original"
          }
        ]
      }
    }
  ]
}

Top-level pole "overall_confidence" a "documents" JSOU VŽDY POVINNÁ, i
když je documents prázdný seznam. Pokud žádný doklad neexistuje:

{"overall_confidence": 1.0, "documents": []}

Nyní analyzuj přiložené dokumenty a vrať JSON.
```

**Pozn**: prompt nesmí být překládán automaticky — text musí zůstat
identický s tím, co je v JSONC, jen escapovaný do JSON stringu.
Claude Code: pozor na `\n` escaping přes celý prompt — to už dnes
funguje v JSONC, jen prodluž obsah.

**Nový `output_schema`:**

```jsonc
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["overall_confidence", "documents"],
  "additionalProperties": false,
  "properties": {
    "overall_confidence": { "type": "number", "minimum": 0, "maximum": 1 },
    "documents": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["doc_type", "source_attachment_ndxs", "confidence", "fields"],
        "additionalProperties": false,
        "properties": {
          "doc_type": {
            "type": "string",
            "enum": ["invoiceReceived", "invoiceIssued", "creditNote", "other"]
          },
          "source_attachment_ndxs": {
            "type": "array",
            "items": { "type": "integer", "minimum": 0 }
          },
          "confidence": { "type": "number", "minimum": 0, "maximum": 1 },
          "fields": {
            // INLINE COPY of modules/core/exchange/schemas/shpd.docs.document.v1.json
            // ProfileSchemaDriftTest verifies these stay in sync.
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "type": "object",
            // ... full canonical schema inlined here ...
          }
        }
      }
    }
  }
}
```

**Jak fields inline strukturovat**: Otevři
`modules/core/exchange/schemas/shpd.docs.document.v1.json`, vezmi celý
obsah, vlož jako hodnotu pole `fields` v output_schema. Drift test
porovná oba JSON objekty s `assertEquals`.

### `AIAnalyzerProvisioner` — žádné změny v kódu

Provisioner už dnes čte profile JSONC přes `JsoncParser::parseFile` a
ukládá do DB. Po update profile JSONC stačí volat:

```bash
bin/shpd-ds ai-profile-reload --force
```

(Existující CLI příkaz, viz `tasks/ai-profile-reload.md`.) Při
`--force` přepíše i když verze v DB je stejná nebo vyšší.

Pro nová DS (čistá databáze) provisioner při `ds-upgrade` načte rovnou
v2.0.0.

### `DocumentApplier` — autoCreateMode a lineage split

Implementační kroky:

1. **Přidat field** `private array $applyOptionsCache = []` do
   `DocumentApplier`. Naplnit ho na začátku `apply()`:

```php
public function apply(array $canonical): ApplyResult
{
    $this->applyOptionsCache = is_array($canonical['applyOptions'] ?? null)
        ? $canonical['applyOptions']
        : [];

    // Idempotency check (viz "Idempotency apply" výše)
    // ...

    // Schema + validator + resolve + reconcile + transaction (existující flow)
    // ...
}
```

2. **Upravit `resolveOne()`** podle pseudo-kódu výše (sekce
   "autoCreateMode"). Přidat parametr `?bool &$shouldSideCreate = null`
   (nebo druhý return) pro signalizaci "auto-create v safe modu".

3. **Upravit `reconcile()`** aby použil `shouldSideCreate` flag a
   naplnil `plan['partyCreates']` / `bankCreate` / `rowItemCreates`
   bez ohledu na `userAction`, když je flag `true`.

4. **Přidat metodu** `safetyGuardOk(string $existsTable, array $fresh):
   bool`:

```php
private function safetyGuardOk(string $existsTable, array $fresh): bool
{
    $payload = $fresh['createPayload'] ?? [];
    if (!is_array($payload)) return false;

    return match ($existsTable) {
        'base_persons_persons' => !empty($payload['company_id']),
        'economy_items' => !empty($payload['name']),
        'base_persons_bank_accounts' =>
            !empty($payload['iban']) || !empty($payload['account_number']),
        default => false,
    };
}
```

5. **Rozdělit `writeLineage`** na `writeLineageTargets`:

```php
private function writeLineageTargets(array $canonical, int $savedDocId): void
{
    $extractedDoc = $canonical['source']['extractedDoc'] ?? null;
    if (!is_int($extractedDoc) || $extractedDoc <= 0) {
        return;
    }
    $this->db->query(
        'UPDATE [core_mail_extracted_documents]
         SET [target_table_id] = %s,
             [target_row_ndx] = %i
         WHERE [id] = %i',
        'docs_core_heads', $savedDocId, $extractedDoc,
    );
}
```

Volání v `apply()` zůstává ve stejném místě (krok 10 v pipeline
dokumentace), jen metoda se přejmenovala.

6. **Idempotency check** (sekce "Idempotency apply"). Přidat na začátek
   `apply()` před schema validation.

### `AnalysisController::result()` — validace canonical

Pseudo-kód úprav v `foreach ($extractedDocsInput as $doc)`:

```php
$extractedJson = is_array($doc['extracted_json'] ?? null)
    ? $doc['extracted_json']
    : null;

$schemaIssues = [];
if ($extractedJson !== null) {
    $schemaIssues = $this->schemaValidator->validate(
        $extractedJson,
        \Shipard\Module\Core\Exchange\Document\DocumentApplier::FORMAT_ID,
        \Shipard\Module\Core\Exchange\Document\DocumentApplier::FORMAT_VERSION,
    );
}

$isValid = $extractedJson !== null && $schemaIssues === [];

if ($isValid) {
    $status = $this->mapConfidenceToStatus($confidence, $thresholds);
    $jsonForDb = (string) json_encode(
        $extractedJson,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
} else {
    $status = ExtractedDocumentDocument::STATUS_AI_FAILED;
    $wrapped = [
        '_validationError' => 'Canonical schema validation failed',
        '_validationIssues' => $schemaIssues,
        '_rawOutput' => $extractedJson,
    ];
    $jsonForDb = (string) json_encode(
        $wrapped,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}

$dibi->insert(self::EXTRACTED_TABLE, [
    // ...
    'extracted_json' => $jsonForDb,
    'status' => $status,
    // ...
]);
```

Konstruktor `AnalysisController` rozšířit o `SchemaValidator` parametr.
V `public/index.php` instancuj:

```php
$schemaValidator = new \Shipard\Module\Core\Exchange\Schema\SchemaValidator(
    \Shipard\Module\Core\Exchange\Schema\SchemaLoader::default()
);
```

### `AnalysisController::applyExtracted()` — rewrite

Plný pseudo-kód nového flow:

```php
public function applyExtracted(AuthContext $auth, Request $request, int $extractedNdx): Response
{
    if (!$auth->isAuthenticated) {
        return Response::error('UNAUTHORIZED', 'Authentication required', 401);
    }

    $existing = $this->db->fetchRow(
        'SELECT * FROM %n WHERE id = %i',
        self::EXTRACTED_TABLE, $extractedNdx,
    );
    if ($existing === null) {
        return Response::error('NOT_FOUND', "Extracted document {$extractedNdx} not found", 404);
    }

    $currentStatus = (int) $existing['status'];
    if ($currentStatus === ExtractedDocumentDocument::STATUS_AI_FAILED) {
        return Response::error(
            'AI_OUTPUT_INVALID',
            'AI extrakce neproběhla úspěšně, použij reanalýzu.',
            422,
        );
    }

    $pendingStates = [
        ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
        ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
        ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
    ];
    if (!in_array($currentStatus, $pendingStates, true)) {
        return Response::error(
            'INVALID_STATE',
            'Document is not in a pending state (10/20/30)',
            409,
        );
    }

    $canonical = json_decode((string) $existing['extracted_json'], true);
    if (!is_array($canonical)) {
        return Response::error('CORRUPTED_DATA', 'extracted_json cannot be parsed', 500);
    }

    // Server-controlled injection (overwrites any client-supplied values)
    $canonical['source'] = is_array($canonical['source'] ?? null) ? $canonical['source'] : [];
    $canonical['source']['extractedDoc'] = $extractedNdx;
    if (empty($canonical['source']['kind'])) {
        $canonical['source']['kind'] = 'aiExtraction';
    }
    $canonical['applyOptions'] = [
        'autoCreateMode' => 'safe',
        'targetDocState' => 10,
    ];

    $result = $this->applier->apply($canonical);
    if (!$result->success) {
        return Response::error(
            $result->errorCode ?? 'INTERNAL_ERROR',
            $result->errorMessage ?? 'Apply failed',
            $result->statusCode ?? 422,
            ['canonical' => $result->canonical],
        );
    }

    // Apply succeeded — now mark extracted_document as applied via the
    // Document flow (triggers message 30→40 auto-transition).
    $statusUpdate = $this->updateExtractedStatus(
        $extractedNdx,
        $auth->userId,
        ExtractedDocumentDocument::STATUS_APPLIED,
        null,
    );
    // updateExtractedStatus may return error (e.g. concurrent state change);
    // forward it as a warning but report apply success — the doc IS in DB.
    if ($statusUpdate->getStatusCode() >= 400) {
        ErrorLogger::warn('applyExtracted: status update failed after successful apply', [
            'extractedNdx' => $extractedNdx,
            'savedDocId' => $result->savedDocId,
            'statusResponse' => $statusUpdate->getStatusCode(),
        ]);
    }

    return Response::success([
        'savedDocId' => $result->savedDocId,
        'extractedNdx' => $extractedNdx,
        'messageNdx' => (int) $existing['message'],
        'canonical' => $result->canonical,
    ]);
}
```

Konstruktor rozšířit o `DocumentApplier $applier`. V `public/index.php`:

```php
$tablesArray = $tableLoader->load();
$registry = $documentLoader->load($dsConfig, $modulesBasePath);
$applier = \Shipard\Module\Core\Exchange\Document\DocumentApplier::create(
    $db->getDibiConnection(), $configRuntime, $dsConfig, $registry, $tablesArray,
);

$analysisController = new \Shipard\Api\Controller\AnalysisController(
    $db, $dsConfig, $dsPath, $tablesArray, $registry, $schemaValidator, $applier,
);
```

### Dokumentace

#### `modules/core/mail/docs/ai-prompts.md` — update sekce

- "Default prompt" — nahradit kompletním novým promptem (z výše).
- "Output schema" — popsat nový wrapper `{overall_confidence, documents:
  [{doc_type, source_attachment_ndxs, confidence, fields: <canonical>}]}`,
  odkázat na `docs/exchange-format.md` pro `fields` strukturu, zmínit
  drift test `ProfileSchemaDriftTest`.
- Sekce "Customization guidelines" → "Přidání nového typu dokumentu" —
  aktualizovat krok 4: "V `output_schema.documents[].doc_type` enum
  přidej nový klíč. Pole `fields` zůstává jednotné napříč typy —
  canonical formát je polymorfní podle `fields.docType`."

#### `modules/core/exchange/README.md` — update sekce "Stav"

Přidat:

```markdown
## Stav (Fáze 2)

- ✅ AI analyzer napojen — produkuje canonical `shpd.docs.document.v1`
  v poli `extracted_documents[].extracted_json`
- ✅ `AnalysisController::result` validuje proti canonical schema;
  invalid → `status=70 (ai_failed)`
- ✅ `applyExtracted` (UI "Použít") nyní volá `DocumentApplier::apply`
  místo pouhé status změny
- ✅ `applyOptions.autoCreateMode = "safe"` — autocreate pro reference
  s dostatečnými identifikátory (Party companyId, Item name,
  BankAccount iban/accountNumber)
- ✅ Idempotency: opakované apply na již-applied extracted_document
  vrátí existující doc id
```

#### `docs/exchange-format.md` — update sekce 11

V sekci 11 "REST API endpointy" v `/apply` body description přidat
popis `applyOptions.autoCreateMode`:

```markdown
"applyOptions": {
  "targetDocState": 10,        // 10 (Koncept) | 20 (Potvrzeno)
  "autoCreateMode": "strict",  // strict (default) | safe | liberal
  "rejectOnIssues": ["error"]
}
```

s popisem tří režimů (kopie tabulky z této specifikace).

## Hotovo když

- [ ] `bin/shpd-ds ds-upgrade` projde bez chyb (žádné DB schema změny,
      jen reload profilu).
- [ ] `bin/shpd-ds ai-profile-reload --force` načte v2.0.0 profile do DB,
      verifikuje se přes `SELECT prompt_version FROM core_mail_ai_profiles
      WHERE profile_id = 'czech_invoices'` → `v2.0.0`.
- [ ] Bootstrap nového DS (čistá DB + `ds-upgrade`) vytvoří profile
      rovnou ve v2.0.0.
- [ ] `ProfileSchemaDriftTest` projde — `output_schema.documents.items.fields`
      v profile JSONC se shoduje s `shpd.docs.document.v1.json`.
- [ ] Mock AI result test: `POST /api/v1/_mail/analysis/{ndx}/result`
      s valid canonical v `extracted_documents[].extracted_json` vytvoří
      row s `status` podle confidence, `extracted_json` v DB obsahuje
      canonical beze změny (žádný wrapper).
- [ ] Mock AI result test: stejný call s invalid canonical (chybí
      `format` field) vytvoří row s `status = 70 (ai_failed)`,
      `extracted_json` v DB obsahuje wrapper `{"_validationError": ...,
      "_validationIssues": [...], "_rawOutput": ...}`.
- [ ] `POST /_mail/extracted-documents/{ndx}/apply` na pending document
      s plně-resolved canonical (vše matched, žádný canCreate) vrátí
      200 se `savedDocId`, v DB existuje `docs_core_heads` row,
      `extracted_document.status = 40`, `target_row_ndx = savedDocId`.
- [ ] `POST /apply` na extracted document s canonical, kde supplier má
      `companyId` (canCreate s identifikátorem) → applier vytvoří
      novou osobu, vrátí 200, lineage propsána.
- [ ] `POST /apply` na extracted document s canonical, kde supplier
      nemá `companyId` (canCreate bez identifikátoru) → 422
      `unresolved_required`, status extracted_document zůstává na
      pendingu.
- [ ] `POST /apply` na extracted document s status=70 (ai_failed)
      → 422 `AI_OUTPUT_INVALID`.
- [ ] `POST /apply` na extracted document s status=40 (already applied)
      → 409 `INVALID_STATE`.
- [ ] **Idempotency:** dvě `POST /apply` rychle za sebou na stejný
      extractedNdx → první vrátí `savedDocId = X`, druhé vrátí stejné
      `savedDocId = X` bez nového záznamu v `docs_core_heads`.
- [ ] **Auto-transition zprávy:** zpráva má 2 pending extracted_documents,
      uživatel apply oba → po druhém apply zpráva přejde z docState=30
      (Analyzovaná) na 40 (Zpracovaná). Ověřit v DB.
- [ ] **Bidirectional lineage:** po apply mít:
      - `docs_core_heads.source_extracted_doc = extractedNdx`,
        `source_kind = 'aiExtraction'`, `source_extracted_at != null`
      - `core_mail_extracted_documents.target_table_id = 'docs_core_heads'`,
        `target_row_ndx = savedDocId`, `status = 40`, `applied_at != null`,
        `applied_by = userId`
- [ ] `autoCreateMode = "strict"` chování zachováno pro přímé volání
      `/api/v1/_exchange/docs/document/apply` (default; bez
      applyOptions.autoCreateMode → strict).
- [ ] `autoCreateMode = "liberal"` test prochází (autocreate Party
      bez companyId, Item bez name) — funkční pro budoucí použití.
- [ ] PHPUnit testy procházejí (`vendor/bin/phpunit`).
- [ ] `modules/core/mail/docs/ai-prompts.md` aktualizováno s v2.0.0
      promptem.
- [ ] `modules/core/exchange/README.md` má sekci "Stav (Fáze 2)".
- [ ] `docs/exchange-format.md` sekce 11 popisuje `autoCreateMode`.

## Konvence

- **Jazyk**: UI texty čeština, kód + komentáře angličtina.
- **PHP 8.5** strict_types, readonly properties kde možné.
- **Žádné nové tabulky** v této fázi. Existující schema z Fáze 1
  zůstává.
- **Žádné frontend úpravy.**
- **Backward compat na `/api/v1/_exchange/docs/document/apply`** —
  default autoCreateMode = strict. Existující testy z Fáze 1
  procházejí beze změny.
- **Idempotency je first-class concern** — applier vrací stejný
  `savedDocId` při opakovaném volání. Důležité pro robustnost UI
  (network retries, F5 v browseru).

## Doporučené pořadí implementace

1. **autoCreateMode v DocumentApplier** — rozšířit `apply()` o
   `applyOptionsCache`, upravit `resolveOne()` a `reconcile()`, přidat
   `safetyGuardOk()`. PHPUnit testy: strict (existující), safe (3
   varianty), liberal.
2. **writeLineageTargets split** — rozdělit existující `writeLineage`,
   PHPUnit ověří, že `status` a `applied_at` se v applieru už neměnení.
3. **Idempotency check v apply()** — přidat pre-check pro
   `source.extractedDoc` → existing target_row_ndx. PHPUnit: druhé
   apply vrátí stejný id.
4. **SchemaValidator do AnalysisController** — konstruktor + DI v
   `public/index.php`. Smoke test že existing `/result` endpoint dál
   funguje.
5. **AnalysisController::result validation** — implementovat validate
   + wrapper. PHPUnit: valid, invalid, mixed.
6. **AnalysisController::applyExtracted rewrite** — implementovat
   nový flow s DocumentApplier delegací. PHPUnit + Integration test
   end-to-end.
7. **Profile JSONC v2.0.0** — přepsat prompt_template + output_schema
   (inline copy canonical schema), bump prompt_version. Spustit
   `ai-profile-reload --force` v dev DS, ověřit v DB.
8. **ProfileSchemaDriftTest** — verifikace inline copy souhlasí s
   canonical schema souborem.
9. **Dokumentace** — update tří MD souborů.
10. **E2E sanity check** — odeslat reálnou AI fakturu (mock) přes
    `/result`, kliknout "Použít" v UI, ověřit doc v DB.

## Otevřené body

- **Status mapping `target_table_id`** — applier v Fázi 1 ukládá
  `'docs_core_heads'`. Pokud v budoucnu (Fáze 3+) přidáme apply pro
  `creditNote` nebo jiné `doc_type`, target_table_id zůstane stejný
  (všechno žije v `docs_core_heads`). Aktuálně to není rizikové.

- **Validace `docType` v invalid canonical** — jeden edge case: AI
  vrátí canonical s `docType = "unknownThing"` (neznámý enum). Schema
  enum validace ho odmítne → status=70. Test by tohle měl pokrýt.

- **Reanalyze a invalidní výsledek** — uživatel klikne "Znova
  analyzovat" na zprávu s ai_failed extracted documenty. Existující
  flow (`reanalyze` endpoint) označí superseded → 60 pro statusy
  10/20/30, ale **co s 70?** Fáze 1 řešila jen 10/20/30. Doporučení:
  rozšířit `reanalyze` o `STATUS_AI_FAILED (70)` ve výčtu superseded.
  Pokud je to v rozsahu, zvládni tady. Pokud najdeš jiné komplikace,
  označ jako "Fáze 2.1" follow-up.

- **`applyExtracted` s status update v separátní transakci** —
  poznámka v "Lineage targets vs status update split" připouští
  benign race condition (apply succeeded, status update padl, user
  retry idempotent). Pokud při implementaci najdeš důvod ke spojení
  do jedné transakce (např. `updateExtractedStatus` lze trivialně
  faktorovat tak, aby nepoužíval vlastní `begin/commit`), udělej to.
  Naopak pokud zachování dvou transakcí je čistší (zachovává
  `updateExtractedStatus` API beze změny pro `rejectExtracted`),
  zachovej trade-off s komentářem `// See exchange-format-phase2.md
  "Lineage targets vs status update split"`.

- **`source.extractedAt` v applieru** — applier ukládá
  `source_extracted_at` jako `mapExtractedAt($canonical['source']['extractedAt'])`.
  AI prompt instruuje vrátit ISO timestamp. Pokud AI omylem vrátí
  něco neparsovatelného, `mapExtractedAt` vrátí null → DB NULL. To je
  acceptable; není potřeba aktivně řešit.
