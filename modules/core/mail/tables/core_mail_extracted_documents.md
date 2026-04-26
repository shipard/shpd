# Tabulka: Extrahované dokumenty (core_mail_extracted_documents)

Kandidáti na business entity, které AI našla v došlé zprávě (např. "v této
e-mailové zprávě je přiložená přijatá faktura č. X"). Vztah 1:N na
[core_mail_incoming_messages](core_mail_incoming_messages.md) a 1:N na
[core_mail_message_analyses](core_mail_message_analyses.md) — jeden běh
analýzy může najít více dokumentů, jeden dokument patří právě k jednomu běhu.

V MVP zůstává jen extrakce — `extracted_json` drží strukturovaný obsah, ale
faktura/dobropis/atd. v cílových tabulkách ještě nevzniká. Aplikace na
cílovou entitu přijde v Fázi 3c (úkol mail-phase3c).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message` | int → `core_mail_incoming_messages`, NOT NULL | Zdrojová zpráva (CASCADE delete) |
| `analysis` | int → `core_mail_message_analyses`, NOT NULL | Konkrétní běh analýzy, který dokument extrahoval |
| `doc_type` | enumString(30), NOT NULL | Typ dokumentu — viz [extractedDocTypes.jsonc](../config/extractedDocTypes.jsonc) |

### Extrakce (extraction)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_attachments` | text | JSON pole `[ndx, ndx, …]` — přílohy, ze kterých dokument vznikl |
| `extracted_json` | longtext | Strukturovaný obsah dokumentu (faktura, položky, částky, …) |
| `confidence` | numeric(4,3) | 0.000–1.000 — celková jistota extrakce |

### Kontrola (review)

| Sloupec | Typ | Popis |
|---|---|---|
| `status` | enumInt, default 20 | Stav kontroly — viz [extractedDocStates.jsonc](../config/extractedDocStates.jsonc) |
| `rejected_reason` | text | Důvod zamítnutí (povinný při `status = rejected`) |

### Cílová entita (target)

| Sloupec | Typ | Popis |
|---|---|---|
| `target_table_id` | varchar(100) | Tabulka, do které byl dokument aplikován |
| `target_row_ndx` | int | Konkrétní záznam v cílové tabulce |
| `applied_at` | datetime | Čas aplikace |
| `applied_by` | int → `core_system_users` | Uživatel, který akci provedl |

### Návaznosti (lineage)

| Sloupec | Typ | Popis |
|---|---|---|
| `superseded_by` | int → `core_mail_extracted_documents` | Při znovu-analýze starý dokument odkazuje na nový |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření |
| `created_by` | int → `core_system_users` | Vytvořil (typicky `_ai_analyzer`) |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_message` | index | `message` | Rychlý dotaz na sourozence při auto-transition 30→40 |
| `idx_analysis` | index | `analysis` | List dokumentů jednoho běhu analýzy |
| `idx_status` | index | `status` | Globální dashboardy (kolik čeká na review) |
| `idx_message_status` | index | `message`, `status` | Detail panel zprávy filtruje podle stavu |

## Stavy (status)

Mapování viz [extractedDocStates.jsonc](../config/extractedDocStates.jsonc).

| Kód | ID | Význam |
|---|---|---|
| 10 | `ready_to_apply` | Confidence ≥ 0.9 — UI nabízí jen "Použít" |
| 20 | `pending_review` | 0.6 ≤ confidence < 0.9 — default po extrakci |
| 30 | `low_confidence` | Confidence < 0.6 — vyžaduje pečlivý review |
| 40 | `applied` | Uživatel potvrdil, entita vznikla (Fáze 3c) |
| 50 | `rejected` | Uživatel zamítl jako false positive |
| 60 | `superseded` | Nahrazen novou analýzou |
| 70 | `ai_failed` | AI nemohla extrahovat (např. nečitelné PDF) |

## Hooky

`ExtractedDocumentDocument::afterSave()` — když se status mění na `applied`,
`rejected` nebo `superseded` a žádný sourozenec není ve stavu
`ready_to_apply`, `pending_review` ani `low_confidence`, automaticky přepne
zprávu z `docState=30` (Analyzovaná) na `docState=40` (Zpracovaná).
Stav `ai_failed` přechodu nebrání. Hook běží ve stejné transakci.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `extracted_documents.message` → `incoming_messages.id` | Zdrojová zpráva |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `extracted_documents.analysis` → `message_analyses.id` | Konkrétní běh |
| sebe | `extracted_documents.superseded_by` → `extracted_documents.id` | Lineage při znovu-analýze |

## Mazání

CASCADE delete při smazání zdrojové zprávy — řeší
`IncomingMessageDocument::beforeDelete`.
