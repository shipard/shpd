# Spisovna — Fáze 1: modul `base.registry` (základ bez AI)

**Stav:** hotovo

## Kontext

Spisovna je evidence trvalých dokumentů firmy (smlouvy, pojistky, revize,
nabídky, úřední písemnosti) — viz `docs/registry-mvp.md` (schválená
rozhodnutí D1–D10 v §0). Tato fáze staví **základ bez AI**: modul
`base.registry`, tabulky šanonů a dokumentů, cfgItem slovník druhů, viewer,
formulář a ruční zařazení z došlé zprávy (kopie příloh).

Autoritativní design je `docs/registry-mvp.md` §3–§6. PRD ho **nenahrazuje**
— implementuj podle designu, tady je scope, pořadí a akceptace.

**Názvosloví:** UI a dokumentace „Spisovna", identifikátory anglicky
(`base.registry`, `base_registry_*`, `Shipard\Module\Base\Registry`).

## Návaznost

- **Staví na:** `core.system`, `core.attachments` (přílohy + nová copy
  metoda), `base.persons` (partner), `core.mail` (zdrojová zpráva, toolbar
  akce). Nic z toho se nemění destruktivně.
- **Odblokuje:** import ze starého `wkf.docs` (samostatný task migračního
  pipeline, design §10) a Fázi 2 (AI target `registry`, design §7).
- **Pouze `nov_shipard`.** Ze starého Shipardu se v této fázi neportuje nic.
- **Bez AI.** Analyzer, profily, extracted_documents ani dashboard se
  nemění.

## Před implementací přečti

- `docs/registry-mvp.md` **§3–§6 celé** (+ §4 cfgItemy, §5 JSONC skici
  tabulek — jsou závazné vč. tableId 427/428 a indexů).
- `modules/base/persons/module.jsonc` a `modules/core/mail/module.jsonc` —
  vzory module.jsonc (dependencies, viewers/navSection, forms,
  documentClasses, settingsItems, config).
- `modules/core/mail/src/IncomingMessagesViewer.php` — 5-slotový layout,
  detail taby, `getToolbarActions()` (vzor `reanalyze` pro novou akci
  vč. cfgItem `core.mail.viewerDefaults` pro labely).
- `modules/core/mail/src/IncomingMessagesForm.php` — vzor formuláře
  s read-only náhledy příloh (`attachmentsView`, fill mechanismus).
- `modules/base/persons/src/PersonDocument.php` — vzor validace unikátnosti
  mezi živými záznamy (`is_own`).
- `modules/core/attachments/src/AttachmentService.php` + `FileStorage.php` —
  upload flow; sem přibude copy metoda (krok 4).
- `src/Api/Router.php` — registrace prefixových větví; přibude
  `/_registry/` (krok 5).
- `src/Api/Controller/MailController.php` — vzor controlleru (auth,
  Response, chybové kódy). **Pozor:** `PersonsRegistryController` je ARES
  (obchodní rejstřík) a s modulem `base.registry` nesouvisí — nový
  controller se jmenuje `RegistryController`.
- `frontend/src/components/viewer/Viewer.svelte` — obsluha toolbar akcí
  (handler `actionId === 'reanalyze'` jako vzor) a otevírání FormDialog.
- Spodní taby vieweru: `tasks/viewer-number-series-tabs.md` + implementace
  ve viewerech dokladů (`modules/docs/invoicesOut`) — vzor pro taby šanonů.
- `docs/doc-states.md` — reuse `core.system.docStatesArchive` beze změn.

## Scope

**V rozsahu:**

- modul `base.registry` s tabulkami `base_registry_binders` (427)
  a `base_registry_documents` (428), cfgItemy `base.registry.docKinds`
  a `base.registry.sourceKinds` — přesně dle designu §4–§5
- `install.base` dependency
- Document třídy: `BinderDocument`, `RegistryDocumentDocument`
  (promote sync metadata ↔ promoted sloupce)
- `RegistryDocumentsViewer` (5-slot, spodní taby šanonů, fulltext),
  `BindersViewer` (settings), `RegistryDocumentsForm`
- `AttachmentService::copyTo()` — kopie přílohy k jinému záznamu (D8)
- `FileFromMessageService` + `RegistryController` + routa
  `POST /_registry/from-message/{ndx}`
- toolbar akce „Zařadit do Spisovny" v detailu došlé zprávy + frontend
  handler + `api/registry.js`
- testy, per-modul dokumentace (README.md, tables/*.md)

**Mimo rozsah:**

- AI cesta (target `registry`, RegistryApplier, prompt) — Fáze 2
- sender rules / šum / digest — Fáze 3
- expirace přes alerts, `registry_search` MCP, plnění `extracted_text` —
  Fáze 4 (sloupec `extracted_text` v tabulce **je**, jen se neplní)
- migrace `wkf.docs` — samostatný task
- dynamický formulář metadat dle `docKinds.fields` — fáze 1 stačí
  generický JSON editor (design §11 bod 3)
- `tasks/README.md` neaktualizuj

## Doporučené pořadí

### Krok 0 — prerekvizity

Změny `.jsonc` (tabulky, cfgItemy, module.jsonc) vyžadují **rebuild
kompilované konfigurace a `ds-upgrade`** dřív, než na ně sáhne kód.
Každý krok, který mění schema/cfg, končí `ds-upgrade` na test DS.

### Krok 1 — modul, tabulky, cfgItemy

Soubory:

- `modules/base/registry/module.jsonc` — id `base.registry`; dependencies
  `core.system`, `core.attachments`, `base.persons`, `core.mail`; tables;
  viewers: `base.registry.documents` (name:cs „Spisovna", icon `folder`,
  `navSection: "_top"`, `navOrder: 35`) a `base.registry.binders`
  (name:cs „Šanony", bez navSection); settingsItems: binders viewer
  (`section: "other"`, nová subsekce `other.registry`); forms;
  documentClasses; config (docKinds, sourceKinds). **Žádný `keepOnReset`**
  (design §3 — šanony i dokumenty jsou migrovaná data).
- `modules/base/registry/tables/base_registry_binders.jsonc` + `.md` —
  dle designu §5.1 (tableId 427).
- `modules/base/registry/tables/base_registry_documents.jsonc` + `.md` —
  dle designu §5.2 (tableId 428, vč. fulltext indexů `ft_head`/`ft_text`
  a sloupce `extracted_text`).
- `modules/base/registry/config/docKinds.jsonc` — dle designu §4.1
  (6 druhů: contract, insurance, quotation, certificate, official, other;
  `fields`/`promote`/`expiration`).
- `modules/base/registry/config/sourceKinds.jsonc` — dle designu §4.2
  (manual, mail, import).
- `modules/base/registry/README.md` — stručný přehled + odkaz na design.
- `modules/install/base/module.jsonc` — přidat `base.registry` do
  dependencies.

Validace: `ds-upgrade` projde na čistém i existujícím DS, tabulky vzniknou,
viewer „Spisovna" je v navigaci.

### Krok 2 — Document třídy

- `src/BinderDocument.php`:
  - `validate`: `name` povinné; unikátnost názvu mezi živými šanony
    (`docState != 90`), vzor `is_own` v `PersonDocument`.
  - `beforeSave`: `created` u nového záznamu.
- `src/RegistryDocumentDocument.php`:
  - `validate`: `title` a `doc_kind` povinné; `doc_kind` musí existovat
    v `base.registry.docKinds`; je-li vyplněno obojí,
    `valid_from <= valid_to`; `metadata` (pokud přišla jako string) musí
    být validní JSON.
  - `beforeSave`: audit (`created`/`created_by` u nového, `modified` vždy)
    a **promote sync** dle `docKinds[doc_kind].promote` — deterministicky,
    pro každý pár `metaKey → column`:
    1. pokud se promoted sloupec změnil proti `originalData` (dirty) →
       `metadata[metaKey] = column` (formulář má přednost);
    2. jinak pokud `metadata[metaKey]` je neprázdné →
       `column = metadata[metaKey]` (cesta pro import/AI, kde je metadata
       zdroj).
  - Registrace obou v `module.jsonc` `documentClasses`.

### Krok 3 — viewer + formulář

- `src/RegistryDocumentsViewer.php` — dle designu §6.2:
  - řádek: `t1` title, `i1` `valid_to` relativně (+ warning badge při
    blízké expiraci — čistě prezentace, žádný alert), `t2` partner
    (fallback `ref_number`), `i2` badge druhu (label + barva z mapy),
    `t3` `[šanon]` + první řádek `ai_summary`;
  - spodní taby: Vše / per živý šanon / Nezařazené (`binder IS NULL`) —
    vzor tabů číselných řad;
  - fulltext: `title`, `ref_number`, `ai_summary`;
  - detail taby: Obsah (title, druh, šanon, partner, platnosti, metadata
    read-only dle druhu) / Přílohy / Původ (odkaz na zdrojovou zprávu,
    `source_kind`).
- `src/BindersViewer.php` — jednoduchý settings viewer (vzor
  `MailboxesViewer`): name, icon, order_pos, počet dokumentů.
- `src/RegistryDocumentsForm.php` — dle designu §6.3: tab **Dokument**
  (vlevo title, druh — trigger `recalculate` kvůli promote polím, šanon,
  partner, ref_number, valid_from/valid_to, notice; vpravo `attachmentsView`
  read-only náhledy), tab **Přílohy** (`AttachmentPanel`, tableId 428),
  tab **Metadata** (generický JSON editor / textarea).
- i18n klíče `cs.js`/`en.js` dle potřeby frontendu.

### Krok 4 — kopie přílohy (`core.attachments`)

- `modules/core/attachments/src/AttachmentService.php` — nová metoda
  `copyTo(int $attachmentId, int $targetTableId, int $targetRecordId,
  ?int $userId): DocumentResult`:
  - načte zdrojový záznam (i pro cizí tabulku), **fyzicky zkopíruje
    soubor** do dnešního adresáře cílové tabulky přes `FileStorage`
    (nový 5-char hash v názvu), založí nový řádek
    `core_attachments_files` (`name` zachovat, `checksum`/`metadata`/
    `mime_type` převzít, `att_order` zachovat, `created_by = $userId`);
  - zdroj se nemění (D8 — kopie, ne přesun);
  - selhání kopie souboru → žádný DB zápis (orphan cleanup).
- Aktualizace `docs/attachments.md` (nová metoda v §8) +
  `modules/core/attachments` testů.

### Krok 5 — FileFromMessageService, controller, routa

- `modules/base/registry/src/FileFromMessageService.php` —
  `fileFromMessage(int $messageNdx, ?int $userId): array` dle designu §6.4:
  1. validace: zpráva existuje a `docState != 90`, jinak chybový výsledek
     (`NOT_FOUND` 404 / `INVALID_STATE` 409);
  2. partner prefill: match `sender_email` na osobu (přes kontakty
     `base.persons`); použít **jen při právě jednom** živém matchi;
  3. vytvoření dokumentu přes `TableGateway::saveDocument`
     (`docState=10` Koncept, `title` = subject, `doc_kind='other'`,
     `source_kind='mail'`, `source_message`, `partner?`);
  4. kopie obsahových příloh zprávy (výběr shodný s
     `IncomingMessagesViewer::fetchContentAttachments()` — bez raw `.eml`,
     bez smazaných) přes `AttachmentService::copyTo` na tableId 428;
  5. dedupe: existuje-li k **jinému živému** dokumentu Spisovny příloha se
     stejným `checksum`, přidej do výsledku warning
     `DUPLICATE_IN_REGISTRY` (+ ndx existujícího dokumentu) — neblokuje;
  6. zpráva: `target_table_id='base_registry_documents'`,
     `target_row = id`, a je-li `docState ∈ {10, 30}` → přechod na 40
     (Hotovo) **docState-only save přes Document flow** (žádný přímý
     UPDATE) — rozhodnutí design §11 bod 1: hned při vzniku Konceptu;
  7. atomicita: DB kroky v transakci; zkopírované soubory při rollbacku
     uklidit (vzor mail ingest, `docs/.../documentation.md` §9.4).
- `src/Api/Controller/RegistryController.php` —
  `POST /api/v1/_registry/from-message/{ndx}`, Bearer user token; odpovědi
  `200 {id, warning?}`, `404 NOT_FOUND`, `409 INVALID_STATE`,
  `500 INTERNAL_ERROR`. Tenká slupka nad službou (vzor
  `ExtractedDocumentApplier` vs. controller).
- `src/Api/Router.php` — nová větev `str_starts_with($subpath, '/_registry/')`.

### Krok 6 — frontend (toolbar akce)

- `modules/core/mail/src/IncomingMessagesViewer.php::getToolbarActions` —
  akce `fileToRegistry` (label z `core.mail.viewerDefaults`
  `toolbarActions.fileToRegistry`, doplnit do
  `modules/core/mail/config/viewerDefaults.jsonc`; name:cs „Zařadit do
  Spisovny"), viditelná pro `docState != 90`, `meta.messageNdx`.
- `frontend/src/api/registry.js` — `fileFromMessage(messageNdx)`.
- `frontend/src/components/viewer/Viewer.svelte` — handler
  `actionId === 'fileToRegistry'`: POST → při úspěchu otevřít `FormDialog`
  nad `base_registry_documents` s vráceným `id`; warning
  `DUPLICATE_IN_REGISTRY` zobrazit nenápadně (banner/toast v dialogu);
  po zavření dialogu refetch vieweru (zpráva mezitím přešla do Hotovo).
- i18n (`dashboard`/`viewer` sekce dle konvence, cs + en).

### Krok 7 — testy a finalizace

Celý test run úzkými `--filter` (viz Testy), kontrola dokumentace modulu.

## Testy

- **Unit:**
  - `BinderDocument` — povinný název; unikátnost mezi živými; koš
    neblokuje reuse názvu.
  - `RegistryDocumentDocument` — validace (title/doc_kind, neznámý druh,
    valid_from > valid_to, nevalidní JSON metadata); promote sync: form →
    metadata (dirty promoted), metadata → sloupce (import cesta), priorita
    dirty formu, druh bez promote mapy je no-op.
  - `AttachmentService::copyTo` — nový fyzický soubor (jiná cesta/hash),
    shodný checksum, zdroj nedotčen, rollback při selhání kopie.
- **API/integrační:**
  - `POST /_registry/from-message/{ndx}` happy path: vznikl Koncept
    s prefilly, přílohy zkopírované (počet + checksumy), zpráva → 40
    + `target_*` nastaveny;
  - zpráva v Koši → 409; neexistující → 404;
  - druhé zařazení téže zprávy → nový dokument + warning
    `DUPLICATE_IN_REGISTRY`;
  - zpráva bez obsahových příloh (jen raw `.eml`) → dokument bez příloh;
  - CRUD dokumentu vč. stavového automatu (10→40→80→40, 70, 90).
- PHPUnit spouštět úzkými `--filter` (Binder, RegistryDocument,
  AttachmentCopy, RegistryFromMessage), ne široké běhy.

## Commit strategie

Commit per krok: (1) modul + tabulky + cfg, (2) Document třídy + testy,
(3) viewer + form + i18n, (4) attachments copyTo, (5) služba + controller +
routa + API testy, (6) frontend akce. Kroky 5 a 6 lze spojit, pokud jsou
malé. Každý commit zanechává zelené testy a funkční `ds-upgrade`.

## Hotovo když

- [ ] `ds-upgrade` projde na čistém i existujícím DS; `install.base`
      obsahuje `base.registry`; viewer „Spisovna" je v navigaci
- [ ] CRUD šanonů v settings funguje; unikátnost názvu vynucena
- [ ] CRUD dokumentu přes UI vč. stavů; promote sync funguje v obou
      směrech s prioritou formuláře
- [ ] viewer: 5-slot layout, spodní taby šanonů + Nezařazené, fulltext,
      detail taby Obsah/Přílohy/Původ
- [ ] „Zařadit do Spisovny" z detailu zprávy: vznikne Koncept s kopiemi
      příloh, otevře se FormDialog, zpráva → Hotovo + polymorfní vazba
- [ ] opakované zařazení hlásí `DUPLICATE_IN_REGISTRY`, neblokuje
- [ ] `AttachmentService::copyTo` zdokumentovaná v `docs/attachments.md`
- [ ] testy zelené (všechny filtry z sekce Testy)
- [ ] per-modul dokumentace (`README.md`, `tables/*.md`) odpovídá
      implementaci
