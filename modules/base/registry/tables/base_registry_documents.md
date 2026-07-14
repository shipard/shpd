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
- **`extracted_text`** se ve fázi 1 neplní — sloupec + fulltext index jsou
  připraveny pro AI cestu (fáze 2).

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
| `extracted_text` | MEDIUMTEXT NULL, system | Extrahovaný text příloh — fáze 1 neplní |

### Původ (source)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_kind` | CHAR(20) ascii NOT NULL, default `manual` | cfgItem `base.registry.sourceKinds` |
| `source_message` | INT NULL → `core_mail_incoming_messages` | Zdrojová zpráva při zařazení z pošty |
| `extracted_doc` | INT NULL → `core_mail_extracted_documents`, system | Extrakce (AI cesta, fáze 2) |

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
| `idx_valid_to` | index | valid_to, docStateMain | Expirace (alerts, fáze 4) |
| `ft_head` | fulltext | title, ref_number, ai_summary | Fulltext vieweru |
| `ft_text` | fulltext | extracted_text | Fulltext obsahu (fáze 2+) |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [`base_registry_binders`](base_registry_binders.md) | `binder` | Šanon |
| `base_persons_persons` | `partner` | Subjekt dokumentu |
| `core_mail_incoming_messages` | `source_message`; zpětně `target_table_id='base_registry_documents'` + `target_row` | Zařazení z došlé pošty |
| `core_mail_extracted_documents` | `extracted_doc` | AI extrakce (fáze 2) |
| `core_attachments_files` | `table_id=428, record_id=id` | Přílohy (kopie souborů, D8) |
| `core_system_users` | `created_by` | Audit |

## Mazání a reset

Bez `keepOnReset` — dokumenty jsou migrovaná data (starý `wkf.docs`,
dedupe `metadata.legacyId`). Mazání jen do koše (docState 90).
