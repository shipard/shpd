# Tabulka: Hosting — AI analyzery (hosting_core_ai_analyzers)

Evidence AI analyzerů napojených na hosting (hosting-10, D1). Řádek = jeden
analyzer stroj, který si přes `GET /_hosting/ai-analyzer/lookup` stahuje
obsah spravovaného souboru `sources.d/hosting.json` (proces `sources-sync`
v repu `ai_analyzer`). Autentizace API klíčem analyzeru (`shpd_hk_…`, jen
prefix + hash) — stejné schéma jako klíče serverů a mail-routerů, sdílená
validace `HostingApiKeyAuthenticator`.

`tableId = 439`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** — generické CRUD/viewer/form cesty vrací
ne-adminovi 403.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(100), NOT NULL, UNIQUE | Lidský název analyzeru (např. „Analyzer EU-1") |
| `note` | text | Volná poznámka správce |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `api_key_prefix` | varchar(12) | Prefix API klíče analyzeru (`shpd_hk_` + první znaky) pro rychlý lookup |
| `api_key_hash` | varchar(64), sensitive | SHA-256 hash celého tokenu; plaintext se neukládá, plní CLI `hosting-analyzer-key` |
| `last_seen` | datetime | Čas posledního autentizovaného dotazu na lookup endpoint |
| `created` | datetime, NOT NULL | Čas vytvoření záznamu |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_name` | unique | `name` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [hosting_core_data_sources](hosting_core_data_sources.md) | — (bez FK) | Lookup endpoint servíruje aktivní DS s vyplněným `analyzer_token`; analyzer k DS není vázán řádkem, obsluhuje všechny |
