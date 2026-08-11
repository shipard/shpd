# Modul `mail` — Message-centrická AI analýza (zánik extrahovaných dokumentů)

**Stav:** částečně — Fáze A–D v tomto repozitáři hotové (schéma, /result v4,
message-centrické endpointy, dashboard + UI, ISDOC embedded/dedup, testy,
dokumentace); zbývá Fáze B3 (`ai_analyzer` daemon, jiný repozitář)
a koordinované nasazení na alfě (stop daemon → upgrade → ds-reset
+ přeimport → start daemonu).

**Poznámky k implementaci (odchylky od PRD):**

- Kontrakt/prompt nese verzi **v4.0.0** — PRD psalo „v3.0.0", ale profil měl
  v době implementace už `v3.2.0`; breaking změna = major bump.
- `source.message` v canonicalu nahradil **obě** dřívější pole
  (`source.extractedDoc` i `source.mailMessage` z ISDOC větve).
- Adaptovány i komponenty, které PRD explicitně nezmiňovalo: MCP nástroje
  (`mail_list_pending` → `has_open_proposal`, `mail_draft_document` bez
  `extracted_document_id`), `hosting-stats` a sloupec
  `base_registry_documents.extracted_doc` (odstraněn, `source_message` stačí).
- Jednodokumentový invariant v ISDOC větvi platí už od Fáze A (víc dokumentů
  → AI fronta); Fáze D doplnila dedup identitou + embedded PDF extrakci.

**Cíl:** Nahradit model „zpráva → N extrahovaných dokumentů" modelem
**jedna zpráva → nejvýše jeden dokumentový návrh**. Jednotkou analýzy je
celá zpráva (subject + body + přílohy). Tabulka
`core_mail_extracted_documents` zaniká; canonical návrh se stěhuje do
historie analýz (`core_mail_message_analyses`), verdikt uživatele
(apply/reject) se zapisuje na řádek analýzy, lineage doklad ↔ zpráva se
narovnává na prostý FK v obou směrech.

**Motivace (shrnutí designové diskuze):**

- Reálná data (alfa, 252 analyzovaných zpráv): ~91 % zpráv produkuje
  1 dokument; vícedokumentové zprávy jsou téměř výhradně registry typy
  (`official`, `contract`), napříč 4 DS existuje jediná zpráva se dvěma
  fakturami. Původní motivace extracted docs („více faktur v e-mailu")
  se v provozu nevyskytuje.
- Schéma je už dnes napůl 1:1: `core_mail_incoming_messages.target_table_id`
  + `target_row` je **singulární** polymorfní vazba — zpráva s N extracted
  docs ji nemůže korektně naplnit. AI apply flow ji dnes nenaplňuje vůbec,
  takže „mail" skupina příloh v detailu dokladu
  (`DocsHeadsViewer::sourceAttachmentGroups`) pro AI doklady nefunguje.
- Extrakce je attachment-centrická — tělo zprávy (platební kontext,
  úřední dokumenty, faktura v textu) do modelu nepasuje; message-centrická
  analýza ho dělá first-class vstupem a otevírá cestu pro budoucí skilly
  (výměr daně → návrh předpisu do saldokonta).
- Dvojí klasifikace (`message.primary_type` × `extracted.doc_type`) se
  hroutí do jedné osy.
- Spisovna: jedno doručení = jeden záznam se všemi přílohami (sémantika
  podacího deníku) — dnešní rozpad úředního e-mailu na 6 registry
  záznamů generuje 6 review karet pro jednu zásilku.

**Návaznost:**

- Nahrazuje část `tasks/mail-phase3a.md` (extracted documents) a navazuje
  na `tasks/mail-states-and-classification.md` (osy docState /
  analysis_state / primary_type zůstávají beze změny).
- ISDOC větev (`tasks/mail-isdoc-import.md`) se adaptuje a rozšiřuje
  (Fáze D: embedded ISDOC v PDF + deduplikace identitou).
- Vyžaduje koordinovanou změnu **`ai_analyzer` daemonu** (Fáze B —
  provádí se v repozitáři `ai_analyzer`, kontrakt definuje tento PRD).
- Migrační pipeline `old_shipard`: pouze verifikace, viz
  `old_shipard: modules/imports/newShipard/tasks/24-mail-message-centric-verify.md`.
- Dokumentace k aktualizaci: `modules/core/mail/docs/ai-analysis.md`,
  `modules/core/mail/docs/ai-prompts.md`, `docs/mail/api-contract.md`,
  `docs/dashboard.md`, `docs/exchange-format.md` (source.*),
  `tables/core_mail_message_analyses.md`,
  `tables/core_mail_incoming_messages.md`; `tables/core_mail_extracted_documents.md`
  zaniká.

---

## Klíčová rozhodnutí (potvrzena, D1–D12)

1. **D1 — Kardinalita:** jedna zpráva → nejvýše jeden dokumentový návrh.
   Jednotkou analýzy je celá zpráva (subject + body + přílohy).
2. **D2 — Zánik `core_mail_extracted_documents`:** canonical se přesune
   do `core_mail_message_analyses.canonical_json`; „aktuální návrh" =
   poslední úspěšná analýza (`MAX(analyzed_at)`, bez flagu, stávající
   konvence). `superseded` zaniká jako koncept (implicitní v historii).
3. **D3 — Verdikt na řádku analýzy:** `resolution` (40 applied /
   50 rejected) + `rejected_reason` + `resolved_at/by`. Confidence pásma
   (ready/pending/low) přestávají být perzistentním stavem — počítají se
   za běhu z `confidence` vs. thresholds profilu.
4. **D4 — Jedna klasifikace:** `extractedDocTypes` splyne
   s `primaryTypes` (klíče už jsou shodné); `primaryTypes` převezmou
   `target` (docs/registry) a `docKind`. `primary_type_source='user'`
   má nadále absolutní přednost.
5. **D5 — Spisovna message-centricky:** jedno doručení = jeden registry
   záznam se **všemi** obsahovými přílohami zprávy.
6. **D6 — Lineage:** `docs_core_heads.source_extracted_doc` →
   `source_message`; apply zapisuje obě strany vazby
   (doklad.source_message ↔ zpráva.target_*) atomicky.
7. **D7 — `secondary_findings`:** informativní seznam dalších nálezů
   v `analysis_json`; žádné entity, žádný stav; hint na kartě
   a v detailu zprávy.
8. **D8 — ISDOC:** detekce kandidátů = samostatné přílohy **+ ISDOC
   embedded v PDF** (extrakce server-side při intake, ne v mail-routeru);
   deduplikace identitou (UUID, fallback kompozit); právě jedna identita
   → deterministický import; více odlišných identit → AI fronta.
9. **D9 — Split tool mimo scope:** samostatné budoucí PRD; mixed zprávy
   do té doby řeší uživatel ručně (vědomé omezení modelu, ne bug).
10. **D10 — Dashboard karta = zpráva;** přílohy karty = všechny obsahové
    přílohy zprávy (`source_attachments` filtr zaniká).
11. **D11 — Kontrakt:** `POST /result` nese `document` (0..1) + povinnou
    `message_classification` + volitelné `secondary_findings`. Prompt
    v3.0.0, big-bang nasazení na alfě, žádná kompatibilní mezivrstva.
    Endpointy `/_mail/extracted-documents/*` se nahrazují
    message-centrickými.
12. **D12 — Žádná migrace dat:** zdroje na alfě se přeimportují
    (`ds-reset`), pošta doteče; `ds-upgrade` provede jen schéma.

---

## Fáze A — Datový model + server (nasazuje se společně s Fází B)

### A1. Schéma

**`core_mail_message_analyses`** — nové sloupce (section `result`
a nová section `resolution`):

- `canonical_json` longtext NULL — validovaný + obohacený canonical
  (`shpd.docs.document.v1`); NULL když běh žádný dokument nenavrhl;
  při selhání validace wrapper `{_validationError, raw}` (stejná
  sémantika jako dnešní ai_failed wrapper na extracted).
- `proposed_type` enumString(30) NULL — typ dokumentu navržený tímto
  během (klíč `core.mail.primaryTypes`). Historický záznam — na rozdíl
  od mutable `message.primary_type` se po zápisu nemění. Wire pole
  kontraktu se jmenuje `doc_type` (viz B1).
- `resolution` enumInt NULL — NULL = otevřený návrh / bez návrhu,
  `40` = applied, `50` = rejected. Nový config
  `config/analysisResolutions.jsonc` (cfgItem
  `core.mail.analysisResolutions`), kódy záměrně shodné s dnešními
  extracted stavy.
- `rejected_reason` text NULL — povinný při `resolution=50`.
- `resolved_at` datetime NULL, `resolved_by` int NULL
  → `core_system_users`.

Sloupec `extracted_document_count` zaniká (degeneroval by na 0/1);
viewer tab Analýzy místo něj ukazuje „návrh: ano/ne" z `canonical_json
IS NOT NULL`.

**`docs_core_heads`**: `source_extracted_doc` zaniká, přibývá
`source_message` int NULL ref `core_mail_incoming_messages`. Pozn.:
`ds-upgrade` neumí rename → drop + add (bez dat, D12). Index
`idx_source_message`.

**`core_mail_incoming_messages`**: beze změny schématu (target_* už
existuje). Mění se jen to, kdo ho plní (A4).

**Drop `core_mail_extracted_documents`** — tabulka, `tables/*.jsonc`
+ `.md`, třídy `ExtractedDocumentDocument`, `ExtractedDocumentApplier`,
`ExtractedDocumentStatusResolver`, `ExtractedApplyOutcome`,
`ExtractedDocTypes` (náhrada viz A2), auto-transition hook
`afterPersist`, reference v `module.jsonc`.

### A2. Konfigurace

- `config/primaryTypes.jsonc` se rozšíří o atributy `target`
  (`docs` / `registry`; chybějící = `docs`) a `docKind` (jen registry
  typy) — hodnoty se převezmou z `extractedDocTypes.jsonc`, který
  následně **zaniká** i s `extractedDocStates.jsonc`.
- Helper třída `PrimaryTypes` (nahrazuje `ExtractedDocTypes`):
  `targetFor()`, `docKindFor()`, validace klíče.
- Nový `config/analysisResolutions.jsonc`.
- Runtime resolver confidence pásem (nahrazuje
  `ExtractedDocumentStatusResolver` jako čistá funkce bez persistence):
  vstup `confidence` + thresholds profilu (fallback
  `{ready: 0.9, review: 0.6}`) + canonical (enrichment strop, dnešní
  pravidlo D7 z row-history-enrichment: item řádek bez `item.ourCode`
  nebo enrichment `low` → strop `review`). Výstup `ready | review | low`
  — používá dashboard (kind karty) a detail zprávy (badge). Nikam se
  nezapisuje.

### A3. `POST /result` — nová transakce

1. Validace `document.extracted_json` proti schema; enrichment
   (`RowHistoryEnricher`, jako dnes); INSERT `message_analyses`
   (status=2, `canonical_json`, `proposed_type`, `confidence`).
   Nevalidní canonical → wrapper do `canonical_json`, běh se uloží
   (dashboard emituje chybovou kartu), 201 se vrací.
2. UPDATE claims SET released (beze změny).
3. UPDATE messages `analysis_state=30`, vynulovat `needs_reanalysis`
   (beze změny).
4. `message_classification` — **povinná** (422 při absenci; prompt v3
   ji vždy generuje). Validace klíče a pravidlo `primary_type_source`
   beze změny.
5. Workflow: `document` přítomen a validní **a** docState=10 →
   docState 20. Bez dokumentu se docState nemění (karta „Není faktura",
   beze změny chování).
6. `secondary_findings` se nevaliduje strukturálně nad rámec „pole
   objektů `{type, note}`" — žije jen v `analysis_json`.

Response 201: `{ analysis_ndx }`.

### A4. Message-centrické akce (nahrazují `/_mail/extracted-documents/*`)

Všechny operují nad **poslední úspěšnou analýzou** zprávy
(`MAX(analyzed_at)`, status=2); guard: `analysis_state=30`,
docState NOT IN (80, 90), `resolution IS NULL`.

**`GET /_mail/messages/{ndx}/preview`** — ekvivalent dnešního
`previewExtracted`: canonical z poslední analýzy + fresh enrichment +
`applier->preview()`; registry větev vrací canonical přímo (jako dnes);
ai_failed wrapper větev zachována. Přílohy v response = **všechny**
obsahové přílohy zprávy (D10).

**`POST /_mail/messages/{ndx}/apply`** — v jedné transakci:

1. Guard navíc: `canonical_json` validní, `message.target_row` prázdný
   (409 při obsazeném).
2. Routing podle `proposed_type` běhu → `PrimaryTypes::targetFor()`.
   (Nesoulad s aktuálním `message.primary_type` apply neblokuje —
   UI může zobrazit warning; závazný je typ návrhu.)
3. Docs target: server-side injection `source.kind='aiExtraction'` +
   `source.message={ndx}` (nahrazuje `source.extractedDoc`), fresh
   enrichment, `DocumentApplier` (autoCreateMode/targetDocState beze
   změny). Po vzniku dokladu zapsat `docs_core_heads.source_message`.
4. Registry target: `RegistryApplier` — záznam dostává **všechny**
   obsahové přílohy zprávy (D5), `source_attachments` logika zaniká.
5. Obě větve: `message.target_table_id/target_row` = cílová entita;
   docState → 40 (z 10 i 20); analysis `resolution=40`,
   `resolved_at/by`.
6. Recovery/idempotence: zachovat dnešní vzor z
   `ExtractedDocumentApplier` (target zapsán, navazující krok selhal →
   opakovaný apply dokončí zbytek, nevytváří duplicitní entitu).

**`POST /_mail/messages/{ndx}/reject`** — povinný `reason`;
analysis `resolution=50` + `rejected_reason` + `resolved_at/by`;
docState → 40 (Hotovo — symetricky s apply; uživatel může následně
Koš/Archiv). Reanalýza po rejectu zůstává možná (30 → 10, vznikne
nový běh s `resolution=NULL`).

**`POST /_mail/messages/{ndx}/unapply`** — reverz apply: soft-delete
cílové entity (dnešní sémantika per target), vynulovat
`message.target_*`, analysis `resolution/resolved_*` → NULL,
docState 40 → 20. Bez UI (toast/API, jako dnes).

**Reanalyze** (`/_mail/messages/{ndx}/reanalyze`): krok „UPDATE
extracted → superseded" zaniká; zbytek beze změny (guard resolution:
zprávu s poslední analýzou `resolution=40` a živým targetem
reanalyzovat nelze — 409, nejdřív unapply).

### A5. Adaptace navazujícího kódu

- `SupplierCodeCaptureHandler`: lineage detekce `source_extracted_doc`
  → `source_message` (+ `source.message` v canonicalu).
- `DocumentApplier::withResolve()` / injection: `source.extractedDoc`
  → `source.message`; `docs/exchange-format.md` aktualizovat
  (source.* tabulka, `source_kind`/`source_message` na heads).
- `DocsHeadsViewer::sourceMessages()` — rozšířit dotaz: UNION přes
  `message.target_*` (migrované + registry) **a** `heads.source_message`
  (AI flow) — po D6 obě cesty konzistentní, dotaz přes source_message
  je primární pro docs.
- `IncomingMessageDocument::beforeDelete` — cascade na extracted zaniká
  (analýzy cascade zůstává).
- `AnalysisClaimReaper`, claim/payload/failed endpointy: beze změny.
- PHPUnit: přepsat testy extracted flow na message-centrické
  (apply/reject/unapply/preview, result transakce, resolution guardy).

---

## Fáze B — Kontrakt + prompt v3 + `ai_analyzer` (co-deploy s Fází A)

### B1. Kontrakt `POST /result` (api-contract §9.5)

```jsonc
{
  "model_name": "…", "prompt_version": "v3.0.0", // … telemetrie beze změny
  "message_classification": {            // POVINNÉ
    "primary_type": "invoiceReceived",
    "confidence": 0.97
  },
  "document": {                          // volitelné, max 1
    "doc_type": "invoiceReceived",       // → sloupec proposed_type
    "extracted_json": { /* canonical */ },
    "confidence": 0.94
  },
  "secondary_findings": [                // volitelné
    { "type": "contract", "note": "Rámcová smlouva v příloze smlouva.pdf" }
  ]
}
```

`source_attachment_ndxs` z kontraktu zaniká. Pole `extracted_documents`
server od v3 nepřijímá (422) — big-bang, bez mezivrstvy (D11).

### B2. Prompt v3.0.0 (`profiles/default_czech_invoices.jsonc` + ai-prompts.md)

- Instrukce: analyzuj zprávu **jako celek** — subject + body + přílohy
  jsou jeden kontext; tělo zprávy je plnohodnotný zdroj (platební
  instrukce, faktura v textu, úřední obsah).
- Vrať právě jednu `message_classification` (vždy) a nejvýše jeden
  `document` — **primární** dokument zprávy. Kritérium primárnosti:
  business dokument, kvůli kterému zpráva přišla (faktura > smlouva >
  obchodní podmínky/přílohy).
- Další nálezy → `secondary_findings` (typ z enum primaryTypes + krátká
  poznámka), nikdy druhý `document`.
- `output_schema` úprava — **názvy polí přesně dle B1** (analyzer nic
  nepřejmenovává).

### B3. `ai_analyzer` daemon (samostatný repozitář, vlastní CC sezení)

- Stavba těla `/result` dle B1 (documents[] → document, promoce
  `message_classification` a `secondary_findings` z model outputu na
  top-level).
- Žádná změna claim/payload/attachments/failed protokolu.
- Nasazení: koordinovaně s Fází A na alfě (zastavit daemon → deploy
  server → deploy daemon → start). Fronta je pull-based, výpadek
  analýzy během okna je neškodný.

---

## Fáze C — Dashboard + UI

### C1. `MailSuggestionsSource` (docs/dashboard.md §5.1)

Message-centrický dotaz: zprávy v docState 10/20 s poslední úspěšnou
analýzou, `resolution IS NULL`. Druhy karet (mapování zachováno, mění
se jen zdroj):

- canonical + pásmo `ready` → `kind=ready` (akce apply primary,
  review, reject);
- canonical + `review`/`low` → `kind=review` (review primary, reject);
- registry návrh → registry karta (headline z canonical, jako dnes);
- `analysis_state=70` → chybová karta (beze změny);
- klasifikace `other` bez canonical → „Není faktura" (beze změny);
- **nově:** neprázdné `secondary_findings` → řádek hintu na kartě
  („+ smlouva v příloze").

Akční targety karet: `{messageNdx}` místo `{extractedNdx}`. Přílohy
karty: všechny obsahové přílohy zprávy (batch dotaz beze změny, filtr
přes source_attachments zaniká) — tím mizí i ISDOC zobrazovací bug
(PDF sourozenec `.isdoc` přílohy nebyl na kartě vidět).

### C2. Detail zprávy (`IncomingMessagesViewer`, form)

- Tab „Extrahované dokumenty" → **„Návrh"**: jedna karta z poslední
  analýzy (typ, confidence badge z runtime resolveru, summary
  z canonicalu, `secondary_findings` hint; akce Použít / Zamítnout /
  Detail). Bez návrhu: prázdný stav s klasifikací.
- Tab „Analýzy" (historie) zůstává; sloupec počtu dokumentů →
  „návrh ano/ne" + resolution badge (Použito / Zamítnuto) u verdiktů.
- `DocumentExchangePreviewModal`: přepnout na
  `GET /_mail/messages/{ndx}/preview`; panel příloh = všechny obsahové
  přílohy zprávy.
- Detail dokladu: skupina „mail" příloh nyní funguje i pro AI doklady
  (A5 / D6) — jen ověřit, frontend beze změny.
- `npm run check:i18n` — nové klíče (Návrh, resolution labely, hinty).

---

## Fáze D — ISDOC: embedded extrakce + deduplikace identitou

### D1. Detekce kandidátů (`IsdocImportService`)

- Dosavadní: přílohy `.isdoc` / `.isdocx` / XML root `{http://isdoc.cz/*}Invoice`.
- **Nově:** PDF přílohy s embedded files (PDF/A-3 `/EmbeddedFiles`) —
  extrakce ISDOC souborů server-side při intake. Doporučená
  implementace: `pdfdetach` (poppler-utils) s graceful degradation —
  binárka chybí → embedded detekce vypnuta, warning do logu jednou,
  intake nikdy neselže. (Čistě PHP parsing PDF embedded files je
  nespolehlivý; poppler je na serverech dostupný/instalovatelný —
  ověřit v provisioningu, případně přidat do `shpd-server` závislostí
  + doctor check.)
- Embedded ISDOC je **transientní** — nevytváří se z něj příloha zprávy
  (PDF nosič na zprávě je); do canonicalu jde `attachments[]` odkaz na
  nosné PDF (kind default, ne `structured` — strojová forma je uvnitř).

### D2. Deduplikace identitou

1. Rozparsovat všechny kandidáty (`IsdocReader`).
2. Identitní klíč: element `UUID`; fallback kompozit
   (`ID` dokladu + DIČ/IČ výstavce + datum vystavení).
3. Shodná identita → jeden doklad; preference zdroje: samostatná
   `.isdoc` příloha > embedded z PDF (deterministické pořadí).
   „Zahození" = nevzniká druhý návrh; přílohy zprávy nedotčeny.
4. Po dedupu právě 1 identita → deterministický import (canonical do
   `canonical_json` běhu `model_name='isdoc'`, confidence 1.0,
   `proposed_type` dle DocumentType, guard transakce beze změny).
5. Po dedupu ≥ 2 odlišné identity → větev se celá vzdá → AI fronta
   (stejná úniková sémantika jako vadný ISDOC); AI vybere primární +
   `secondary_findings`.

### D3. Testy

Fixtures: samostatný ISDOC; PDF s embedded ISDOC; duo příloha+embedded
téže identity (dedup na 1); dvě odlišné identity (fallback do fronty);
vadný embedded (ignor, pokračuje se zbytkem); chybějící `pdfdetach`
(degradace).

---

## Pořadí a nasazení

1. Fáze A + B se vyvíjejí paralelně a **nasazují společně** (alfa:
   stop daemon → `shpd-server upgrade` + `ds-upgrade` všech DS →
   `ai-profile-reload --force` → deploy + start daemonu).
2. Fáze C hned poté (frontend bez schema závislostí nad rámec A).
3. Fáze D nezávisle po A (dotýká se jen `IsdocImportService`).
4. `ds-reset` + přeimport zdrojů na alfě (D12) — provádí David/Anna,
   mimo scope PRD.

## Mimo scope

- Split tool (rozpad zprávy na více zpráv) — samostatné budoucí PRD;
  `secondary_findings` je jeho připravený vstup.
- Generování enum typů do promptu z `primaryTypes.jsonc` (trvající
  future work z Fáze 3a).
- Skill „výměr → předpis do saldokonta" — message-centrický model je
  jeho předpoklad, ne součást.
