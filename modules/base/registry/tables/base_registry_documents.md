# Tabulka: Dokumenty spisovny (base_registry_documents)

Jeden záznam = jeden trvalý dokument firmy (smlouva, pojistka, revize,
nabídka, úřední písemnost) s metadaty a přílohami. Dokument ≠ doklad —
žádná číselná řada ani účetní důsledky.

Klíčové principy (design `docs/registry-mvp.md`):

- **`metadata` je zdroj pravdy** druhově specifických polí (klíče dle
  `docKinds[doc_kind].fields`). `RegistryDocumentDocument::beforeSave`
  synchronizuje promoted sloupce (`ref_number`, `valid_from`, `valid_to`)
  dle `docKinds.promote` — v obou směrech, priorita má dirty hodnota
  z formuláře.
- **Přílohy** přes `core.attachments` (`table_id = 428`); při zařazení
  z pošty se soubory **kopírují** (D8), zdrojová zpráva zůstává netknutá.
  Záznam dostává **všechny obsahové přílohy zprávy** (jedno doručení =
  jeden záznam, D5 z `tasks/mail-message-centric.md`).
- **`extracted_text`** plní `ExtractedTextFiller` (zařazení, endpoint
  extract-text, CLI backfill) — přímým UPDATE mimo Document hooky, aby
  nebumpnul `modified` (unapply guard AI cesty). Viz README modulu.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `id` | INT PK | |
| `title` | VARCHAR(250) NOT NULL | Název dokumentu |
| `doc_kind` | CHAR(30) ascii NOT NULL, default `other` | Druh — cfgItem `base.registry.docKinds` |
| `binder` | INT NULL → [`base_registry_binders`](base_registry_binders.md) | Šanon; NULL = Nezařazené |
| `ref_number` | VARCHAR(100) NULL | Číslo / značka (promoted z metadata) |

### Subjekt (subject)

| Sloupec | Typ | Popis |
|---|---|---|
| `partner` | INT NULL → `base_persons_persons` | Protistrana / subjekt dokumentu |

### Platnost (validity)

| Sloupec | Typ | Popis |
|---|---|---|
| `valid_from` | DATE NULL | Platnost od (promoted z metadata) |
| `valid_to` | DATE NULL | Platnost do / lhůta (promoted); sémantika: datum, po kterém dokument přestává být v pořádku bez zásahu |

### Obsah (content)

| Sloupec | Typ | Popis |
|---|---|---|
| `ai_summary` | TEXT NULL | Shrnutí (AI, fáze 2; při ručním pořízení prázdné) |
| `metadata` | JSON NULL | Druhově specifická pole dle `docKinds.fields` |
| `extracted_text` | MEDIUMTEXT NULL, system | Extrahovaný text příloh (`ExtractedTextFiller`, cap 500k) |

### Původ (source)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | CHAR(20) ascii NOT NULL, default `manual` | cfgItem `base.registry.sourceKinds` |
| `source_message` | INT NULL → `core_mail_incoming_messages` | Zdrojová zpráva při zařazení z pošty (ruční i AI apply) |

### Poznámky (notes) + systém

| Sloupec | Typ | Popis |
|---|---|---|
| `notice` | TEXT NULL | Poznámka |
| `docState` / `docStateMain` | TINYINT | `core.system.docStatesArchive` |
| `created` | DATETIME NOT NULL | Audit |
| `created_by` | INT NULL → `core_system_users` | Audit |
| `modified` | DATETIME NULL | Audit |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_binder` | index | binder, docStateMain | Spodní taby vieweru (per šanon, živé) |
| `idx_kind` | index | doc_kind | Filtr dle druhu |
| `idx_partner` | index | partner | Dokumenty partnera |
| `idx_valid_to` | index | valid_to, docStateMain | Expirace (`base.registry.expirations`) |
| `ft_head` | fulltext | title, ref_number, ai_summary | Fulltext vieweru + `registry_search` |
| `ft_text` | fulltext | extracted_text | Fulltext obsahu příloh (viewer + `registry_search`) |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [`base_registry_binders`](base_registry_binders.md) | `binder` | Šanon |
| `base_persons_persons` | `partner` | Subjekt dokumentu |
| `core_mail_incoming_messages` | `source_message`; zpětně `target_table_id='base_registry_documents'` + `target_row` | Zařazení z došlé pošty (obousměrná lineage; AI cesta = apply návrhu přes `RegistryApplier`) |
| `core_attachments_files` | `table_id=428, record_id=id` | Přílohy (kopie souborů, D8) |
| `core_system_users` | `created_by` | Audit |

## Mazání a reset

Bez `keepOnReset` — dokumenty jsou migrovaná data (starý `wkf.docs`,
dedupe `metadata.legacyId`). Mazání jen do koše (docState 90).
