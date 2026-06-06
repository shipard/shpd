# Tabulka: AI backendy (core_ai_backends)

Konfigurace AI providerů pro analýzu došlé pošty. Per DS může existovat více
backendů (např. Anthropic + lokální Ollama), právě jeden může mít
`is_default = true`. Per DS se obvykle vystačí s jedním Anthropic backendem
auto-provisioned při `ds-upgrade`.

## Citlivá data

Sloupec `api_key` je typu `encrypted_text` — viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md). Plaintext
neleží v DB; šifrování řeší `AIBackendDocument::beforeSave()` přes
`DsSecretCipher`. Plaintext se nesmí logovat ani vracet do view; do API
response (claim endpoint) se vkládá jen v paměti dočasně po dobu zpracování
zprávy.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `backend_id` | varchar(50), NOT NULL, UNIQUE | Lidský identifikátor (`default`, `claude-opus`) |
| `name` | varchar(100), NOT NULL | Zobrazovaný název v UI |

### Provider

| Sloupec | Typ | Popis |
|---|---|---|
| `provider` | varchar(30), NOT NULL, default `anthropic` | Identifikátor providera. V MVP pouze `anthropic`. |
| `model` | varchar(100), NOT NULL | Model name (`claude-sonnet-4-5`, …) |
| `base_url` | varchar(200) | Volitelný custom endpoint (pro non-default proxy) |

### Přístup (credentials)

| Sloupec | Typ | Popis |
|---|---|---|
| `api_key` | encrypted_text | API klíč šifrovaný `DsSecretCipher`. Ukládá `AIBackendDocument::beforeSave` při dirty change. |

### Ladění (tuning)

| Sloupec | Typ | Popis |
|---|---|---|
| `max_tokens` | int, NOT NULL, default 4096 | Max output tokenů na request |
| `temperature` | numeric(3,2), NOT NULL, default 0.00 | Extrakce má být deterministická |

### Příznaky (flags)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_default` | boolean, default false | Výchozí backend DS. Smí být `true` jen u jedné řádky (vynuceno aplikačně v `AIBackendDocument::validate`). |
| `is_active` | boolean, default false | Aktivuje se po nastavení `api_key` přes `ai-analyzer-set-key`. |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas založení |
| `created_by` | int → `core_system_users` | Uživatel, který backend založil |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` | tinyint (system) | Stav dokumentu — viz `core.system.docStatesArchive` |
| `docStateMain` | tinyint (system) | Řazení podle stavu |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_backend_id` | unique | `backend_id` | Lidský kód unikátní per DS |
| `idx_is_default` | index | `is_default` | Rychlé vyhledání default backendu při claim |
| `idx_is_active` | index | `is_active` | Filter aktivních backendů |

## Životní cyklus

1. **Auto-provisioning** při `ds-upgrade`: vznikne backend `default`
   s `is_default=true`, `is_active=false`, `api_key=NULL`.
2. **Nastavení klíče**: admin spustí `bin/shpd-ds ai-analyzer-set-key`,
   který klíč zašifruje a nastaví `is_active=true`.
3. **Claim**: `AnalysisController::claim()` načte default aktivní backend,
   `DsSecretCipher::decrypt()` vrátí plaintext, plaintext se vloží do response
   a okamžitě zapomene.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_ai_profiles](core_mail_ai_profiles.md) | `ai_profiles.backend` → `ai_backends.id` | Profil je vždy vázán na konkrétní backend |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `message_analyses.backend` → `ai_backends.id` | Audit: kdo analyzoval |
