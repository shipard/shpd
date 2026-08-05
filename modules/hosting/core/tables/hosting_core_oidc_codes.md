# Tabulka: OIDC OP transakce (hosting_core_oidc_codes)

Transakce OIDC providera hostingu (D2, D10) — jeden řádek žije od
`authorize` po `token`, analogie
[core_system_auth_transactions](../../../core/system/tables/core_system_auth_transactions.jsonc)
na straně RP. Čistě transakční tabulka: bez docStates, bez vieweru
a formu. `tableId = 433`, **`adminOnly = true`**.

## Životní cyklus řádku

1. **`authorize`** — INSERT: `txn` (43 znaků base64url), `client`,
   `state`/`nonce`/`code_challenge`/`redirect_uri` z požadavku,
   `expires = now + 600 s`. `user` a `code` jsou NULL.
2. **`approve`** (Bearer session, SPA) — UPDATE: `user` = session user,
   `code` = 43 znaků, `expires = now + 60 s` (zkrácení na kódové TTL).
   `user IS NULL` guard = single-use transakce.
3. **`token`** — lookup dle `code`, **okamžitý DELETE** (single-use
   i při následném selhání validace), pak validace a vydání id_tokenu.

Úklid expirovaných řádků: oportunistický `DELETE WHERE expires < now`
při každém `authorize` (vzor RP). Žádný cron.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `txn` | varchar(64), NOT NULL, UNIQUE | Identifikátor transakce pro `?op_auth={txn}` |
| `client` | int → [hosting_core_data_sources](hosting_core_data_sources.md), NOT NULL | Klientský DS |
| `user` | int → core_system_users, NULL | Schvalující uživatel — plní `approve` |
| `code` | varchar(64), NULL, UNIQUE | Autorizační kód — plní `approve` (MariaDB unique povoluje víc NULL) |
| `state` | varchar(200), NOT NULL | `state` z authorize požadavku (RP posílá 43 znaků) |
| `nonce` | varchar(64), NOT NULL | `nonce` — propíše se do id_tokenu |
| `code_challenge` | varchar(128), NOT NULL | PKCE S256 challenge |
| `redirect_uri` | varchar(250), NOT NULL | Redirect URI z authorize — token endpoint vyžaduje shodu |
| `expires` | datetime, NOT NULL | TTL (600 s transakce, 60 s kód) |
| `created` | datetime, NOT NULL | Čas vytvoření |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_txn` | unique | `txn` |
| `unq_code` | unique | `code` |
| `idx_expires` | index | `expires` (levný oportunistický úklid) |
