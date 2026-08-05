# Tabulka: Hosting — zdroje dat (hosting_core_data_sources)

Evidence zdrojů dat (DS) spravovaných hostingem. Fáze 0 = ručně plněná
evidence + zdroj portálového seznamu „moje DS". Sloupce pro mail token
a OIDC client_secret **záměrně chybí** — přijdou ve svých fázích (3, 1)
jako aditivní změny schématu.

`tableId = 431`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** (D9) — portáloví uživatelé se k datům dostanou
výhradně přes `/_hosting/portal/*` (D10).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `ds_id` | varchar(19), NOT NULL, UNIQUE | ID zdroje dat ve formátu `xxxx-xxxx-xxxx-xxxx` |
| `name` | varchar(200), NOT NULL | Lidský název DS (zobrazuje se na portálu) |
| `web_id` | varchar(50), UNIQUE | Slug pro mail adresy a hezké URL. NULL = nepřidělen; zatím jen evidence (mail-router = Fáze 3). |

### Umístění (placement)

| Sloupec | Typ | Popis |
|---|---|---|
| `server` | int → [hosting_core_servers](hosting_core_servers.md) | Server, na kterém DS běží. NULL = zatím nepřiřazen. |
| `url_app` | varchar(200), NOT NULL | URL aplikace — cíl vstupního tlačítka na portálu |
| `install_module` | varchar(50) | Install modul DS (např. `install.base`) — evidence pro provisioning |
| `lifecycle` | enumString(10), NOT NULL, default `active` | Klíč v [`hosting.core.dsLifecycle`](../config/dsLifecycle.jsonc) — request, creating, active, suspended. Fáze 0 používá jen `active`; request/creating řídí provisioning frontu Fáze 2. |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření záznamu |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_ds_id` | unique | `ds_id` |
| `unq_web_id` | unique | `web_id` (MariaDB unikátní index povoluje víc NULL — nullable unique funguje) |
| `idx_lifecycle` | index | `lifecycle` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [hosting_core_servers](hosting_core_servers.md) | `data_sources.server → servers.id` | Umístění na serveru |
| [hosting_core_ds_users](hosting_core_ds_users.md) | `ds_users.data_source → data_sources.id` | Vazby uživatelů na DS |
