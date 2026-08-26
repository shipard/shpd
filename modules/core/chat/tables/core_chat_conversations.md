# Tabulka: Konverzace chatu (core_chat_conversations)

Konverzace vnitřního AI asistenta. **Per uživatel + DS** — uživatel vidí jen
vlastní konverzace (scoping přes sloupec `user` a DB zdroje dat). Mazání je
soft (`docState = 90`), řádek se nikdy fyzicky neodstraní.

Zprávy konverzace žijí v [core_chat_messages](core_chat_messages.md) a nejsou
zapisovatelné přes API — vznikají v orchestrační smyčce (viz
[docs/chat.md](../../../../docs/chat.md)).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `user` | int → `core_system_users`, NOT NULL | Vlastník konverzace |
| `title` | varchar(200), nullable | Název (typicky z první zprávy) |
| `section` | varchar(32), nullable | Scope na sekci navigace (id z `global.navSections`); volí se při založení, dál se nemění. `NULL` = bez scope |

### Backend

| Sloupec | Typ | Popis |
|---|---|---|
| `backend` | int → `core_ai_backends`, nullable | Zvolený LLM backend; `NULL` → použije se default aktivní |
| `model_snapshot` | varchar(100), nullable | Model použitý v konverzaci (audit) |

### Telemetrie (telemetry)

Agregát přes zprávy konverzace; plněno orchestrační smyčkou.

| Sloupec | Typ | Popis |
|---|---|---|
| `tokens_input` | int, NOT NULL, default 0 | Součet vstupních tokenů |
| `tokens_output` | int, NOT NULL, default 0 | Součet výstupních tokenů |
| `cost` | numeric(14,6), NOT NULL, default 0 | Náklady (zatím se neplní — viz `docs/ai.md`) |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas založení |
| `created_by` | int → `core_system_users`, nullable | Kdo založil |
| `modified` | datetime, NOT NULL | Čas poslední aktivity (řazení seznamu) |
| `docState` | tinyint (system), default 10 | Stav — `core.system.docStatesArchive` (10 aktivní, 90 smazaná) |
| `docStateMain` | tinyint (system), default 1 | Řazení podle stavu |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_user` | index | `user` | Seznam konverzací uživatele |
| `idx_doc_state` | index | `docState` | Filtr nesmazaných |

## Životní cyklus

1. **Založení** — `POST /_chat/conversations` vytvoří prázdnou konverzaci
   (`docState=10`), volitelně s `title`/`backend`/`section`.
2. **Provoz** — `POST /_chat/conversations/{id}/messages` přidává zprávy
   (smyčka), `modified` a telemetrie se průběžně aktualizují.
3. **Soft-delete** — `DELETE` nastaví `docState=90`; konverzace zmizí ze seznamu,
   data zůstanou.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| `core_system_users` | `conversations.user` / `.created_by` | Vlastník |
| [core_ai_backends](../../ai/tables/core_ai_backends.md) | `conversations.backend` → `ai_backends.id` | Zvolený backend |
| [core_chat_messages](core_chat_messages.md) | `messages.conversation` → `conversations.id` | Zprávy konverzace |
