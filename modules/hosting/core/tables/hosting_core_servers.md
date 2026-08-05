# Tabulka: Hosting — servery (hosting_core_servers)

Evidence DS serverů spravovaných hostingem. Fáze 0 = ručně plněná
evidence; API klíče, příznaky „smí zakládat DS", `last_seen` a verze
z rekonciliace přijdou ve Fázi 2 (provisioning agent) — schema changes
jsou aditivně bezpečné.

`tableId = 430`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** (D9) — generické CRUD/viewer/form cesty vrací
ne-adminovi 403.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(100), NOT NULL | Lidský název serveru (např. „Produkce EU-1") |
| `fqdn` | varchar(200), NOT NULL, UNIQUE | Plně kvalifikované doménové jméno stroje |
| `note` | text | Volná poznámka správce |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas vytvoření záznamu |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_fqdn` | unique | `fqdn` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [hosting_core_data_sources](hosting_core_data_sources.md) | `data_sources.server → servers.id` | Umístění zdroje dat na serveru |
