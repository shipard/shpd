# Tabulka: Zprávy chatu (core_chat_messages)

Zprávy jedné [konverzace](core_chat_conversations.md). Jeden řádek = jeden tah
(zpráva uživatele, odpověď asistenta, nebo výsledky nástrojů). **Nezapisují se
přes API** — vznikají výhradně v orchestrační smyčce
([docs/chat.md](../../../../docs/chat.md)), která tím garantuje integritu bloků.
Detailní endpoint je jen čte.

## Formát obsahu

Sloupec `content` je **JSON pole bloků ve formátu Anthropic Messages API**
(`text`, `tool_use`, `tool_result`) — ne čistý text. Důvod: do historie musí
přežít i volání nástrojů, jinak by model v dalším tahu ztratil kontext, co
dělal. `tool_result` bloky podle Anthropic API žijí ve zprávě s `role=user`
(proto `kind`, viz níže).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `conversation` | int → `core_chat_conversations`, NOT NULL | Konverzace |
| `seq` | int, NOT NULL, default 0 | Pořadí v konverzaci (0-based) |
| `role` | enumString(12), NOT NULL, default `user` | `core.chat.messageRoles`: `user` / `assistant` |
| `kind` | enumString(16), NOT NULL, default `user_text` | `core.chat.messageKinds`: `user_text` / `assistant` / `tool_results` — display hint odlišující skutečnou zprávu uživatele od syntetické zprávy s výsledky nástrojů |

### Obsah (content)

| Sloupec | Typ | Popis |
|---|---|---|
| `content` | longtext, NOT NULL | JSON pole bloků (viz výše) |

### Telemetrie (telemetry)

Plněno u asistentského tahu (per tah).

| Sloupec | Typ | Popis |
|---|---|---|
| `tokens_input` | int, nullable | Vstupní tokeny tahu |
| `tokens_output` | int, nullable | Výstupní tokeny tahu |
| `cost` | numeric(14,6), nullable | Náklady tahu (zatím se neplní) |
| `model_name` | varchar(100), nullable | Model, který tah vyprodukoval |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vzniku |
| `created_by` | int → `core_system_users`, nullable | Vlastník konverzace |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_conversation` | index | `conversation` | Zprávy konverzace |
| `idx_conversation_seq` | index | `conversation`, `seq` | Načtení historie v pořadí |

## Vznik zpráv (orchestrační smyčka)

1. **Zpráva uživatele** (`role=user`, `kind=user_text`) — uloží se hned při
   přijetí, ještě před voláním modelu (odolnost vůči selhání).
2. **Tah asistenta** (`role=assistant`, `kind=assistant`) — uloží se po
   dokončení tahu; `content` nese textové bloky + případné `tool_use`.
3. **Výsledky nástrojů** (`role=user`, `kind=tool_results`) — syntetická zpráva
   s `tool_result` bloky, vrácená modelu pro další iteraci.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_chat_conversations](core_chat_conversations.md) | `messages.conversation` → `conversations.id` | Konverzace |
| `core_system_users` | `messages.created_by` | Vlastník |
