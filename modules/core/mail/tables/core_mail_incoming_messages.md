# Tabulka: Došlé zprávy (core_mail_incoming_messages)

Hlavní tabulka evidence došlé pošty. Jednotkou je zpráva doručená do konkrétní
schránky (`mailbox`). Z jedné zprávy může časem vzniknout business entita (např.
přijatá faktura) — vazba je polymorfní přes `target_table_id` + `target_row`.

**Zpráva ≠ dokument.** Zpráva je pouze transportní jednotka; business entity
žijí v jiných tabulkách a zpráva na ně jen odkazuje.

## Struktura

Sloupce jsou organizovány do skupin:

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message_id` | varchar(30), NOT NULL, UNIQUE | Lidský kód zprávy (`MSG-YYYYMMDD-NNNN`) — generovaný v `IncomingMessageDocument::beforeSave()` |
| `mailbox` | int → `core_mail_mailboxes`, NOT NULL | Schránka, do které zpráva dorazila |
| `primary_type` | enumString(30), NOT NULL | Předpokládaný druh dokumentu — viz [primaryTypes.jsonc](../config/primaryTypes.jsonc). Default `other`. |

### Hlavičky (headers)

| Sloupec | Typ | Popis |
|---|---|---|
| `subject` | text, NOT NULL | Předmět zprávy |
| `sender_email` | varchar(200), NOT NULL | E-mailová adresa odesílatele |
| `sender_name` | varchar(200) | Display name odesílatele (z hlavičky `From`) |
| `sender_person` | int → `base_persons_persons` | Matching na osobu v systému (NULL ve Fázi 1, naplňuje Fáze 3) |
| `received_at` | datetime, NOT NULL | Čas doručení — u manuálu `= created` |
| `external_message_id` | varchar(255) | RFC822 `Message-ID` (pro budoucí deduplikaci) |
| `in_reply_to` | varchar(255) | Hlavička `In-Reply-To` (ukládáme, ve Fázi 1 nevyužíváme) |
| `reply_references` | text | Hlavička `References` (ukládáme, ve Fázi 1 nevyužíváme). Pojmenováno se suffixem `reply_`, protože `references` je rezervované slovo SQL. |

### Tělo (body)

| Sloupec | Typ | Popis |
|---|---|---|
| `body_plain` | longtext | Tělo zprávy v prostém textu |
| `body_html` | longtext | Tělo zprávy v HTML (když je k dispozici) |
| `raw_source_attachment` | int → `core_attachments_files` | Odkaz na přílohu typu `.eml` (originál). Nezobrazuje se v panelu "obsahových" příloh — přístup přes samostatný tab "Originál". |

### Směrování (routing)

| Sloupec | Typ | Popis |
|---|---|---|
| `target_table_id` | varchar(100) | Jméno cílové tabulky (např. `economy_docs_issued_invoices_received`) pro business entitu, která ze zprávy vznikla |
| `target_row` | int | ID záznamu v cílové tabulce |

Pár (`target_table_id`, `target_row`) je polymorfní FK — aplikační kód ho kontroluje, DB úroveň nikoli (Shipard nepoužívá FOREIGN KEY).

### Zdroj (source)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_type` | tinyint, default 1 | Způsob vzniku zprávy: `1` = manuální pořízení, `2` = e-mail (mail-router), `3` = API, `4` = scan. Ve Fázi 1 jsou všechny testovací i ručně pořízené zprávy `source_type = 1`. |

### AI analýza (ai)

| Sloupec | Typ | Popis |
|---|---|---|
| `ai_analysis_enabled` | boolean (nullable) | Override per zpráva. `NULL` = zděděno z DS-default (zpracovat); `true`/`false` = explicitní přepis. |
| `needs_reanalysis` | boolean, default false | Příznak nastavený akcí "Znova analyzovat" — zapne se po reanalyze hooku, vypne se při dalším úspěšném `result`. |
| `profile_override` | int → `core_mail_ai_profiles` | Pro ad-hoc znovu-analýzu s jiným profilem. NULL = použít default profil DS. |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime | Čas založení záznamu |
| `created_by` | int → `core_system_users` | Uživatel, který záznam vytvořil |
| `modified` | datetime | Čas poslední změny |
| `docState` | tinyint (system) | Stav zprávy — viz [docStatesIncoming.jsonc](../config/docStatesIncoming.jsonc) |
| `docStateMain` | tinyint (system) | Řazení podle stavu |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_message_id` | unique | `message_id` | Lidský kód zprávy je unikátní |
| `idx_mailbox` | index | `mailbox` | Filtrace dle schránky |
| `idx_received_at` | index | `received_at` DESC | Chronologický výpis, newest-first |
| `idx_external_message_id` | index | `external_message_id` | Pro budoucí deduplikaci dle RFC822 Message-ID |
| `idx_doc_state` | index | `docStateMain` ASC, `received_at` DESC | Řazení ve vieweru |
| `idx_target` | index | `target_table_id`, `target_row` | Zpětné vyhledávání zpráv ze kterých vznikla entita |
| `idx_sender_email` | index | `sender_email` | Filtr dle odesílatele |
| `idx_sender_person` | index | `sender_person` | Filtr dle osoby v systému |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_mailboxes](core_mail_mailboxes.md) | `messages.mailbox` → `mailboxes.id` | Patří do schránky |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `analyses.message` → `messages.id` | Historie AI analýz (1:N) |
| `core_attachments_files` | `messages.raw_source_attachment` → `attachments.id` | Originál `.eml` |
| `core_attachments_files` | přes `table_id + record_id` | Obsahové přílohy zprávy |
| `base_persons_persons` | `messages.sender_person` → `persons.id` | Odesílatel v CRM |

## Workflow (Fáze 1)

1. **Manuální pořízení** — uživatel otevře `IncomingMessageEditor`, vyplní hlavičky a tělo, drag & drop přílohy. `source_type = 1`, `docState = 10` (Nová).
2. **Zařazení do analýzy** (Fáze 3) — `docState` přechází na 20 (V analýze), externí AI služba po dokončení na 30 (Analyzovaná) nebo zpět na 10 (failed).
3. **Zpracování** — ze zprávy vznikne business entita, `target_table_id` a `target_row` se naplní, `docState` → 40.
4. **Archivace / smazání** — `docState` → 80 (archiv) nebo 90 (trash).
