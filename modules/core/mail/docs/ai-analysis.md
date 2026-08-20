# AI analýza došlých zpráv

Tento dokument popisuje architekturu, datový tok a životní cyklus AI analýzy
v modulu `core.mail`. Specs: [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md)
(protokol, Fáze 3a) a [tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
(message-centrický model, který extrahované dokumenty nahradil).

## Cíl

Z došlé zprávy v `core_mail_incoming_messages` strojově vytěžit **nejvýše
jeden dokumentový návrh** (přijatá faktura, dobropis, registry dokument…)
a nabídnout ho uživateli k review/použití. Jednotkou analýzy je **celá
zpráva** — subject + body + přílohy jsou jeden kontext (D1). Vlastní
extrakci dělá **externí analyzer daemon** (samostatný repozitář
`ai_analyzer`). Shipard je server-of-record: spravuje frontu, claimy,
ukládá výsledky, řídí review workflow.

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
1. analyzer GET /queue                  ← zprávy s analysis_state=10 mimo Archiv/Koš,
                                            z povolených schránek (viz "Stavy zprávy")
2. analyzer POST /{ndx}/claim           ← atomicky: analysis_state 10→20, vznikne claim row,
                                            response obsahuje plaintext API klíč backendu
                                            (jen v paměti; Cache-Control: no-store)
3. analyzer GET /{ndx}/payload          ← subject, body, metadata příloh
4. analyzer GET /{ndx}/attachments/.../content   ← streamuje binární obsah
5. analyzer ➜ provider (Anthropic, ...) ← analyzer.provider extrahuje, vrátí JSON
6. analyzer POST /{ndx}/result          ← uloží běh do message_analyses (canonical_json,
                                            proposed_type, confidence), uvolní claim,
                                            analysis_state →30; zpracuje povinnou
                                            message_classification; nese-li běh validní
                                            dokumentový návrh a zpráva je stále v Nové,
                                            docState 10→20 (K řešení)
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

`POST /result` v jedné transakci: `INSERT message_analyses` (status=2,
`canonical_json` = validovaný + obohacený canonical návrhu, `proposed_type`,
`confidence`; nevalidní canonical → forenzní wrapper `{_validationError, …}`
do `canonical_json`, běh se uloží a 201 se vrací) → `UPDATE claims SET
released=1` → `UPDATE messages SET analysis_state=30` (+ podmíněný
`docState 10→20` a zápis klasifikace). Při selhání se vše rollbackuje.
Kontrakt v4 detailně: [docs/mail/api-contract.md §9.5](../../../../docs/mail/api-contract.md).

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
s dokumentovým návrhem posune Novou na K řešení. Do Hotovo zprávu posouvá
verdikt uživatele (apply/reject návrhu).

| Kód | cs | mainState | viewGroup | Poznámka |
|---|---|---|---|---|
| 10 | Nová | 1 | active | výchozí |
| 20 | K řešení | 2 | active | nastavuje result s dokumentovým návrhem, nebo ručně |
| 40 | Hotovo | 3 | active | readOnly; nastavuje verdikt (apply i reject), unapply vrací na 20 |
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

Nová zpráva dostává `analysis_state=10`, pokud vzniká v docState 10 nebo 20
(Nová/K řešení; chybějící docState = default Nová), analýza není vypnutá
a existuje aktivní AI profil; jinak 0. Zda je analýza vypnutá, se dědí
s precedencí **zpráva > schránka > default povoleno**: explicitní
message-level `ai_analysis_enabled` (true/false) rozhoduje vždy; při `NULL`
rozhoduje flag schránky `mailboxes.ai_analysis_disabled`; obojí neurčené =
povoleno. Stejnou precedenci vynucuje `/queue` (výdej i `total_available`) —
zprávu z vypnuté schránky nevydá, ledaže má `ai_analysis_enabled=1`, takže
vypnutí schránky působí okamžitě i na už nafrontované zprávy.
Zprávy vznikající rovnou v Hotovo/Archivu/Koši (import, zrcadlení archivní
pošty) se nefrontují — `/queue` by je nikdy nevydal (trvale zavádějící
„Ve frontě") a hrozila by hromadná analýza při odarchivování. Explicitní
`analysis_state` v requestu (`POST /_mail/import`) má vždy přednost.
Dříve nafrontované archivní zprávy opravuje idempotentní datový krok
v `ds-upgrade` (`AIAnalyzerProvisioner::fixQueuedArchivedMessages` —
jen docState 80/90; Hotovo do opravy nepatří, tam mohla zpráva dojít
legálně workflow cestou s dokončenou analýzou).

## Dokumentový návrh a verdikt (resolution)

Zpráva má **nejvýše jeden otevřený dokumentový návrh**: canonical poslední
úspěšné analýzy (`canonical_json` na řádku `core_mail_message_analyses`,
konvence `MAX(analyzed_at), status=2` — žádný flag). Typ návrhu nese
`proposed_type` (klíč `core.mail.primaryTypes`) — historický záznam běhu,
na rozdíl od mutable `message.primary_type` se po zápisu nemění.

**Verdikt uživatele** se zapisuje na řádek analýzy — sloupec `resolution`
(cfgItem `core.mail.analysisResolutions`):

| Kód | Význam |
|---|---|
| NULL | otevřený návrh / běh bez návrhu |
| 40 | `applied` — návrh byl použit, cílová entita existuje |
| 50 | `rejected` — uživatel zamítl (`rejected_reason` povinný) |

K verdiktu patří `resolved_at` / `resolved_by`. Kódy jsou záměrně shodné
s někdejšími stavy extrahovaných dokumentů (tabulka
`core_mail_extracted_documents` zanikla — D2).

**Confidence pásma nejsou perzistentní stav.** Pásmo `ready` / `review` /
`low` počítá za běhu `AnalysisConfidenceResolver` z `confidence` běhu vs.
thresholds profilu (`confidence_thresholds`, fallback
`{"ready": 0.9, "review": 0.6}`), se stropem podle pokrytí řádků — viz
„Obohacení řádků z historie" níže. Používá ho dashboard (kind karty),
detail zprávy (badge) a preview; nikam se nezapisuje.

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
| Dominantní položka | úroveň 3, fallback bez textového signálu (`tasks/enrichment-dominant-item.md`): historie ≥ 10 řádků a jedna položka ≥ 80 % z nich → `historyDominantItem` / `low`, trojice z nejnovějšího výskytu. Guard přes částku: total řádku > max historických `total_price` dominantní položky → bez návrhu (chybějící total řádku → guard se neuplatní) |
| Běží | `/result` (persist do `canonical_json`), `GET /_mail/messages/{ndx}/preview` (fresh), `apply` (fresh, před merge userActions) |
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
    "matchedBy":  "historyExactRaw" | "historyExactNorm" | "historyFuzzy" | "historyDominantItem" | null,
    "confidence": "high" | "medium" | "low" | null,
    "matchedText": "…",                            // vyhrávající kandidátní text (null u dominance)
    "itemName":   "Materiál",                      // jméno navržené položky (pro UI tooltip)
    "sourceDocId": 12345,                         // docs_core_heads.id zdroje
    "sourceDocNumber": "FP-2026-0042",            // doc_number zdroje (pro UI badge)
    "suggested":  { "ourCode": "…", "vatCode": "…", "account": "…" },  // co reálně doplnil
    "dominance":  { "share": 0.94, "rows": 179 }, // jen u historyDominantItem (podklad tooltip badge)
    "skipped":    "hasOurCode" | "noItemRow"      // jen u přeskočených řádků
}
```

**Strop pásma (D7):** `AnalysisConfidenceResolver::capBandByRowCoverage`
sníží runtime pásmo `ready` na `review`, pokud po enrichmentu existuje
item řádek bez `item.ourCode`, **nebo** řádek doplněný enrichmentem
s confidence `low` (dominance nemá textový signál — uživatel návrh
potvrzuje, viz `tasks/enrichment-dominant-item.md` D5). Kontační řádky
(`acc.record` / `accSide`) položku nemají validně — stropu se netýkají.
Strop se počítá za běhu při každém čtení (feed, detail, preview) — nic
se nepersistuje.

**Zpětný zápis supplier codes (D8):** `SupplierCodeCaptureHandler`
(registrace `documentEventHandlers` v module.jsonc) při přechodu dokladu
10 → 20 s lineage `aiExtraction` zapíše `INSERT IGNORE` do
`economy_items_supplier_codes` mapování z canonical řádků s extrahovaným
`item.supplierCode`, kterým finální řádek přiřadil položku. Doplňuje
apply-time zápis `DocumentApplier::writeSupplierCodeMappings` (ten pokryje
řádky vyřešené už při apply, handler ruční přiřazení v Konceptu); překryv
řeší unique index `(person, supplier_code)`. Párování canonical → finální
řádky je poziční přes `order_pos` s guardem na shodu popisu.

## Obsahová eskalace (content tags)

Vrstva 2 „AI párování položek" (`tasks/content-tag-enrichment.md`,
D1–D22) — nastupuje, když po Vrstvě 0 zbývají item řádky bez
`item.ourCode`. Orchestruje `RowEnrichmentPipeline`
(`modules/core/exchange/src/Enrich/`), který nahrazuje přímé volání
`RowHistoryEnricher` ve všech cestách kromě ISDOC importu (strukturované
položky, eskalace tam nepatří).

**Flow:**

1. Vrstva 0 (`RowHistoryEnricher::enrich`) — beze změny.
2. Zbývá nepokrytý item řádek? Ne → konec.
3. **Pravidlo IČO → štítek** (`core_exchange_tag_rules`, tableId 438):
   zásah přeskakuje LLM, persistuje se
   `_resolve.contentTag {tag, tagSource: "rule", ruleId}`.
4. Jinak **LLM klasifikace** (`ContentTagClassifier`, jen při `/result` —
   právě jednou za běh analýzy): doklad se klasifikuje do fixní taxonomie
   `core.exchange.contentTags` (prompt `tag-v1.0.0`, enum generovaný
   z cfgItem, digest = supplier + popisy řádků + totals). Persist
   `{tag, tagSource: "llm", tagConfidence, promptVersion, rowExceptions}`;
   `null` štítek je legitimní výstup (nic se nezapisuje). Selhání LLM
   nikdy neshodí `/result`.
5. **Resolution štítek → položka** (`ContentTagResolver`, fresh při každém
   čtení): tag řádku = `rowExceptions[rowIndex] ?? primaryTag`; právě
   jedna živá položka s tagem v `economy_items.content_tags` → trojice
   {ourCode, account}; více → `ambiguous`; žádná → fallback účet z nabídky
   účetních položek aktivní varianty osnovy (`AccountingItemsOffer`,
   prefix fallback `vehicle.*`) → `accountOnly`; ani ten → `unmapped`.
   `amountGuard` z `economy.items.contentTagDefaults` návrh zadrží
   (`guarded`, možný dlouhodobý majetek). Propisují se jen prázdná pole.

Fresh běh (preview/apply) LLM nevolá — **fresh re-check pravidla přepíše
persistnutý LLM štítek** (deterministika bije odhad, D16); otagování
položky mezi analýzou a preview se projeví bez reanalýzy. Štítek se navíc
denormalizuje do sloupce `core_mail_message_analyses.content_tag`.

Audit per řádek (`_resolve.rows[i].enrichment`):

```jsonc
{
    "matchedBy":  "contentTag",
    "confidence": "medium" | null,        // medium jen když se něco propsalo
    "tag":        "vehicle.fuel",
    "tagSource":  "rule" | "llm",
    "itemName":   "Spotřeba PHM",         // jen resolution item
    "sourceItemId": 123,                  // jen resolution item
    "suggested":  { "ourCode": "…", "account": "…" },  // co reálně doplnil
    "resolution": "item" | "accountOnly" | "ambiguous" | "unmapped" | "guarded",
    "candidates": ["FUEL-A", "FUEL-B"],   // jen ambiguous
    "guard":      "amount",               // jen guarded
    "vatHint":    "nonDeductible",        // informativní (D4), z contentTagDefaults
    "tagLabel":   "Pohonné hmoty"         // jen fresh čtení (jazyk requestu, D23);
                                          // persist z /result label nenese
}
```

**Precedence (D13):** setting `exchange.contentTag.beforeDominance`
(SettingsStore, default `true`) — contentTag má přednost před dominancí
(tier 3 Vrstvy 0 běží až jako úklid po eskalaci); `false` = stávající
pořadí. Backend LLM klasifikace jde přes setting
`exchange.contentTag.backend` (ndx `core_ai_backends`, null = default
backend; doporučení: levný model). Obojí zatím jen přes `ds-setting set`.

**UI (tasks/content-tag-ui.md, D23–D28):**

- **Review modal**: podmíněný sloupec Účet (render, když aspoň jeden řádek
  `account` nese), badge tooltip „Obsahová klasifikace — {tagLabel}
  (pravidlo dodavatele / AI)" + řádek vatHint. Row-level userAction
  **`noItem`** („Jen účet — bez položky", stringová akce jako `skip`):
  klient ji nabídne, jen když řádek nese `account`; `DocumentApplier`
  reconcile pořídí řádek bez item FK s účtem, chybějící účet = 422
  `no_item_requires_account`; pin přebíjí fresh resolve i enrichment
  návrh (D3). Response označí řádek `_resolve.rows[i].item.status =
  "noItem"`.
- **Dashboard karta „Nová kategorie"** (`ContentTagSuggestionsSource`,
  `modules/core/exchange/src/Dashboard/`): jedna karta per štítek
  s otevřenými návrhy bez živé otagované položky; akce
  `materialize_content_tag` volá `POST /_exchange/content-tags/materialize`
  (sdílená služba `AccountingItemMaterializer`). `goods.stock` nabízí
  volbu materiál (501…) / zboží (504…); štítky vědomě bez mapování
  (admin.other…) nekartují. Query-driven, bez dismiss stavu.
- **Nastavení → Položky → Obsahové štítky** (panel `contentTags`,
  `ContentTagsSettings.svelte`): stav mapování taxonomie + Založit per
  štítek (`GET /_exchange/content-tags/overview`), reverzní otagování
  neotagovaných položek s jednoznačným účtem
  (`POST /_exchange/content-tags/tag-items`), odkaz na setup nabídku.
- **Nastavení → Položky → Pravidla obsahových štítků**
  (`core.exchange.tagRules` viewer + `TagRuleDocument`): label štítku,
  IČO + jméno partnera, origin, statistiky; přeštítkování formulářem
  přepne `origin` na `user`, smazání = hard DELETE (detail akce) —
  koš by přes unique(company_id) blokoval re-learning.

**Strop pásma (D14):** řádek doplněný s `matchedBy: "contentTag"` stropuje
pásmo na `review` vždy — obsahový návrh potvrzuje člověk.

**Learning (D22):** `ContentTagRuleCaptureHandler` (registrace
`documentEventHandlers` v `core.exchange/module.jsonc`) při přechodu
dokladu 10 → 20 s lineage `aiExtraction` a LLM štítkem zapíše pravidlo
IČO → štítek (origin `learned`, platné okamžitě). Shoda s existujícím
pravidlem → jen statistiky; konflikt s `learned` pravidlem → pravidlo se
smaže (dodavatel s pestrým sortimentem); `user`/`seed` pravidla learning
nikdy nemění.

**Seed z účetní historie:** třetí zdroj pravidel `IČO → štítek` (kromě
ruční správy a learningu) je import agregované účetní historie ze
zdrojového systému — `shpd-ds booking-history --apply-seed`, origin
`seed`. Nad tímtéž souborem stojí i report o kvalitě zdroje a o taxonomii
(pokrytí, konzistence LLM × reverz, mrtvé štítky) a reverzní otagování
položek podle účtů. Formát souboru, prahy seedu a chování příkazu:
[`docs/booking-history-format.md`](../../../../docs/booking-history-format.md).

## Zaokrouhlení celkové částky při apply

Faktury se zaokrouhlenou částkou k úhradě (typicky na celé Kč):
`DocumentApplier::transform` odvozuje `total_rounding_mode` dokladu
nezávisle z čísel — porovnáním vypočtené částky (Σ `vatRecap[].total` →
`totalBase + totalVat` → Σ řádků s DPH) s deklarovanou
`totals.totalAmount`. Extrahovaný `totals.totalRounding` je jen
informativní (review modal ho zobrazuje v součtech). Konzervativní
kritérium a detaily: `docs/exchange-format.md` (sekce vatRecap/totals)
a [tasks/mail-invoice-rounding.md](../../../../tasks/mail-invoice-rounding.md).
Platí i pro ISDOC větev (`PayableRoundingAmount`) — apply jde přes týž
applier.

## Režim výpočtu DPH při apply (derivace vat_mode)

U dokladů s řádky v koncových cenách (účtenky, PHM, maloobchod) AI
vrací `vat.mode: "fromBase"`, ačkoli `rows[].totalPrice` už daň
obsahuje — počítat DPH zdola by ji na doklad dalo podruhé.
`DocumentApplier` proto `vat_mode` hlavičky deterministicky derivuje
(`VatModeDerivation`): sedí-li Σ řádků právě na Σ `vatRecap[].total`
(a ne na base), nastaví režim „shora" (fromTotal) bez ohledu na
deklarovaný mode — a zrcadlově. Korekce se v review modalu zobrazí
jako warning **`vat_mode_derived`** v `_resolve.issues` (preview
i apply). Když derivace nemá dost dat (chybí recap i `totalBase`),
validátor místo toho warnuje **`vat_mode_suspect`**. Prompt (od v4.1.0,
zpřesněno ve v4.2.0) navíc učí model mode u koncových cen vracet rovnou
správně — derivace zůstává pojistka. Integritu řádků a rekapitulace
hlídají validátorové warningy **`rows_recap_mismatch`** (neúplné řádky)
a **`vat_recap_inconsistent`** (rekapitulace dopočtená místo opsaná) —
viz `docs/exchange-format.md` a
[tasks/ai-extraction-integrity.md](../../../../tasks/ai-extraction-integrity.md). Detaily: `docs/exchange-format.md` (sekce vat)
a [tasks/docs-vat-mode-derivation.md](../../../../tasks/docs-vat-mode-derivation.md).

## Pohyb řádků při apply (doplnění operation)

AI pohyb řádku (`rows[].operation`) záměrně nevrací — je to interní
účetní koncept, na předloze není a prompt pro něj pravidlo nemá. Aby
koncept z Použít prošel na stav V pořádku bez ručního doplňování,
`DocumentApplier` doplní item řádkům pohyb podle typu resolvované /
založené položky, jinak výchozím pohybem docTypu (cfgItem
`docs.core.applyRowOperations`). Doplnění je **tiché** — probíhá na
každém item řádku každého apply, takže info hláška by svítila vždy
a degradovala pozornost pro vzácné hlášky; transparentnost dává sám
výsledek (sloupec Pohyb konceptu). Do `_resolve.issues` jde jen
warning `row_operation_config_invalid` při rozbité konfiguraci
(neexistující/nepovolený kód). Detaily: `docs/exchange-format.md` §10
„Doplnění pohybu řádků"
a [tasks/mail-apply-row-operation.md](../../../../tasks/mail-apply-row-operation.md).

## Message-centrické akce (apply / reject / unapply / preview)

Akce nad dokumentovým návrhem operují nad **poslední úspěšnou analýzou**
zprávy; jádro je HTTP-agnostická služba `MessageProposalApplier` (sdílí ji
HTTP controller i MCP nástroj `mail_draft_document`), výsledek nese
`ProposalApplyOutcome`. Guardy: zpráva mimo Archiv/Koš, `analysis_state=30`,
otevřený návrh (`resolution IS NULL`). Endpointy (detailně
[docs/mail/api-contract.md §9.8–9.12](../../../../docs/mail/api-contract.md)):

- **`GET /_mail/messages/{ndx}/preview`** — read-only náhled návrhu pro
  review modal: canonical + fresh enrichment + `applier->preview()`
  (registry větev vrací canonical přímo). Přílohy v response = **všechny**
  obsahové přílohy zprávy (D10).
- **`POST /_mail/messages/{ndx}/apply`** — routing dle `proposed_type`
  běhu přes `PrimaryTypes::targetFor()`: docs → exchange `DocumentApplier`
  se server-side injection `source.kind='aiExtraction'` +
  `source.message={ndx}`; registry → `RegistryApplier` (záznam dostává
  všechny obsahové přílohy zprávy, D5). Obě větve zapíšou obě strany
  lineage atomicky (doklad `source_message` ↔ zpráva `target_table_id` /
  `target_row`); pak verdikt `resolution=40` + zpráva → Hotovo (z 10 i 20).
  Recovery/idempotence: obsazený `message.target_row` = opakovaný apply
  jen dokončí zbytek (nevzniká duplicitní entita).
- **`POST /_mail/messages/{ndx}/reject`** — povinný `reason`;
  `resolution=50` + `rejected_reason` + `resolved_at/by`; zpráva → Hotovo
  (symetricky s apply, uživatel může následně Koš/Archiv).
- **`POST /_mail/messages/{ndx}/unapply`** — reverz apply (bez UI, MCP/API
  záchranná brzda): cílová entita → Koš (docs guard: stále nedotčený
  Koncept, jinak 409 `DOC_ADVANCED`), `message.target_*` → NULL,
  `resolution/resolved_*` → NULL, zpráva 40 → 20.

## Klasifikace typu zprávy (message_classification)

Analyzer v prvním kroku klasifikuje zprávu jako celek a vrací top-level
pole `message_classification: {primary_type, confidence}` v `POST /result`
— od kontraktu v4 **povinné** (422 při absenci; prompt v4 ho vždy
generuje). Server v transakci resultu zapíše `primary_type` +
`primary_type_source='ai'` — **jen pokud** `primary_type_source != 'user'`
(ruční volba uživatele má vždy přednost; nastavuje ji dirty-change detekce
v `IncomingMessageDocument::beforeSave`). Neznámý typ = warning + ignore,
uložení výsledku se nikdy nerozbije.

Dokument s `doc_type='other'` neexistuje — ne-faktura vrací
`document: null` + klasifikaci `other`; dashboard pak emituje info kartu
„Není faktura" s akcemi Koš/Archiv (viz docs/dashboard.md). Ostatní nálezy
vedle primárního dokumentu (smlouva v příloze faktury apod.) vrací analyzer
jako informativní `secondary_findings` (`{type, note}`, D7) — žijí jen
v `analysis_json`, žádné entity, žádný stav; UI je ukazuje jako hint na
kartě a v detailu zprávy.

Enum typů v promptu i output_schema je zatím natvrdo; generování
z `primaryTypes.jsonc` je future work.

## Deterministický ISDOC import

Když došlá zpráva nese ISDOC, extrahuje se doklad **deterministicky
parserem** místo AI analýzy — ISDOC nese autoritativní strukturovaná data,
LLM extrakce je u něj zbytečná. Specs:
[tasks/mail-isdoc-import.md](../../../../tasks/mail-isdoc-import.md),
[tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
Fáze D (embedded + dedup).

**Detekce kandidátů** (`IsdocImportService`): samostatné přílohy `.isdoc` /
`.isdocx` (ZIP obal) / XML s root elementem `{http://isdoc.cz/*}Invoice`,
**plus ISDOC embedded v PDF** (PDF/A-3 `/EmbeddedFiles`) — extrakce
server-side při intake přes `pdfdetach` (poppler-utils). Chybí-li binárka,
embedded detekce se vypne s jednorázovým warningem do logu, intake nikdy
neselže; přítomnost `pdfdetach` kontroluje `shpd-server doctor`. Embedded
ISDOC je **transientní** — nevytváří se z něj příloha zprávy (nosné PDF na
zprávě je); do canonicalu jde `attachments[]` odkaz na nosné PDF
(kind `original`, ne `structured` — strojová forma je uvnitř).

Datový tok: `MailController::receiveIncoming` po commitu intake transakce
zavolá `IsdocImportService::tryImport` (lazy wiring — bez kandidáta se
service vůbec nestaví). Service:

1. rozparsuje všechny kandidáty (`IsdocReader`, modul `core.exchange`) na
   canonical `shpd.docs.document.v1` se `source.kind='isdoc'`,
2. **deduplikuje identitou** (D8): klíč = element `UUID`, fallback kompozit
   (`ID` dokladu + DIČ/IČ výstavce + datum vystavení). Shodná identita →
   jeden doklad, preference zdroje samostatná `.isdoc` příloha > embedded
   z PDF (deterministické pořadí); přílohy zprávy nedotčeny. Po dedupu
   **≥ 2 odlišné identity** → větev se celá vzdá → AI fronta (zpráva má
   nejvýše jeden návrh, D1 — AI vybere primární + `secondary_findings`),
3. zvaliduje proti schema a obohatí řádky z historie
   (`RowHistoryEnricher`, stejné jako `/result`),
4. ve vlastní transakci s `FOR UPDATE` guardem (`analysis_state IN (0, 10)`
   — závod s analyzerem prohrává import) zapíše:
   - záznam do `core_mail_message_analyses` (`status=2`,
     `model_name='isdoc'`, `prompt_version='isdoc'`, `model_version`
     z `@version` XML, cost/tokens NULL, `confidence=1.0`,
     `canonical_json` = canonical návrhu, `proposed_type` dle
     `DocumentType`: 1 → `invoiceReceived`, 2 → `creditNote`) —
     žádná jiná entita nevzniká, návrh čeká na verdikt jako u AI,
   - message: `analysis_state=30`, `primary_type='invoiceReceived'`
     + `primary_type_source='isdoc'` (jen pokud source není `user`),
     docState 10→20 jen pokud je stále 10.

Vztah k frontě: úspěšně naimportovaná zpráva se v AI frontě **vůbec
neobjeví** (analysis_state přeskočí 10 → 30); analyzer daemon nevyžaduje
změny. Vadná **samostatná** ISDOC příloha / nepodporovaný `DocumentType`
(zálohové faktury apod.) = celá větev se pro zprávu vzdá a zpráva jde
normálně do AI fronty (warning v logu, příjem pošty nikdy neselže); vadný
**embedded** ISDOC se naopak jen ignoruje a pokračuje se zbytkem kandidátů.
Import funguje i v DS bez AI backendu/profilu (thresholds fallback
`{ready: 0.9, review: 0.6}`).

„Znova analyzovat" (30 → 10) zůstává únikovou cestou k AI, kdyby ISDOC
výsledek nestačil.

## Reanalýza

`POST /api/v1/_mail/messages/{ndx}/reanalyze` — UI akce viditelná v toolbaru
detail panelu, jen když `analysis_state ∈ {30, 70}` a zpráva není v Archivu/Koši.
Volitelný `profile_override_ndx` v body. Logika:

1. Validuj `analysis_state` (30 nebo 70) a `docState NOT IN (80, 90)`.
2. Guard aplikovaného návrhu: zprávu, jejíž poslední úspěšná analýza má
   `resolution=40` a živý target (`target_row` obsazený), reanalyzovat
   nelze — 409, nejdřív unapply.
3. Validuj profile_override (pokud zadán) — musí existovat a být `is_active=1`.
4. UPDATE message: `needs_reanalysis=1`, `profile_override`, `analysis_state→10`.
   `docState` se nemění. Zpráva ze schránky s `ai_analysis_disabled=1` bez
   message-level `ai_analysis_enabled=1` dostane navíc `ai_analysis_enabled=1` —
   explicitní záměr uživatele přebíjí default schránky, jinak by `/queue`
   zprávu tiše nikdy nevydal.

Historie analýz se nemění — „aktuální návrh" je implicitně poslední
úspěšný běh, žádný supersede krok neexistuje (koncept `superseded` zanikl).
Reanalýza po rejectu je možná — vznikne nový běh s `resolution=NULL`.
Analyzer při dalším GET /queue zprávu uvidí včetně override profilu.

## UI detail panelu

`IncomingMessagesViewer` generuje 4 taby (labely z cfgItem
`core.mail.viewerDetailLabels`):

1. **Obsah** — subject, sender, body + sekce obsahových příloh (bez raw .eml)
2. **Analýzy** — historie běhů z `core_mail_message_analyses` (čas, model,
   prompt, confidence, sloupec **Návrh** ano/ne z `canonical_json IS NOT
   NULL`, sloupec **Verdikt** z `resolution`, cost, duration)
3. **Návrh** — dokumentový návrh poslední úspěšné analýzy (nejvýše jeden,
   D1): jedna karta s typem, confidence pásmem z runtime resolveru (resp.
   resolution badge u rozhodnutých), summary z canonicalu, hintem
   `secondary_findings` a akcemi **Použít** (POST
   `/_mail/messages/{ndx}/apply`), **Zamítnout** (modal s povinným
   důvodem, POST `/_mail/messages/{ndx}/reject`) a **Zobrazit detail**
   (review modal nad `GET /_mail/messages/{ndx}/preview`). Bez návrhu
   prázdný stav s klasifikací zprávy.
4. **Originál** — raw `.eml` pokud existuje

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
3. Default profil (`profile_id=czech_general`) ze šablony
   `profiles/czech_general.jsonc`. Před lookupem běží jednorázový rename
   legacy id `czech_invoices` → `czech_general` (včetně `name`; ds-upgrade
   vypíše `[RENAME]`, po prvním běhu no-op).

Bootstrap je idempotentní — když existuje jiný profil/backend s `is_default=1`,
default *se nepřepíše*; admin zachová svůj override.

## Reference

- [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md) — spec protokolu (Fáze 3a)
- [tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
  — message-centrický model (D1–D12): zánik extrahovaných dokumentů,
  resolution, secondary_findings, lineage
- [tasks/mail-states-and-classification.md](../../../../tasks/mail-states-and-classification.md)
  — oddělení `analysis_state` od `docState` + klasifikace `primary_type`
- [tasks/mail-isdoc-import.md](../../../../tasks/mail-isdoc-import.md)
  — deterministický ISDOC import (mapovací tabulka ISDOC → canonical)
- [docs/operations/secrets.md](../../../../docs/operations/secrets.md) — DsSecretCipher
- [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) — API kontrakty
- [ai-prompts.md](ai-prompts.md) — default prompt + customization guidelines
