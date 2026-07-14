# Modul `base.registry` — Spisovna

Evidence trvalých dokumentů firmy — smlouvy, pojistky, revizní zprávy,
cenové nabídky, úřední písemnosti. Dokumenty se organizují do **šanonů**
(uživatelská osa) a klasifikují **druhem** (`docKinds`, systémová osa
řídící metadata a expiraci).

> Autoritativní design MVP (fáze, AI cesta, migrace ze starého `wkf.docs`):
> [`docs/registry-mvp.md`](../../../docs/registry-mvp.md). Po dokončení MVP
> se obsah designu přesune sem.

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

## Navigace

- Viewer **Spisovna** (`base.registry.documents`) — root-level položka
  hlavní navigace (`navSection: "_top"`), hned za Došlou poštou.
- Viewer **Šanony** (`base.registry.binders`) — v Nastavení aplikace,
  sekce Ostatní → Spisovna.

## Reset

Modul nemá `keepOnReset` — šanony i dokumenty jsou migrovaná data ze
starého `wkf.docs`; po `ds-reset` je obnoví re-import (dedupe přes
`metadata.legacyId`).
