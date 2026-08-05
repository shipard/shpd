# Tabulka: Hosting — uživatelé zdrojů dat (hosting_core_ds_users)

Vazba uživatel hostingu ↔ zdroj dat. Zdroj pro portálový seznam
„moje DS" (`GET /_hosting/portal/my-datasources`, D10). Uživatelé jsou
běžné účty hosting DS (`core_system_users`, D8) — žádná nová tabulka
uživatelů.

`tableId = 432`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** (D9).

## Struktura

### Vazba (link)

| Sloupec | Typ | Popis |
|---|---|---|
| `user` | int → core_system_users, NOT NULL | Uživatel hostingu (D8) |
| `data_source` | int → [hosting_core_data_sources](hosting_core_data_sources.md), NOT NULL | Zdroj dat |
| `role` | enumString(10), NOT NULL, default `member` | Klíč v [`hosting.core.dsUserRoles`](../config/dsUserRoles.jsonc) — admin, member. Fáze 0: jen informativní badge na portálu. |
| `last_entered` | datetime | Rezerva — čas posledního vstupu přes portál; Fáze 0 neplní |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření záznamu |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_user_data_source` | unique | `user`, `data_source` — jedna vazba na dvojici |
| `idx_data_source` | index | `data_source` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [hosting_core_data_sources](hosting_core_data_sources.md) | `ds_users.data_source → data_sources.id` | Zdroj dat vazby |
| core_system_users | `ds_users.user → users.id` | Účet uživatele hostingu |
