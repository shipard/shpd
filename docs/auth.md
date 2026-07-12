# Autentizace — lokální login, OIDC relying party, break-glass

## Přehled

Autentizace má tři cesty vzniku `shpd_st_` session tokenu:

1. **Lokální login** — `POST /_auth/login` (login + bcrypt heslo).
2. **OIDC** — standardní relying party (authorization code + PKCE) vůči
   libovolnému OIDC providerovi (Microsoft Entra, Zitadel, Keycloak, později
   centrální Shipard ID).
3. **Break-glass CLI** — `shpd-ds auth-emergency-login` založí session přímo
   v DB, obchází HTTP vrstvu i auth politiku.

Session mechanika (`shpd_st_`, 24 h TTL, refresh s rotací, logout,
`AuthMiddleware`) je pro všechny cesty stejná — OIDC je jen další způsob, jak
session vznikne. Mintování a invalidaci session drží `SessionService`
(`src/Api/SessionService.php`), který nově plní i `ip_address`.

## Auth politika per DS

`config/main.json`, volitelný klíč `auth`. Bez něj platí dnešní chování
(jen lokální login):

```jsonc
"auth": {
    "local": true,                       // default true
    "providers": [                       // default []
        {
            "id": "entra",               // stabilní id, [a-z0-9-]+
            "label": "Přihlásit přes Firma a.s.",
            "issuer": "https://login.microsoftonline.com/{tenant}/v2.0",
            "clientId": "...",
            "clientSecret": "...",
            "scopes": ["openid", "profile", "email"],   // default tyto tři
            "autoLinkEmail": true,       // default false
            "jitProvision": false        // default false
        }
    ]
}
```

- Parsování + validace: `AuthPolicy::fromArray()` / `OidcProviderConfig`
  (`src/Core/Auth/`). Fail-fast: unikátní `id`, `issuer` https URL, neprázdný
  `clientId`/`clientSecret`, `local: false` bez providerů = chyba.
- Validuje se **lazy** při prvním použití (`DataSourceConfig::getAuthPolicy()`),
  ne při načtení configu — rozbitá auth sekce nezablokuje CLI (break-glass).
- `local: false` → `POST /_auth/login` vrací **403 `AUTH_METHOD_DISABLED`**
  i při správném heslu; login obrazovka formulář skryje.
- `GET /_app/info` vrací `auth: {local, providers: [{id, label}]}` — nikdy
  clientId/secret/issuer.

## OIDC flow

```
Prohlížeč                      nov_shipard                       IdP
    │  GET /_auth/oidc/start?provider=x                           │
    │──────────────────────────────►│  INSERT transakce           │
    │                               │  (state, nonce, PKCE,       │
    │            302                │   TTL 10 min)               │
    │◄──────────────────────────────│                             │
    │  authorize URL (state, nonce, code_challenge S256)          │
    │─────────────────────────────────────────────────────────────►
    │                 … přihlášení u IdP …                         │
    │  GET /_auth/oidc/callback?code=…&state=…                    │
    │──────────────────────────────►│  lookup state (single-use)  │
    │                               │  POST token endpoint ───────►
    │                               │  (code + PKCE verifier +    │
    │                               │   client_secret_post)       │
    │                               │◄──────────── id_token ──────│
    │                               │  validace JWT (JWKS)        │
    │                               │  IdentityMapper.resolve()   │
    │            302                │  createSession()            │
    │◄──────────────────────────────│  UPDATE transakce (handoff, │
    │  /app/?login=oidc&code={handoff}       TTL 60 s)            │
    │  POST /_auth/oidc/exchange {code}                           │
    │──────────────────────────────►│  DELETE transakce           │
    │◄──────────────────────────────│                             │
    │  {token, expires_at, user} — envelope shodný s loginem      │
```

Klíčové vlastnosti:

- **Token nikdy v URL** (D11) — do SPA jde jen jednorázový handoff kód
  s TTL 60 s; `exchange` ho smaže při prvním použití (i expirovaný).
- **State je single-use** — po úspěšném callbacku (naplněný `handoff_code`)
  se replay odmítne jako `oidc_invalid_state`.
- **PKCE S256** vždy; token endpoint `client_secret_post`.
- Transakce flow žijí v `core_system_auth_transactions` (tableId 422);
  expirované řádky uklízí oportunistický DELETE při každém `start`.
- Chybové větve callbacku = 302 na `/app/?login_error={kod}`; kódy viz níže.
- Redirect URI se odvozuje z requestu: dev mód
  `http://{host}/{ds-id}/api/v1/_auth/oidc/callback`, produkce
  `https://{host}/api/v1/_auth/oidc/callback`.

### Validace id_tokenu (`OidcClient::validateIdToken`)

- Podpis proti JWKS providera přes `firebase/php-jwt`.
- Allowlist algoritmů **RS256/ES256** — `none` a HS* se odmítají ještě před
  dekódováním.
- `iss` === issuer z discovery, `aud` obsahuje `clientId`, `exp` povinný,
  `exp`/`iat` s tolerancí ±60 s, `nonce` musí sedět s transakcí.
- Discovery + JWKS cache: `{ds}/cache/oidc/{sha256(issuer)}.{discovery,jwks}.json`,
  discovery TTL 24 h; neznámý `kid` → vynucený refresh JWKS, max 1×/5 min
  (anti-DoS). Jen https, timeout 10 s (`OidcDiscovery`).

### Mapování identit (`IdentityMapper`, D10)

Tabulka `core_system_user_identities` (tableId 421), unikátní klíč
`(issuer, subject)` — id providera v konfiguraci se může přejmenovat, issuer
je stabilní. E-mail u IdP se může měnit, `(issuer, sub)` lookup funguje dál.

1. `(issuer, subject)` nalezeno → kontrola `is_active` (neaktivní →
   `oidc_account_inactive`) → update `last_login` → hotovo.
2. Nenalezeno + `autoLinkEmail: true` + `email_verified` + neprázdný e-mail →
   `SELECT … WHERE email = ? AND is_active = 1`:
   - právě 1 → propojit (INSERT identity);
   - 0 → `jitProvision: true` ? založit uživatele (`login` = e-mail,
     `full_name` = name claim ?: e-mail, `password_hash` **NULL**; kolize
     loginu → `oidc_login_conflict`) : `oidc_no_account`;
   - \>1 → `oidc_email_ambiguous` (e-mail není unique — řeší admin).
3. Jinak → `oidc_no_account`.

JIT uživatelé mají `password_hash NULL` — lokální login vrací 401 se stejnou
hláškou jako špatné heslo (žádný leak existence účtu).

### Chybové kódy

Redirect `/app/?login_error={kod}`, frontend překládá přes
`login.error.{kod}`:

| Kód | Význam |
|-----|--------|
| `oidc_denied` | Uživatel odmítl na straně IdP (`?error=` v callbacku) |
| `oidc_invalid_state` | Neznámý / expirovaný / už použitý state |
| `oidc_provider_error` | Discovery, code exchange nebo validace id_tokenu selhaly |
| `oidc_no_account` | Identita nemá účet a auto-link/JIT nejsou povolené |
| `oidc_email_ambiguous` | E-mail odpovídá více účtům |
| `oidc_account_inactive` | Propojený uživatel je deaktivovaný |
| `oidc_login_conflict` | JIT: login (= e-mail) už existuje |

## Frontend

- `main.js` při bootu (před mountem) detekuje `?login=oidc&code=` →
  `exchangeOidc()` → `authStore.setAuth()` → `history.replaceState` (kód
  nesmí přežít reload). `?login_error=` → `loginNotice` store → LoginScreen.
- `LoginScreen.svelte`: z `appInfoStore.auth` — `local: false` skryje
  formulář; tlačítka providerů (label z konfigurace) navigují na
  `oidcStartUrl(id)` (plná navigace, ne fetch). Oddělovač
  (`login.providerHint`) jen když je vidět obojí.
- Pure helpery `parseOidcRedirect` / `buildOidcStartUrl` v `api/oidc.js`
  (bez závislosti na window — testovatelné v node:test).

## Break-glass (D9/D15)

```bash
cd /opt/shipard/data-sources/{id}
vendor/bin/shpd-ds auth-emergency-login --login jan.novak
```

Najde aktivního uživatele, `SessionService::createSession()` přímo do DB,
vytiskne token + návod (localStorage / curl). Funguje bez ohledu na auth
politiku — `local: false`, nedostupný IdP i rozbitá `auth` sekce configu.
Každé použití zaloguje warning (`ErrorLogger`).

## Vývoj a test s Keycloakem v dockeru

```bash
docker run -d --name keycloak -p 8080:8080 \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin \
  quay.io/keycloak/keycloak:latest start-dev
```

1. Admin konzole `http://localhost:8080` → nový realm `shipard-dev`.
2. Clients → Create: `Client ID` = `shipard`, Client authentication **On**,
   Standard flow **On**. Valid redirect URI:
   `http://127.0.0.1/{ds-id}/api/v1/_auth/oidc/callback`.
3. Credentials tab → zkopírovat Client secret.
4. Users → založit uživatele s e-mailem (Email verified **On**), nastavit heslo.
5. Do `config/main.json` DS:

```jsonc
"auth": {
    "providers": [{
        "id": "keycloak-dev",
        "label": "Keycloak (dev)",
        "issuer": "http://localhost:8080/realms/shipard-dev",
        "clientId": "shipard",
        "clientSecret": "…",
        "autoLinkEmail": true,
        "jitProvision": true
    }]
}
```

**Pozn.:** issuer musí být https; jedinou výjimkou je `http://localhost` /
`http://127.0.0.1` (dev Keycloak bez TLS proxy) — viz
`OidcProviderConfig::isAllowedIssuerUrl()`. Stejné pravidlo vynucují i
skutečná HTTP volání v `OidcDiscovery`/`OidcClient`.

Smoke bez prohlížeče:

```bash
curl -s http://127.0.0.1/{ds-id}/api/v1/_app/info | jq .data.auth
curl -si "http://127.0.0.1/{ds-id}/api/v1/_auth/oidc/start?provider=keycloak-dev" | grep -i location
```

## Soubory

| Soubor | Role |
|--------|------|
| `src/Api/SessionService.php` | Mintování/invalidace `shpd_st_` sessions (login, OIDC, break-glass) |
| `src/Core/Auth/AuthPolicy.php`, `OidcProviderConfig.php` | Politika + validace konfigurace |
| `src/Core/Auth/OidcDiscovery.php` | Discovery + JWKS file cache, throttlovaný refresh |
| `src/Core/Auth/OidcClient.php` | Authorize URL, code exchange, validace id_tokenu |
| `src/Core/Auth/IdentityMapper.php` | `(issuer, sub)` → user_id, auto-link, JIT |
| `src/Core/Auth/OidcException.php`, `OidcIdentity.php` | Doménová chyba s kódem, DTO identity |
| `src/Api/Controller/OidcController.php` | Endpointy start/callback/exchange |
| `src/Command/DataSource/AuthEmergencyLoginCommand.php` | Break-glass CLI |
| `modules/core/system/tables/core_system_user_identities.jsonc` | Mapovací tabulka (421) |
| `modules/core/system/tables/core_system_auth_transactions.jsonc` | OIDC transakce (422) |
| `frontend/src/api/oidc.js`, `api/auth.js` | Pure helpery, `exchangeOidc`, `oidcStartUrl` |
| `frontend/src/stores/loginNotice.svelte.js` | Chybový kód pro login obrazovku |
