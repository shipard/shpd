# Tabulka: Hosting — mail-routery (hosting_core_mail_routers)

Evidence mail-routerů napojených na hosting (Fáze 3, D4). Řádek = jeden
mail-router stroj, který si přes `GET /_hosting/mail/lookup` stahuje
`lookup.json` (proces `lookup-sync` v repu `mail_router`). Autentizace
API klíčem routeru (`shpd_hk_…`, jen prefix + hash) — stejné schéma
jako klíče serverů, sdílená validace `HostingApiKeyAuthenticator`.

`tableId = 434`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** (D9) — generické CRUD/viewer/form cesty vrací
ne-adminovi 403.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `name` | varchar(100), NOT NULL, UNIQUE | Lidský název routeru (např. „Mail EU-1") |
| `domains` | varchar(500), NOT NULL | Čárkami oddělené mail domény, které router obsluhuje (→ `hosts` v lookup odpovědi). Normalizuje `MailRouterDocument::beforeSave` (trim + lowercase). |
| `note` | text | Volná poznámka správce |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `api_key_prefix` | varchar(12) | Prefix API klíče routeru (`shpd_hk_` + první znaky) pro rychlý lookup |
| `api_key_hash` | varchar(64), sensitive | SHA-256 hash celého tokenu; plaintext se neukládá, plní CLI `hosting-router-key` |
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
| [hosting_core_data_sources](hosting_core_data_sources.md) | — (bez FK) | Lookup endpoint servíruje aktivní DS s vyplněným `mail_token`; router k DS není vázán řádkem, obsluhuje všechny |
