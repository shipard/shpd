# Tabulka: Hosting — servery (hosting_core_servers)

Evidence DS serverů spravovaných hostingem. Fáze 0 = ručně plněná
evidence; Fáze 2 (provisioning agent) doplnila API klíč serveru
(`shpd_hk_…`, jen prefix + hash), příznak „smí zakládat DS" a stavové
sloupce z rekonciliace (`last_seen`, `last_version`).

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
| `api_key_prefix` | varchar(12) | Prefix API klíče serveru (`shpd_hk_` + první znaky) pro rychlý lookup |
| `api_key_hash` | varchar(64), sensitive | SHA-256 hash celého tokenu; plaintext se neukládá, plní CLI `hosting-server-key` |
| `can_provision` | boolean, default 0 | Smí server zakládat DS z fronty požadavků (Fáze 2) |
| `last_seen` | datetime | Čas poslední rekonciliace agenta `hosting-sync` |
| `last_version` | varchar(30) | Verze shpd nahlášená agentem při rekonciliaci |
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
