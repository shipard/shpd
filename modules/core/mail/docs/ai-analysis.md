# Fáze 3a: AI analýza došlých zpráv

Tento dokument popisuje architekturu, datový tok a životní cyklus AI analýzy
v modulu `core.mail`. Spec: [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md).

## Cíl

Z došlé zprávy v `core_mail_incoming_messages` strojově extrahovat business
dokumenty (přijatá faktura, dobropis, …) a nabídnout je uživateli k review/použití.
Vlastní extrakci dělá **externí analyzer daemon** (samostatný repozitář
`ai_analyzer`, vyvíjeno v paralelní Fázi 3b). Shipard je server-of-record:
spravuje frontu, claimy, ukládá výsledky, řídí review workflow.

## Pull-based protokol

Externí analyzer drží trvale token a periodicky volá:

```
GET  /api/v1/_mail/analysis/queue
POST /api/v1/_mail/analysis/{ndx}/claim
GET  /api/v1/_mail/analysis/{ndx}/payload
GET  /api/v1/_mail/analysis/{ndx}/attachments/{att_ndx}/content
POST /api/v1/_mail/analysis/{ndx}/result
POST /api/v1/_mail/analysis/{ndx}/failed
```

Auth: `Bearer shpd_ak_…` token systémového uživatele `_ai_analyzer`. Vygeneruje
ho `ai-analyzer-setup` CLI; zobrazí se jednou.

### Životní cyklus jednoho běhu

Pipeline status žije v `analysis_state` (cfgItem `core.mail.analysisStates`),
ortogonálně k workflow stavu `docState` — viz sekce "Stavy zprávy".

```
1. analyzer GET /queue                  ← zprávy s analysis_state=10 mimo Archiv/Koš
2. analyzer POST /{ndx}/claim           ← atomicky: analysis_state 10→20, vznikne claim row,
                                            response obsahuje plaintext API klíč backendu
                                            (jen v paměti; Cache-Control: no-store)
3. analyzer GET /{ndx}/payload          ← subject, body, metadata příloh
4. analyzer GET /{ndx}/attachments/.../content   ← streamuje binární obsah
5. analyzer ➜ provider (Anthropic, ...) ← analyzer.provider extrahuje, vrátí JSON
6. analyzer POST /{ndx}/result          ← uloží message_analyses + extracted_documents,
                                            uvolní claim, analysis_state →30; zpracuje
                                            volitelnou message_classification; vznikl-li
                                            aspoň jeden extracted doc a zpráva je stále
                                            v Nové, docState 10→20 (K řešení)
```

Při chybě:

```
2'. POST /{ndx}/failed { retryable: true }   ← analysis_state 20→10 (vrátí do fronty)
2'. POST /{ndx}/failed { retryable: false }  ← analysis_state 20→70 ("Analýza selhala")
```

`docState` se při failed nemění. Pokud analyzer mezi `claim` a `result` spadne,
`mail-analysis-reap` (cron 1×/min) najde claim s `expires_at < now()`, označí ho
`released=true` s reason `expired` a vrátí `analysis_state` zpět na 10 (jen
pokud je stále ve 20 — dokončený result/failed má přednost).

### Atomicita a souběh

`POST /claim` běží v transakci s `SELECT … FOR UPDATE` na řádku zprávy →
serializuje souběžné claim() přes stejnou zprávu. MariaDB nepodporuje partial
unique index `(message) WHERE released=0`, invariant "max jedna aktivní claim
per zpráva" tedy vynucuje aplikační kód v claim controlleru.

`POST /result` v jedné transakci: `INSERT message_analyses` → `INSERT
extracted_documents` (po jednom, status podle confidence vs profile thresholds)
→ `UPDATE claims SET released=1` → `UPDATE messages SET analysis_state=30`
(+ podmíněný `docState 10→20` a zápis klasifikace). Při selhání se vše
rollbackuje.

## Šifrování API klíčů backendů

`core_ai_backends.api_key` je sloupec typu `encrypted_text` (viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md)).
`AIBackendDocument::beforeSave()` šifruje hodnotu přes `DsSecretCipher` při
dirty change; `AnalysisController::claim()` ji decryptuje a vkládá plaintext do
JSON response s `Cache-Control: no-store, no-cache, must-revalidate`.

**Bezpečnostní invarianty (CLAUDE.md "Citlivá data" + spec §10 dec.2):**

- Plaintext nikdy neleží v DB.
- Plaintext nikdy nejde do logu — `claim()` catch maskuje výjimku fixní zprávou
  a detail loguje pouze server-side přes `error_log()`.
- Empty submit do form fieldu `api_key` → `AIBackendDocument::beforeSave` ho
  unsetne (UPDATE nepřepíše ciphertext).
- Bez injektovaného `DsSecretCipher` Document hodí výjimku → CLI / API nikdy
  silently nezapíše plaintext.

## Stavy zprávy

Zpráva má **dvě ortogonální osy** (spec
[tasks/mail-states-and-classification.md](../../../../tasks/mail-states-and-classification.md)):

**Workflow `docState`** (`core.mail.docStatesIncoming`) — stav pro uživatele,
srovnaný se zbytkem aplikace. Pipeline na něj sahá jediným místem: result
s dokumenty posune Novou na K řešení.

| Kód | cs | mainState | viewGroup | Poznámka |
|---|---|---|---|---|
| 10 | Nová | 1 | active | výchozí |
| 20 | K řešení | 2 | active | nastavuje result s dokumenty, nebo ručně |
| 40 | Hotovo | 3 | active | readOnly; auto-transition po review všech docs |
| 80 | Archiv | 4 | archive | readOnly |
| 90 | Smazáno | 5 | trash | readOnly |

**Pipeline `analysis_state`** (`core.mail.analysisStates`) — status AI
analýzy, přežívá Koš i Archiv; řídí ho výhradně pipeline + reanalyze:

```
0 (Bez analýzy)   koncový — AI vypnutá / netýká se (importy v Hotovo)

10 (Ve frontě) ──claim──▶ 20 (Analyzuje se) ──result──▶ 30 (Analyzováno)
                               │                              │
                               ├─failed retryable / reaper─▶ 10
                               │                              └─reanalyze─▶ 10
                               └─failed permanent─▶ 70 (Analýza selhala) ─reanalyze─▶ 10
```

`analysis_state=20` (aktivní claim) drží read-only zámek formuláře —
`IncomingMessageDocument::validate()` odmítne uložení s form-level chybou
`analysis_in_progress`. Přechody `docState` (Koš/Archiv) fungují i během
analýzy; fronta zprávy v Archivu/Koši přirozeně vynechává.

Nová zpráva dostává `analysis_state=10`, pokud analýza není explicitně
vypnutá (`ai_analysis_enabled=false`) a existuje aktivní AI profil; jinak 0.
Import (`POST /_mail/import`) s default `docState=40` dostává 0 — pokud
request nepošle `analysis_state` explicitně.

## Stavy extrahovaných dokumentů

`core.mail.extractedDocStates`:

| Kód | ID | Význam |
|---|---|---|
| 10 | `ready_to_apply` | confidence ≥ 0.9 — UI nabízí jen "Použít" |
| 20 | `pending_review` | 0.6 ≤ confidence < 0.9 — default po extrakci |
| 30 | `low_confidence` | confidence < 0.6 — vyžaduje pečlivý review |
| 40 | `applied` | uživatel potvrdil (entita vznikne v Fázi 3c) |
| 50 | `rejected` | uživatel zamítl jako false positive |
| 60 | `superseded` | nahrazen novou analýzou (po reanalyze) |
| 70 | `ai_failed` | AI nemohla extrahovat (nečitelné PDF apod.) |

Mapping confidence → status řídí pole `confidence_thresholds` v profilu:
`{"ready": 0.9, "review": 0.6}`. Status navíc stropuje pokrytí řádků —
viz „Obohacení řádků z historie" níže.

## Obohacení řádků z historie (Row History Enrichment)

Deterministická vrstva 0 „AI párování položek" — `RowHistoryEnricher`
(`modules/core/exchange/src/Enrich/`). Řádkům canonical dokumentu bez
`item.ourCode` doplní trojici `item.ourCode` + `vat.code` + `account`
podle řádků dřívějších dokladů (docState 20/40) téhož partnera a
`doc_type`. Bez šablon, bez LLM — paměť jsou finalizované doklady
v `docs_core_rows`.

Klíčová rozhodnutí (D1–D9, detailně `tasks/row-history-enrichment.md`):

| | |
|---|---|
| Doplňuje se | jen prázdná pole; priorita userAction pin > AI extrakce > historie |
| Historie | řádky partnera + doc_type, docState IN (20, 40), item živý (10/40/80), nejnovější první, limit 200 |
| Match popisu | exact raw → exact normalizovaný (bez číslic/interpunkce) → Jaccard token-set ≥ 0.6; první zásah vyhrává |
| Běží | `/result` (persist do `extracted_json`), `previewExtracted` (fresh), `apply` (fresh, před merge userActions) |
| Selhání | nikdy neshodí endpoint — log + neobohacený canonical |

Fresh běh je idempotentní: vlastní dřívější návrhy (poznané podle
`enrichment.suggested` == aktuální hodnota) nejdřív odvolá a matchuje
znovu proti aktuální DB. `DocumentApplier::withResolve()` přenáší
enrichment blok přes fresh resolve per row index.

Audit per řádek v `_resolve.rows[i].enrichment` (žádná změna schématu,
`_resolve` má `additionalProperties: true`); blok se zapisuje vždy,
i pro nenapárované a přeskočené řádky:

```jsonc
{
    "matchedBy":  "historyExactRaw" | "historyExactNorm" | "historyFuzzy" | null,
    "confidence": "high" | "medium" | null,
    "sourceDocId": 12345,                         // docs_core_heads.id zdroje
    "suggested":  { "ourCode": "…", "vatCode": "…", "account": "…" },  // co reálně doplnil
    "skipped":    "hasOurCode" | "noItemRow"      // jen u přeskočených řádků
}
```

**Strop statusu (D7):** `/result` sníží `ready_to_apply` na
`pending_review`, pokud po enrichmentu existuje item řádek bez
`item.ourCode`. Kontační řádky (`acc.record` / `accSide`) položku
nemají validně — stropu se netýkají.

**Zpětný zápis supplier codes (D8):** `SupplierCodeCaptureHandler`
(registrace `documentEventHandlers` v module.jsonc) při přechodu dokladu
10 → 20 s lineage `aiExtraction` zapíše `INSERT IGNORE` do
`economy_items_supplier_codes` mapování z canonical řádků s extrahovaným
`item.supplierCode`, kterým finální řádek přiřadil položku. Doplňuje
apply-time zápis `DocumentApplier::writeSupplierCodeMappings` (ten pokryje
řádky vyřešené už při apply, handler ruční přiřazení v Konceptu); překryv
řeší unique index `(person, supplier_code)`. Párování canonical → finální
řádky je poziční přes `order_pos` s guardem na shodu popisu.

## Auto-transition 20 → 40

Když uživatel přes UI přepne všechny extracted documents do `applied/rejected/
superseded` (a žádný nezůstane v `ready/pending/low`), zpráva sama přejde
z docState=20 (K řešení) na 40 (Hotovo). Trigger: explicit hook
`ExtractedDocumentDocument::afterPersist()` — běží uvnitř save transakce, takže
přechod je atomický. Stav `ai_failed` přechodu nebrání (admin se může rozhodnout
zprávu zavřít i s neúspěšnou extrakcí). Undo apply (unapply) reconciluje
reverzně: 40 → 20 (`reconcileMessageAfterUnapply`).

## Klasifikace typu zprávy (message_classification)

Od promptu **v2.2.0** analyzer v prvním kroku klasifikuje zprávu jako celek
a vrací volitelné top-level pole `message_classification: {primary_type,
confidence}` v `POST /result` (viz api-contract §9.5); protože stávající
analyzer daemon nové pole nepromotuje, server ho čte i z
`analysis_json.message_classification`. Server v transakci
resultu zapíše `primary_type` + `primary_type_source='ai'` — **jen pokud**
`primary_type_source != 'user'` (ruční volba uživatele má vždy přednost;
nastavuje ji dirty-change detekce v `IncomingMessageDocument::beforeSave`).
Neznámý typ = warning + ignore, uložení výsledku se nikdy nerozbije.

Dokumenty s `doc_type='other'` do `documents` nepatří — ne-faktura vrací
`documents: []` + klasifikaci `other`; dashboard pak emituje info kartu
„Není faktura" s akcemi Koš/Archiv (viz docs/dashboard.md).

Enum typů v promptu i output_schema je zatím natvrdo (`invoiceReceived` |
`other`); generování z `primaryTypes.jsonc` je future work.

## Reanalýza

`POST /api/v1/_mail/messages/{ndx}/reanalyze` — UI akce viditelná v toolbaru
detail panelu, jen když `analysis_state ∈ {30, 70}` a zpráva není v Archivu/Koši.
Volitelný `profile_override_ndx` v body. Logika:

1. Validuj `analysis_state` (30 nebo 70) a `docState NOT IN (80, 90)`.
2. Validuj profile_override (pokud zadán) — musí existovat a být `is_active=1`.
3. UPDATE existing extracted_documents WHERE status IN (10, 20, 30, 70) → `60`
   (superseded). Statusy 40 (applied) a 50 (rejected) **zůstávají** beze změny.
4. UPDATE message: `needs_reanalysis=1`, `profile_override`, `analysis_state→10`.
   `docState` se nemění.

Analyzer při dalším GET /queue zprávu uvidí včetně override profilu.

## UI detail panelu

`IncomingMessagesViewer` generuje 5 tabů:

1. **Obsah** — subject, sender, body
2. **Přílohy** — content attachments (bez raw .eml)
3. **Analýzy** — list běhů z `core_mail_message_analyses` (čas, model, prompt,
   confidence, počet extracted docs, cost, duration)
4. **Extrahované dokumenty** (Fáze 3a) — karty s typem, status badge, confidence,
   summary; akce per dokument: "Zobrazit detail" (modal s raw JSON),
   "Použít" (POST `/_mail/extracted-documents/{id}/apply`), "Zamítnout" (modal
   s povinným důvodem, POST `/_mail/extracted-documents/{id}/reject`).
   Tyto endpointy procházejí přes `ExtractedDocumentDocument::afterPersist`,
   takže auto-transition zprávy 20→40 funguje atomicky.
5. **Originál** — raw `.eml` pokud existuje

Řádek vieweru i hlavička detailu zobrazují badge stavu analýzy (label +
stateStyle z `core.mail.analysisStates`; hodnota 0 se nezobrazuje). Toolbar
v detailu obsahuje "Otevřít" (form edit) a "Znova analyzovat" (podmíněně dle
`analysis_state`, viz Reanalýza).

## Auto-provisioning

Při každém `ds-upgrade` se zavolá `AIAnalyzerProvisioner::provision()`:

1. Systémový uživatel `_ai_analyzer` (idempotentně).
2. Default backend (`backend_id=default`, `provider=anthropic`,
   `model=claude-sonnet-4-5`, `api_key=NULL`, `is_active=0`) — admin doplní
   klíč přes `ai-analyzer-set-key`, čímž `is_active=1`.
3. Default profil (`profile_id=czech_invoices`) ze šablony
   `profiles/default_czech_invoices.jsonc`.

Bootstrap je idempotentní — když existuje jiný profil/backend s `is_default=1`,
default *se nepřepíše*; admin zachová svůj override.

## Reference

- [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md) — kompletní spec
- [tasks/mail-states-and-classification.md](../../../../tasks/mail-states-and-classification.md)
  — oddělení `analysis_state` od `docState` + klasifikace `primary_type`
- [docs/operations/secrets.md](../../../../docs/operations/secrets.md) — DsSecretCipher
- [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) — API kontrakty
- [ai-prompts.md](ai-prompts.md) — default prompt + customization guidelines
