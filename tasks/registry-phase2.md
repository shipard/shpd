# Spisovna — Fáze 2: AI cesta (target `registry`)

## Kontext

Fáze 1 je hotová a nasazená: modul `base.registry` (tabulky 427/428, cfgItem
`docKinds`, viewer/form, `AttachmentService::copyTo`, ruční zařazení z pošty).
Tato fáze napojuje AI: klasifikace a extrakce **registry typů** (smlouva,
pojistka, nabídka, revize, úřední písemnost) a jejich zařazení do Spisovny
přes **stávající review workflow** `core_mail_extracted_documents` — nový
target `registry` vedle dosavadního `docs` (D5).

Autoritativní design je `docs/registry-mvp.md` §4 + §7 (aktualizovaný, viz
Odchylka níže). PRD ho **nenahrazuje** — implementuj podle designu, tady je
scope, pořadí a akceptace.

**Odchylka od původního §7.3 (v designu už opraveno):** analyzer žádný
lokální text příloh nemá — posílá je providerovi binárně
(`ai_analyzer/preprocessing.py`), takže `extracted_text` se plní **na PHP
straně** přes `pdftotext` (poppler-utils, na serverech je už kvůli
`pdftocairo`), best-effort při apply.

## Návaznost

- **Staví na Fázi 1** (committed): `RegistryDocumentDocument` (promote sync
  metadata → sloupce — applier ho využívá, nesyncuje sám),
  `AttachmentService::copyTo`, partner e-mail match ve
  `FileFromMessageService` (vytáhne se do sdílené podoby).
- **Dvě codebase:** `nov_shipard` (PHP + profil šablona) a `ai_analyzer`
  (Python). Analyzer je **profile-driven** (prompt i output_schema dostává
  z `/claim`) — očekávané změny v Pythonu: **žádný produkční kód, jen
  testy**. Ověř při implementaci; kdyby produkční změna přece byla nutná,
  zastav se a reportuj.
- **Pořadí nasazení:** PHP strana napřed (cfg + seam + applier snesou staré
  analýzy beze změny chování), profil v3 potom — od té chvíle analyzer
  začne registry typy vracet.
- **Zpětná kompatibilita:** docs cesta (faktury/dobropisy) se chováním
  nemění; extracted docs bez `target` v cfg = default `docs`.
- **Mimo:** triáž kaskáda a sender rules (Fáze 3), alerts/`registry_search`
  (Fáze 4), migrace `wkf.docs`, backfill.

## Před implementací přečti

- `docs/registry-mvp.md` **§4 + §7 celé** (po aktualizaci) — závazný návrh.
- `modules/core/mail/src/ExtractedDocumentApplier.php` — apply/unapply/
  `writeStatusTransition`/`writeUnapplyTransition`; sem přijde seam.
  Docs-specifika: enrichment, `_resolve` merge, `applyOptions`,
  `HEADS_TABLE` guard v unapply.
- `modules/core/mail/src/ExtractedDocumentDocument.php` — statusy,
  `afterPersist` (auto-transition zprávy), `reconcileMessageAfterUnapply`.
- `modules/core/mail/config/extractedDocTypes.jsonc` +
  `primaryTypes.jsonc` — párování klíčů (bez translation tabulky).
- `modules/core/mail/docs/ai-prompts.md` **celý** + šablona
  `modules/core/mail/profiles/default_czech_invoices.jsonc` — pole profilu,
  output_schema wrapper, sekce „Přidání nového typu dokumentu" (kroky 1–5;
  krok 4 „fields zůstává jednotné" touto fází přestává platit → oneOf).
- `tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php` — vzor drift
  testu (embed vs. canonical soubor).
- `modules/core/mail/src/AIAnalyzerProvisioner.php` —
  `syncProfileFromTemplate` (SemVer gate; dopraví v3 na běžící DS).
- `src/Api/Controller/AnalysisController.php` — ingest výsledků analýzy
  (mapping `documents[]` → extracted rows + thresholds → status) a
  apply/reject/unapply endpointy (wiring seamu). **Ověř, kde se dnes
  zapisuje `target_table_id`/`target_row_ndx` u docs cesty** — registry
  cesta musí být symetrická (recovery přes `completeApplied` na tom stojí).
- `modules/core/mail/src/Feed/MailSuggestionsSource.php` —
  `buildSuggestionCard` (titulek/podtitulek/akce), `fetchNotInvoiceRows`.
- `modules/base/registry/src/FileFromMessageService.php` — kopie příloh
  s orphan cleanupem, partner e-mail match (sdílet, ne duplikovat).
- `modules/core/exchange/src/Resolve/PartyResolver.php` — match dle
  companyId/vatId/name. (**Ne** `PersonResolver` — ten orchestruje celé
  person payloady vč. sub-kolekcí, pro match-only je to moc těžké.)
- MCP nástroj `mail_draft_document` (`modules/core/mail/src/Mcp/`) — druhé
  wiring místo applieru.
- Frontend: `frontend/src/components/dashboard/Dashboard.svelte` (akce
  `apply_extracted`/`review_extracted`/`reject_extracted`, undo), review
  modal (`DocumentExchangePreview`) — branch per target.
- `ai_analyzer`: `prompt.py`, `schema.py`, `tests/unit/` — jen kvůli
  ověření profile-driven chování a novým test fixtures.

## Scope

**V rozsahu:**

- cfg: `extractedDocTypes` — `target` na stávajících typech (`docs`) +
  5 nových registry typů s `docKind`; `primaryTypes` — tytéž klíče
- JSON schema `shpd.registry.document.v1`
  (`modules/base/registry/schemas/registry-document-v1.json`)
- seam: interface `ExtractedTargetApplier` (core.mail) + mapa targetů
  v `ExtractedDocumentApplier` (apply i unapply) + wiring napevno
- `RegistryApplier` (base.registry): mapping canonical → dokument (stav 40),
  binder návrh (historie → jméno → NULL), partner resolve (PartyResolver →
  e-mail → NULL), kopie příloh, `extracted_text`
- `TextExtractor` v `core.attachments` (pdftotext) + `DoctorCommand` check
  + zapojení do ruční cesty (`FileFromMessageService`)
- profil šablona **v3.0.0**: prompt pravidla pro registry typy,
  `output_schema.fields` jako **oneOf** (docs canonical | registry
  canonical), `supported_doc_types`; sync na běžící DS přes provisioner
- drift testy: registry embed vs. schema soubor; **kindFields vs.
  `docKinds.fields`** (strojová kontrola shody názvů — lekce „tiché
  prázdno")
- dashboard: registry karty v `MailSuggestionsSource` + frontend (label
  „Zařadit", registry review preview, undo)
- `ai_analyzer`: nové unit test fixtures (validace registry výstupu proti
  oneOf schématu, prompt render)
- dokumentace: `docs/ai-prompts.md` (oneOf, guidelines), README modulu

**Mimo rozsah:**

- levná triáž malým modelem / druhý profil — Fáze 2 jede v rámci
  stávajícího jednoprůchodu (design §7.7); rozhodnutí „profil vs. druhý
  profil" (design §11 bod 4) padá takto: **jeden profil, oneOf schema**,
  protože pipeline dnes běží jeden profil per analýza a multi-profil běhy
  by byly zásah do fronty
- učení binder návrhu nad rámec historie partner+druh
- jakékoli UI Spisovny nad rámec karet (viewer/form z Fáze 1 stačí)
- `tasks/README.md` neaktualizuj

## Doporučené pořadí

### Krok 0 — prerekvizity

Změny `.jsonc`/schémat → rebuild kompilované konfigurace + `ds-upgrade`.
Profil se na DS synchronizuje v rámci `ds-upgrade`
(`syncProfileFromTemplate`, SemVer gate) — ověř, že se volá, jinak doplň.

### Krok 1 — cfg + schéma + drift test skeleton

- `modules/core/mail/config/extractedDocTypes.jsonc` — dle designu §7.1:
  stávající typy dostanou `"target": "docs"`; nové typy `contract`,
  `insurance`, `quotation`, `certificate`, `official` s
  `"target": "registry"` + `"docKind"` + `enabled: true`, order 110–150.
- `modules/core/mail/config/primaryTypes.jsonc` — `quotation` zapnout;
  doplnit `contract`, `insurance`, `certificate`, `official`
  (enabled true; ordery sladit s extractedDocTypes). Párování klíčů
  primaryTypes ↔ extractedDocTypes zůstává zachované.
- `modules/base/registry/schemas/registry-document-v1.json` — JSON Schema
  draft-2020-12 dle designu §7.4: povinné `schema` (const
  `shpd.registry.document.v1`), `docType` (enum 5 registry klíčů),
  `title`; volitelné `summary`, `party {name, companyId, email}`,
  `kindFields`, `binderSuggestion`. `kindFields` větvené per `docType`
  (if/then), properties **doslova** = `docKinds.fields` příslušného druhu,
  `additionalProperties: false`. Datumy ISO 8601, žádné hádání.
- Nový drift test `RegistrySchemaDriftTest`: properties kindFields větví
  == `base.registry.docKinds` `fields` (načtené z config souboru). Selhání
  s návodem, co dogenerovat.

### Krok 2 — seam v `ExtractedDocumentApplier` (core.mail)

- Nový interface `modules/core/mail/src/ExtractedTargetApplier.php`:
  - `apply(array $canonical, array $extractedRow, ?int $userId): TargetApplyResult`
  - `unapply(array $extractedRow): TargetUnapplyResult`
  - malé result DTO (success, savedId | errorCode/errorMessage/statusCode)
    — držet tvar kompatibilní s `ExtractedApplyOutcome` mapováním.
- `ExtractedDocumentApplier`:
  - konstruktor: `array $targetAppliers = []` (mapa `target =>
    ExtractedTargetApplier`); **registrace napevno ve wiringu** (vzor
    FeedSources, D10) — žádný plugin registr;
  - `resolveTarget(string $docType): string` — z cfg
    `extractedDocTypes[docType]['target'] ?? 'docs'`;
  - `apply()`: po parse canonical větev — `docs` beze změny (enrichment,
    `_resolve` merge, applyOptions, `DocumentApplier`); `registry` →
    `$targetAppliers['registry']->apply(...)` (enrichment/_resolve/
    applyOptions se **přeskakují** — docs-specifika), pak sdílený
    `writeStatusTransition(applied)` beze změny; chybějící applier pro
    resolvnutý target → `INTERNAL_ERROR` 500 s jasnou hláškou;
  - `unapply()`: guard + úklid větvené per target — docs cesta
    (headsGateway, Koncept guard) beze změny; registry deleguje guard +
    trash na `$targetAppliers['registry']->unapply(...)`;
    `writeUnapplyTransition` zůstává sdílený. Pozn.: dnes je `unapply`
    static s `$headsGateway` — refactor na instanční / předání mapy je
    v pořádku, přizpůsob volající místa.
- Wiring (mapa targetů): `AnalysisController` (apply/reject/unapply
  endpointy), MCP `mail_draft_document`, případná další místa — najdi
  všechna přes usages `ExtractedDocumentApplier`.

### Krok 3 — `RegistryApplier` (base.registry)

`modules/base/registry/src/RegistryApplier.php` implements
`ExtractedTargetApplier`, dle designu §7.3:

- **Mapping**: `title`, `doc_kind` z cfg (`extractedDocTypes[doc_type]
  ['docKind']`), `metadata` = `kindFields` 1:1 (promoted sloupce doplní
  `RegistryDocumentDocument::beforeSave` z metadata — applier je
  nenastavuje ručně), `ai_summary` = `summary`, `source_kind='mail'`,
  `source_message`, `extracted_doc`, `created_by = $userId`.
- **Stav**: dokument vzniká v **40 (Zařazeno)**. Pokud stavový automat
  vynucuje vznik v 10, založ v 10 a proveď přechod 10→40 v téže transakci
  přes Document flow — výsledek stejný, jednoklik zůstává jednoklik.
- **Binder návrh** (design §7.5, bez LLM): (1) historie — nejčastější
  `binder` živých dokumentů (`docStateMain=1`) téhož `partner` + `doc_kind`
  (jen když se partner resolvnul); (2) `binderSuggestion` case-insensitive
  match na živé šanony; (3) NULL. **Nikdy nezakládá šanon.**
- **Partner resolve** (design §7.6): `PartyResolver` nad `party`
  (companyId/name) — použít **jen** `Matched` výsledek; cokoli jiného →
  fallback match e-mailu odesílatele (sdílený helper vytažený z
  `FileFromMessageService` — extrahuj do `PartnerEmailMatcher` nebo
  obdobné sdílené třídy v base.registry, ruční cesta ho použije taky);
  jinak NULL. **Žádné auto-zakládání osob.**
- **Přílohy**: kopie dle `source_attachments` extracted dokladu přes
  `AttachmentService::copyTo` (fallback: všechny obsahové přílohy zprávy
  — stejný výběr jako Fáze 1); orphan cleanup při selhání (vzor
  `FileFromMessageService`).
- **`extracted_text`**: `TextExtractor` (krok 4) nad zkopírovanými
  přílohami, best-effort — selhání apply nikdy neblokuje, jen warn log.
- **Target zápis**: `target_table_id='base_registry_documents'` +
  `target_row_ndx` symetricky s docs cestou (viz „Před implementací
  přečti" — ověřené místo zápisu).
- **`unapply`**: guard — extracted `applied` řeší sdílená vrstva; applier
  ověří, že dokument je stále `docState=40` **a** `modified IS NULL OR
  modified <= applied_at` (jinak `DOC_ADVANCED` 409); → Koš
  (`docState=90`) přes `TableGateway::saveDocument`; přílohy dokumentu se
  nemažou (soft-delete je vratný).
- **Transakčnost**: DB kroky v transakci, kopie souborů mimo ni
  s úklidem.

### Krok 4 — `TextExtractor` (core.attachments)

- `modules/core/attachments/src/TextExtractor.php` — vedle
  `ThumbnailGenerator`/`MetadataExtractor` (stejný vzor): `application/pdf`
  → `pdftotext -layout` do stdout; `text/*` → přímé čtení; jiné MIME →
  null. Cap délky (např. 500 000 znaků — `mediumtext` má rezervu), UTF-8
  sanitizace, chybějící binárka / timeout → null + warn.
- `DoctorCommand`: přidat `pdftotext` do binary checks;
  `install-packages.sh` ověřit (poppler-utils už instalujeme kvůli
  `pdftocairo` — pravděpodobně jen check, žádná změna).
- Zapojení do ruční cesty: `FileFromMessageService` po kopii příloh
  best-effort naplní `extracted_text` (drobný delta, sjednocuje chování).
- `docs/attachments.md` — doplnit sekci.

### Krok 5 — profil šablona v3.0.0

`modules/core/mail/profiles/default_czech_invoices.jsonc`:

- `prompt_version`: `v3.0.0`.
- `supported_doc_types`: + 5 registry klíčů.
- `prompt_template` — nová pravidla: kdy klasifikovat který registry typ;
  `documents[].fields` pro registry typy = `shpd.registry.document.v1`
  (**přesné názvy kindFields dle druhu vyjmenovat v promptu** — žádné
  přejmenovávání, nesoulad = tiché prázdno); `summary` 2–3 věty v jazyce
  profilu („co to je, co z toho plyne, na co si dát pozor");
  `binderSuggestion` volitelný (obecný název šanonu, např. „Pojištění");
  `primary_type` enum rozšířený o nové klíče; nadále platí: neuhaduj,
  vynech neznámé, ISO formáty.
- `output_schema`: `documents[].doc_type` enum + nové klíče;
  `fields` → **oneOf** [stávající docs canonical embed, registry canonical
  embed]. Analyzer neumí `$ref` napříč soubory — registry větev je
  doslovná kopie `registry-document-v1.json`.
- `ProfileSchemaDriftTest` rozšířit: registry embed == schema soubor
  (vedle stávající docs kontroly).
- `docs/ai-prompts.md`: aktualizovat sekci Output schema (oneOf) a
  „Přidání nového typu dokumentu" (krok 4 — fields už není jednotné,
  volba větve dle targetu typu).
- Sync: `ds-upgrade` → `syncProfileFromTemplate` (SemVer v2.x → v3.0.0)
  dopraví profil na běžící DS. Ověř na test DS, že sync proběhl a
  nepřepsal admin pole.

### Krok 6 — `ai_analyzer` (jen testy)

- Ověř, že produkční kód žádnou změnu nepotřebuje (schema i prompt chodí
  z `/claim`; `schema.py` validuje generic JSON Schema — oneOf zvládá
  `jsonschema` knihovna).
- Nové unit testy: fixture validního registry výstupu (insurance +
  contract) proti v3 output_schema (test_schema); fixture nevalidního
  (cizí klíč v kindFields → fail, díky `additionalProperties: false`);
  prompt render smoke s v3 šablonou (test_prompt).

### Krok 7 — dashboard + frontend

- `MailSuggestionsSource`:
  - resolve target z cfg per `doc_type` (helper sdílet s applierem —
    statická metoda / malá utilita, ne copy-paste);
  - registry návrhové karty: titulek `{docKind label} — {party.name}`
    (fallback bez partnera jen label); podtitulek: „platí do {datum}"
    (klíč kindFields mapovaný na `valid_to` najdi **inverzí**
    `docKinds[docKind]['promote']`) + jistota + e-mail subject;
    `context.target = 'registry'`; akce stejné kinds
    (`apply_extracted`/`review_extracted`/`reject_extracted`, apply
    primary jen u status 10) — endpointy beze změny;
  - `fetchNotInvoiceRows` beze změny (registry typy mají vlastní
    `primary_type`, karta „Není faktura" na ně nespadne — ověř testem).
- Frontend:
  - label akce apply dle `context.target`: registry → „Zařadit"
    (i18n `dashboard.card.action.apply_registry` cs+en; docs beze změny);
  - review modal: branch per target — nová kompaktní komponenta
    `RegistryExtractedPreview.svelte` (title, summary, tabulka kindFields
    s lokalizovanými labely, binder návrh, přílohy, akce Zařadit /
    Zamítnout); **žádný resolve panel** (design §7.8);
  - undo (unapply) beze změny — stejný endpoint, ověř že toast/refresh
    funguje i pro registry kartu.

### Krok 8 — testy + ověření na alfě

Celý běh úzkými filtry (viz Testy). Po nasazení na alfu: reanalyze 2–3
reálných zpráv se smlouvou / nabídkou přes UI a projít kartu → Zařadit →
dokument ve Spisovně → undo. Mutace na alfě (reanalyze, apply) až po
explicitním odsouhlasení v chatu, jednotlivě (D3 konvence).

## Testy

- **Unit (PHP):**
  - `resolveTarget`: bez `target` v cfg → `docs`; neznámý doc_type → `docs`;
  - `RegistryApplier::apply`: mapping canonical → řádek (doc_kind z cfg,
    metadata 1:1, promoted doplněné přes beforeSave, ai_summary, source_*);
    binder historie / jméno / NULL; partner Matched / e-mail fallback /
    NULL (nikdy create); chybějící kindFields → dokument vznikne
    s prázdnými metadaty (žádný fail);
  - `RegistryApplier::unapply`: happy path → 90; `modified > applied_at`
    → `DOC_ADVANCED`; dokument smazaný ručně → `DOC_ADVANCED`;
  - `TextExtractor`: PDF fixture → text; text/plain; nepodporovaný MIME →
    null; chybějící binárka → null bez výjimky;
  - drift: `ProfileSchemaDriftTest` (docs + registry embed),
    `RegistrySchemaDriftTest` (kindFields == docKinds.fields).
- **Integrační/API:**
  - apply registry extracted: dokument 40 + kopie příloh + extracted
    applied + zpráva 30→40 (afterPersist);
  - unapply: dokument 90, extracted 20, zpráva reverz, `target_*`
    vynulované; opakovaný unapply → 409;
  - recovery cesta (`completeApplied`) funguje pro registry target;
  - reject registry extracted — beze změny chování;
  - regresní: apply/unapply faktury (docs cesta) beze změny.
- **Python:** `pytest tests/unit` — nové fixtures (krok 6).
- PHPUnit úzkými `--filter` (RegistryApplier, TextExtractor, Drift,
  ExtractedTarget…), ne široké běhy.

## Commit strategie

(1) cfg + schéma + drift skeleton, (2) seam interface + mapa + wiring +
regresní testy docs cesty, (3) RegistryApplier + testy, (4) TextExtractor +
Doctor + ruční cesta, (5) profil v3 + drift + ai-prompts.md,
(6) ai_analyzer testy, (7) dashboard + frontend. Každý commit zelené testy;
krok 5 nasazovat až po krocích 2–4 (analyzer začne registry typy vracet
teprve s v3 profilem).

## Hotovo když

- [ ] cfg rozšířeno, rebuild + `ds-upgrade` OK; párování
      primaryTypes ↔ extractedDocTypes drží
- [ ] apply extrahované pojistky/smlouvy vytvoří dokument Spisovny ve
      stavu 40 s kopiemi příloh, metadaty, promoted sloupci, `ai_summary`
      a (při úspěchu pdftotext) `extracted_text`
- [ ] binder návrh: historie → jméno → NULL; nikdy nezaloží šanon
- [ ] partner: PartyResolver match-only + e-mail fallback; nikdy
      auto-create
- [ ] unapply: dokument → Koš, extracted → 20, zpráva reverz; guard
      `DOC_ADVANCED` při mezitímní editaci
- [ ] docs cesta beze změny chování (regresní testy zelené)
- [ ] profil v3.0.0 se přes `ds-upgrade` syncne na běžící DS; drift testy
      hlídají oba embedy + shodu kindFields s `docKinds.fields`
- [ ] `ai_analyzer` bez produkčních změn, nové testy zelené
- [ ] dashboard: registry karty (titulek, „platí do", jistota), akce
      „Zařadit", registry review preview bez resolve panelu, undo funguje
- [ ] `pdftotext` v `DoctorCommand`; ruční zařazení plní `extracted_text`
- [ ] dokumentace aktualizovaná (`docs/ai-prompts.md`,
      `docs/attachments.md`, README modulu)
