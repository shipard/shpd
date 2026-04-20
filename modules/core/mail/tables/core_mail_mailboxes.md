# Tabulka: Schránky (core_mail_mailboxes)

Konfigurované e-mailové schránky data sourcu. Jedna DS může mít N schránek pro různé
účely — faktury, obecný kontakt, podpora atd. Schránka nese konfiguraci (e-mailová
adresa, výchozí primární typ příchozích zpráv) a přes ni jsou navázány došlé zprávy
(`core_mail_incoming_messages.mailbox`).

## Struktura

Sloupce jsou organizovány do skupin:

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `mailbox_id` | varchar(50), NOT NULL, UNIQUE | Lidský identifikátor schránky (`invoices`, `info`, …) |
| `name` | varchar(100), NOT NULL | Zobrazovaný název schránky v UI |
| `email_address` | varchar(200), NOT NULL, UNIQUE | E-mailová adresa schránky (unikátní per DS) |
| `description` | text | Volitelný popis / poznámky k použití schránky |

### Konfigurace (config)

| Sloupec | Typ | Popis |
|---|---|---|
| `default_primary_type` | enumString(30) | Výchozí primární typ nově doručených zpráv — viz [primaryTypes.jsonc](../config/primaryTypes.jsonc). Nullable, při prázdné hodnotě se použije `other`. |
| `is_default` | boolean | Příznak výchozí schránky DS. Došlá pošta bez explicitního `mailbox` pole půjde do ní. Smí být `true` pro nejvýš jednu schránku per DS (vynuceno aplikačně v `MailboxDocument::validate`). |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime | Čas založení záznamu |
| `created_by` | int → `core_system_users` | Uživatel, který záznam vytvořil |
| `modified` | datetime | Čas poslední změny |
| `docState` | tinyint (system) | Stav dokumentu — viz [core.system.docStatesArchive](../../system/config/docStatesArchive.jsonc) |
| `docStateMain` | tinyint (system) | Řazení podle stavu (nastavováno automaticky) |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_mailbox_id` | unique | `mailbox_id` | Lidský kód schránky musí být v DS unikátní |
| `unq_email_address` | unique | `email_address` | E-mailová adresa je unikátní per DS |
| `idx_doc_state` | index | `docStateMain` ASC, `name` ASC | Řazení pro viewer |
| `idx_is_default` | index | `is_default` | Rychlé vyhledání výchozí schránky při příjmu došlé pošty |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `incoming_messages.mailbox` → `mailboxes.id` | Došlé zprávy patřící schránce |

## Mazání

Schránku nelze smazat, pokud na ni odkazují existující došlé zprávy
(referenční integrita kontrolovaná aplikačně — Shipard nepoužívá FOREIGN KEY).
Místo toho se schránka přepne do `docState = 70` (Archiv).
