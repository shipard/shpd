# Modul `base.registry` — Spisovna

Evidence trvalých dokumentů firmy — smlouvy, pojistky, revizní zprávy,
cenové nabídky, úřední písemnosti. Dokumenty se organizují do **šanonů**
(uživatelská osa) a klasifikují **druhem** (`docKinds`, systémová osa
řídící metadata a expiraci).

> MVP (fáze 1–4) je hotové — **tento README je provozní pravda modulu**.
> Původní design s rozhodnutími D1–D10 a fázováním zůstává jako design
> record v [`docs/registry-mvp.md`](../../../docs/registry-mvp.md).
> Zbývá samostatný task migrace starého `wkf.docs`.

## Koncept

- **Dispozice** (D1): každá došlá zpráva končí v právě jedné dispozici
  (doklad / dokument Spisovny / archiv) — pošta tenduje k prázdnu.
- **Dvě osy** (D2): `doc_kind` je systémový slovník (řídí metadata, AI
  extrakci a expirace), **šanon** je uživatelská organizace (ploché
  složky). Subjekt jen přes `partner`.

## Tabulky

| Tabulka | tableId | Obsah |
|---|---|---|
| [`base_registry_binders`](tables/base_registry_binders.md) | 427 | Šanony — ploché organizační složky |
| [`base_registry_documents`](tables/base_registry_documents.md) | 428 | Dokumenty Spisovny |

## cfgItemy

- **`base.registry.docKinds`** — řízený slovník druhů dokumentů. Per druh:
  `fields` (klíče v `metadata` JSON = přesné názvy polí AI output schématu),
  `promote` (mapování vybraných polí na promoted sloupce `ref_number` /
  `valid_from` / `valid_to`), `expiration.warnDaysBefore` (sémantika
  expirace nad promoted `valid_to`).
- **`base.registry.sourceKinds`** — zdroj dokumentu (`manual`, `mail`,
  `import`; budoucí `databox`, `scan`).
- **`base.registry.viewerDetailLabels`** — labely detail tabů vieweru
  (Obsah / Přílohy / Původ) a sentinelů spodních tabů šanonů
  (Vše / Nezařazené).

## Doc states

Obě tabulky používají standardní archivační sadu
`core.system.docStatesArchive` (10 Koncept, 80 V opravě, 40 V pořádku =
Zařazeno, 70 V archívu = ukončená platnost, 90 Koš). Žádný vlastní cfgItem
stavů.

## Zařazení z došlé pošty — ruční cesta

`POST /api/v1/_registry/from-message/{ndx}` (Bearer) —
`FileFromMessageService` vytvoří Koncept dokumentu (title = subject,
`doc_kind='other'`, `source_kind='mail'`, partner dle jednoznačného matche
`sender_email` přes sdílený `PartnerEmailMatcher`), zkopíruje obsahové
přílohy zprávy přes `AttachmentService::copyTo` (D8 — kopie, ne přesun),
best-effort naplní `extracted_text` (`ExtractedTextFiller` → `TextExtractor`
z core.attachments, pdftotext) a zprávě nastaví `target_*` + přechod
10/20 → 40 (Hotovo). Shodný checksum přílohy u jiného živého dokumentu →
non-fatal warning `DUPLICATE_IN_REGISTRY`. UI vstup: toolbar akce
„Zařadit do Spisovny" v detailu zprávy.

## AI cesta (fáze 2) — target `registry`

Analyzer (profil v3.0.0) klasifikuje a extrahuje registry typy (`contract`,
`insurance`, `quotation`, `certificate`, `official` — cfgItem
`core.mail.extractedDocTypes` s `target: "registry"` + `docKind`) do
kontraktu **`shpd.registry.document.v1`**
([`schemas/shpd.registry.document.v1.json`](schemas/shpd.registry.document.v1.json);
`kindFields` = přesně `docKinds.fields`, drift hlídá
`RegistrySchemaDriftTest`). Návrhy jdou stávajícím review workflow
`core_mail_extracted_documents` (D5) — dashboard karta „{druh} —
{protistrana}" s akcí **Zařadit**, review preview
(`RegistryExtractedPreview`, bez resolve panelu) a undo.

Apply deleguje `ExtractedDocumentApplier` (core.mail, mapa target
applierů) na **`RegistryApplier`**:

- dokument vzniká rovnou v **40 (Zařazeno)**; `metadata` = `kindFields`
  1:1, promoted sloupce doplní `RegistryDocumentDocument::beforeSave`,
  `ai_summary` = `summary`;
- **partner**: `PartyResolver` (core.exchange, jen `Matched`) → e-mail
  odesílatele (`PartnerEmailMatcher`) → NULL — nikdy nezakládá osoby;
- **šanon**: historie (nejčastější šanon živých dokumentů partner+druh) →
  `binderSuggestion` case-insensitive na živé šanony → NULL — nikdy
  nezakládá šanony;
- kopie příloh dle `source_attachments` (fallback obsahové přílohy
  zprávy) s orphan cleanupem; `target_table_id`/`target_row_ndx` na
  extracted řádek v téže transakci; `extracted_text` best-effort po
  commitu.

**Unapply** (undo z dashboardu): guard — dokument stále ve 40 a
`modified <= applied_at` (jinak `DOC_ADVANCED` 409) → dokument do Koše
(90), extracted zpět na 20, zpráva reverz; přílohy se nemažou.

## Fulltext (`extracted_text`)

Sloupec `extracted_text` (mediumtext, system) drží text obsahových příloh
dokumentu — skládá ho **`ExtractedTextFiller`** (`TextExtractor`
z core.attachments, pdftotext; oddělovač prázdný řádek, cap 500 000 znaků,
pořadí dle `att_order`). Zápis jde **přímým UPDATE mimo Document hooky**
(nesmí bumpnout `modified` — na `modified <= applied_at` stojí unapply
guard AI cesty). Plní se:

- při zařazení (ruční cesta i `RegistryApplier`) — best-effort po commitu;
- **`POST /api/v1/_registry/documents/{id}/extract-text`** — přegeneruje
  z aktuálních příloh, vrací `{chars, attachments}`; bez příloh text
  vyčistí; 404 pro neexistující dokument nebo Koš. Frontend ho volá po
  uploadu/smazání přílohy ve formuláři (generický `changeEndpoint` na
  attachments tabu — `FormTab` → `AttachmentPanel`, fire-and-forget);
- **`shpd-ds registry-extract-texts [--all] [--limit=N]`** — backfill
  živých dokumentů; default jen chybějící texty, `--all` přegeneruje vše.

Viewer hledá hlavičku přes LIKE (`title`, `ref_number`, `ai_summary` —
sloupce indexu `ft_head`) **plus** `MATCH (extracted_text) AGAINST` —
dokument se najde i podle obsahu PDF.

## Hlídání expirací — alert check

**`base.registry.expirations`** (`RegistryExpirationAlertCheck`, interval
6h, tags `registry`) hlídá dokumenty ve stavech **40 + 80** s `valid_to`
v horizontu `expiration.warnDaysBefore` svého druhu (`docKinds`; druh
s `expiration: null` se nehlídá). Koncepty (10) se nehlídají; přechod do
70/90 je legitimní umlčení alertu (Ukončení platnosti).

- **Severity:** po termínu → `error`; do `min(warnDaysBefore)` →
  `warning`; do `max(warnDaysBefore)` → `info`.
- **`finding_key` = `doc_{id}`** — stabilní napříč běhy i změnou severity
  (reconciler UPDATEuje); prodloužení `valid_to` nebo přechod do 70/90 →
  finding se nevrátí → alert se auto-resolvne.
- Akce `open_form` na dokument; karta se objeví v dashboard feedu přes
  `AlertsSource` (žádná dashboard práce navíc).
- `valid_to` má sémantiku „datum, po kterém dokument přestává být
  v pořádku bez zásahu" (u smluv konec platnosti, u úředních písemností
  deadline).

## MCP nástroj `registry_search`

Čtecí tier ([`src/Mcp/RegistrySearchTool.php`](src/Mcp/RegistrySearchTool.php),
registrace v `buildMcpRegistry()` v `public/index.php` s guardem na aktivní
modul). Otevírá Spisovnu internímu chatu:

- `query` — fulltext přes `ft_head` **i** `ft_text` (hledá v textu příloh);
- `doc_kind`, `binder_name` (case-insensitive match na živé šanony;
  nenalezený šanon → prázdný výsledek se srozumitelným summary), `partner`
  (ID osoby z `persons_search`), `valid_to_before`/`valid_to_after`,
  `expiring_within_days` (zkratka `valid_to <= dnes+N`; bez parametru se
  platnost nefiltruje), `state` (`filed` = 40 default | `active` | `all`);
- výstup: `ref {type: 'registry_document', id}`, `full_name`
  („{title} — {partner}"), druh + label z cfg, šanon, partner, platnosti,
  `expired` bool, `ai_summary` zkrácené na ~200 znaků, `state_label`;
  `limit` cap 50, `has_more` stránkování.

## Import ze starého Shipardu

**`POST /api/v1/_registry/import`** (`RegistryController::import` →
[`RegistryImportService`](src/RegistryImportService.php)) — programové
založení jednoho dokumentu z migračního runneru `wkf.docs`
(`docs/registry-mvp.md` §10, analogie `/_mail/import`). Auth: libovolný
api_key (typicky `_legacy_importer`).

- Payload `shpd.registry.document.v1` + import blok: `docKind` (klíč
  `docKinds`), `title`, `binder` (jméno), `notice`, `validFrom`/`validTo`,
  `docState` (10/40/70/80, default 40), `created` (ISO 8601, historické),
  povinný blok `legacy {ndx, id?, kind?, author?, folder?}`.
- **Zachovává historické `created`** (audit hook doplňuje jen prázdné)
  a zapisuje cílový `docState` přímo; `docStateMain` odvodí centrálně
  `TableGateway::saveDocument`.
- **Idempotence:** dedupe podle `metadata.legacyNdx` + `source_kind='import'`
  mimo Koš → `200 {id, existed: true}` beze změn.
- **Šanon** se resolvuje case-insensitive na živé šanony; nenalezený →
  `binder=NULL` + `warning: "BINDER_NOT_FOUND"`. Endpoint šanony nezakládá
  — zakládá je runner před dokumenty. Přílohy endpointem netečou (nahrává
  je attachments klient na tableId 428 po založení).
- Odpovědi: `201 {id}` / `200 {id, existed}` (+ `warning?`), 422 validace
  (`details[{field, code}]`).

## Navigace

- Viewer **Spisovna** (`base.registry.documents`) — root-level položka
  hlavní navigace (`navSection: "_top"`), hned za Došlou poštou.
- Viewer **Šanony** (`base.registry.binders`) — v Nastavení aplikace,
  sekce Ostatní → Spisovna.

## Reset

Modul nemá `keepOnReset` — šanony i dokumenty jsou migrovaná data ze
starého `wkf.docs`; po `ds-reset` je obnoví re-import (dedupe přes
`metadata.legacyNdx`).
