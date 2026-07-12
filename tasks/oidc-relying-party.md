# OIDC Relying Party — přihlášení přes externí identity providery

## Kontext

Autentizace v nov_shipard je dnes pouze lokální: `core_system_users`
(login + bcrypt hash), opaque session tokeny `shpd_st_` (24 h, rotace při
refresh), API klíče `shpd_ak_`. Uživatele zakládá jen CLI `user-create`.

Tato fáze přidává **generickou OIDC relying party (RP) vrstvu**: každý DS
umí být standardním OIDC klientem vůči libovolnému providerovi (Microsoft
Entra, Zitadel, Keycloak, později centrální Shipard ID). Per-DS auth politika
určuje, které metody jsou povolené — včetně možnosti lokální heslo úplně
vypnout (enterprise zákazníci s vlastním IdP).

**Potvrzená rozhodnutí** (z návrhové diskuse, 2026-07):

- **D1** — DS boundary = standardní OIDC RP (authorization code + PKCE),
  generická implementace v nov_shipard. Shipard ID bude později jen jeden
  z providerů; centrála je díky standardnímu rozhraní vyměnitelná.
- **D9/D15** — break-glass: CLI příkaz založí `shpd_st_` session přímo v DB,
  obchází HTTP login úplně (funguje bez ohledu na politiku).
- **D10** — mapování: lookup přes `(issuer, sub)`; auto-link podle ověřeného
  e-mailu jen u providerů s `autoLinkEmail: true` (enterprise IdP); JIT
  provisioning jako per-provider opt-in.
- **D11** — předání tokenu do SPA přes jednorázový handoff kód + exchange
  endpoint (token nikdy v URL).
- **D12** — validace JWT přes `firebase/php-jwt` (nová composer závislost).
- **D13** — OIDC transakce (state, PKCE, handoff) v DB tabulce.
- **D14** — provider config včetně `clientSecret` v `config/main.json`
  (konzistentní s DB credentials tamtéž).

## Návaznost

- **Pouze `nov_shipard`.** Shipard ID (centrální OP) v této fázi neexistuje
  a není součástí scope.
- **Nezávislé na Fázi 0** (pozvánky, reset hesla, správa uživatelů v UI) —
  může běžet paralelně.
- **Vývoj/testování bez Shipard ID:** RP vrstva se ladí proti lokálnímu
  Keycloak/Zitadel v dockeru (libovolné redirect URI); veřejní provideři
  většinou povolují jen `http://localhost`.
- Session mechanika (`shpd_st_`, refresh, logout, `AuthMiddleware`) se
  **nemění** — OIDC je jen další cesta, jak session vznikne.

## Před implementací přečti

- `src/Api/Controller/AuthController.php` — session vzniká zde; extrakce do
  `SessionService` je první krok.
- `src/Api/Middleware/AuthMiddleware.php` (`isExempt()`) a
  `src/Api/Middleware/RateLimitMiddleware.php` (ř. ~125, login limit per IP).
- `src/Api/Router.php` (`/_auth/*` routy, ř. ~239) a `public/index.php`
  (`dispatchAuth`, ř. ~638).
- `src/Core/Config/DataSourceConfig.php` — vzor accessorů s defaulty.
- `src/Api/Controller/AppController.php::info()` — veřejný endpoint pro
  login screen.
- `frontend/src/api/auth.js`, `frontend/src/components/auth/LoginScreen.svelte`,
  `frontend/src/App.svelte`.
- `src/Command/Server/DsCreateCommand.php` ř. ~120 a
  `src/Command/DataSource/DsUpgradeCommand.php` ř. ~82 — auto-vytváření
  podadresářů DS (přibude `cache/oidc`).

## Scope

1. Auth politika per DS v `config/main.json` + serverové vynucení.
2. OIDC klient: discovery + JWKS cache, authorize URL, code exchange (PKCE),
   validace `id_token`.
3. Endpointy `/_auth/oidc/start|callback|exchange` + handoff do SPA.
4. Mapovací tabulka identit + politika propojování (autoLinkEmail, JIT).
5. Break-glass CLI.
6. Login screen: tlačítka providerů, podmíněný lokální formulář, chybové stavy.

**Non-goals (explicitně mimo):** Shipard ID / vlastní OP; interaktivní
propojování účtů pro konzumní providery (`autoLinkEmail: false` bez existující
identity → chyba `oidc_no_account`, flow s potvrzením heslem je follow-up);
Fáze 0 nástroje; odhlášení na straně IdP (RP-initiated logout); refresh tokeny
od providera (session žije vlastním životem, provider se použije jen při loginu).

## Konfigurace

`config/main.json`, nový klíč `auth` (celý volitelný — bez něj platí dnešní
chování, tj. jen lokální login):

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

Validace při načtení (fail-fast s jasnou chybou): unikátní `id`, `issuer`
https URL, u každého providera `clientId` + `clientSecret` neprázdné.
`local: false` s prázdnými `providers` = chyba konfigurace.

## Změny po souborech

### Commit 1 — základy: SessionService, schéma, politika

**`composer.json`** — přidat `"firebase/php-jwt": "^6.10"`.

**`modules/core/system/tables/core_system_user_identities.jsonc`** (nová,
tableId **421**): `id` PK AI, `user_id` int NOT NULL (idx), `provider`
varchar(50) NOT NULL (informativní — id z konfigurace), `issuer` varchar(255)
NOT NULL, `subject` varchar(255) NOT NULL, `email_at_link` varchar(200) NULL,
`created` datetime NOT NULL, `last_login` datetime NULL. Unique
`(issuer, subject)`. Klíčem je issuer+sub — id providera v konfiguraci se může
přejmenovat, issuer je stabilní.

**`modules/core/system/tables/core_system_auth_transactions.jsonc`** (nová,
tableId **422**): `id` PK AI, `state` varchar(64) NOT NULL unique, `provider`
varchar(50) NOT NULL, `pkce_verifier` varchar(128) NOT NULL, `nonce`
varchar(64) NOT NULL, `handoff_code` varchar(64) NULL (idx), `session_token`
varchar(128) NULL, `created` datetime NOT NULL, `expires` datetime NOT NULL
(idx). Životní cyklus: start → (callback OK: naplní handoff_code +
session_token, prodlouží expires na +60 s) → exchange smaže řádek. Expirované
řádky uklízí stejný mechanismus jako sessions.

**`modules/core/system/tables/core_system_users.jsonc`** — `password_hash`
→ `"nullable": true` (JIT uživatelé heslo nemají). `ds-upgrade` provede
MODIFY.

**`src/Api/SessionService.php`** (nový) — extrakce `createSession()`,
`invalidateSession()`, `generateToken()` z `AuthController`. Navíc:
`createSession()` nově plní `ip_address` (sloupec existuje, dosud se
neplnil) — parametr `?string $clientIp`.

**`src/Api/Controller/AuthController.php`** — použije `SessionService`;
`login()`: (1) když politika `local: false` → 403 `AUTH_METHOD_DISABLED`;
(2) `password_hash IS NULL` → 401 `UNAUTHORIZED` (stejná hláška jako špatné
heslo, žádný leak).

**`src/Core/Config/DataSourceConfig.php`** — `getAuthPolicy(): AuthPolicy`
(nové readonly DTO `src/Core/Auth/AuthPolicy.php` + `OidcProviderConfig.php`);
defaulty a validace viz výše.

### Commit 2 — OIDC klient, endpointy, break-glass

**`src/Core/Auth/OidcDiscovery.php`** (nový) — fetch + file cache
`{ds}/cache/oidc/{sha256(issuer)}.discovery.json` a `.jwks.json`. Discovery
TTL 24 h. JWKS: při neznámém `kid` refresh, ale max 1× za 5 min
(anti-DoS). HTTP přes curl (vzor `AnthropicLlmClient`), timeout 10 s,
jen https.

**`src/Core/Auth/OidcClient.php`** (nový) —
`buildAuthorizeUrl(provider, state, nonce, codeChallenge, redirectUri)`;
`exchangeCode(provider, code, verifier, redirectUri): array` (POST token
endpoint, `client_secret_post`); `validateIdToken(provider, jwt, nonce):
OidcIdentity` — přes firebase/php-jwt: podpis proti JWKS, allowlist algoritmů
**RS256/ES256** (nikdy none/HS*), `iss` === issuer z discovery, `aud`
obsahuje clientId, `exp`/`iat` s tolerancí ±60 s, `nonce` match. Vrací
readonly DTO `OidcIdentity` (`issuer`, `sub`, `email`, `emailVerified`,
`name`).

**`src/Core/Auth/IdentityMapper.php`** (nový) — `resolve(OidcIdentity,
OidcProviderConfig, DataSourceConnection): int` (user_id) nebo doménová
výjimka s kódem pro redirect:
1. lookup `(issuer, subject)` → nalezeno → kontrola `is_active`
   (neaktivní → `oidc_account_inactive`) → update `last_login` → return.
2. nenalezeno, `autoLinkEmail` && `emailVerified` && email neprázdný →
   `SELECT ... WHERE email = %s AND is_active = 1`:
   - právě 1 → INSERT identity, return;
   - 0 → `jitProvision` ? založ uživatele (`login` = email, `full_name` =
     name claim ?: email, `email`, `password_hash` NULL, `is_active` 1;
     kolize loginu → `oidc_login_conflict`) + INSERT identity : `oidc_no_account`;
   - \>1 → `oidc_email_ambiguous` (email není unique — auto-link při
     duplicitě zakázán, řeší admin).
3. nenalezeno, `autoLinkEmail: false` → `oidc_no_account`.

**`src/Api/Controller/OidcController.php`** (nový):
- `start(Request, config, db): Response` — validace `?provider=` proti
  politice (neznámý → 404), vygeneruje `state` (32 B urlsafe), `nonce`,
  PKCE verifier (64 B) + S256 challenge, INSERT transakce (TTL 10 min),
  **302** na authorize URL. Redirect URI = `{scheme}://{host}{apiBase}/_auth/oidc/callback`
  odvozené z Requestu — pozor na dev mód s prefixem `/{ds-id}/api/v1`.
- `callback(Request, ...): Response` — `?error=` od IdP → redirect
  `oidc_denied`; lookup `state` (nenalezen/expirovaný → `oidc_invalid_state`);
  exchange + validace id_tokenu (selhání → `oidc_provider_error`);
  `IdentityMapper::resolve()`; `SessionService::createSession()`; UPDATE
  transakce (handoff_code 32 B, session_token, expires +60 s); **302** na
  `/app/?login=oidc&code={handoff}`. Chybové větve → **302** na
  `/app/?login_error={kod}`. Dev mód: cílová app URL s prefixem.
- `exchange(Request, db): Response` — POST `{code}`; lookup handoff_code,
  kontrola expirace, DELETE řádku, vrátí **stejný envelope jako login**
  (`token`, `expires_at`, `user`). Neplatný/expirovaný kód → 401.

**`src/Api/Router.php`** — `GET /_auth/oidc/start` → `('auth','oidcStart')`,
`GET /_auth/oidc/callback` → `('auth','oidcCallback')`,
`POST /_auth/oidc/exchange` → `('auth','oidcExchange')`.

**`public/index.php`** — `dispatchAuth()` rozšířit o tři akce (OidcController
dostává navíc `$resolved->config`).

**`src/Api/Middleware/AuthMiddleware.php`** — `isExempt()`: `auth` +
`oidcStart|oidcCallback|oidcExchange` → true.

**`src/Api/Middleware/RateLimitMiddleware.php`** — podmínka na ř. ~127
rozšířit: login-class limit (10/min/IP) platí i pro `oidcStart` a
`oidcExchange`. `oidcCallback` nechat v `default` (přichází s query od IdP,
je chráněný single-use state).

**`src/Api/Controller/AppController.php::info()`** — přidat do odpovědi
`auth: { "local": bool, "providers": [{ "id", "label" }] }`. Nikdy
clientId/secret/issuer.

**`src/Command/DataSource/AuthEmergencyLoginCommand.php`** (nový,
`auth-emergency-login --login xy`) — break-glass: najde aktivního uživatele,
`SessionService::createSession()` přímo do DB, vytiskne token a URL. Funguje
bez ohledu na auth politiku (neprochází HTTP vrstvou). Zaloguje warning.

**`DsCreateCommand` / `DsUpgradeCommand`** — do seznamu auto-vytvářených
podadresářů přidat `cache/oidc`.

### Commit 3 — frontend, i18n, dokumentace

**`frontend/src/api/auth.js`** — `exchangeOidc(code)` (raw fetch, jako
ostatní auth funkce); `oidcStartUrl(providerId)` helper skládající URL na
start endpoint (plná navigace, ne fetch).

**`frontend/src/App.svelte`** (příp. `main.js`) — při startu detekce
`?login=oidc&code=` → `exchangeOidc()` → `authStore.setAuth()` →
`history.replaceState` (vyčistit URL). Detekce `?login_error=` → předat
`LoginScreen`.

**`frontend/src/components/auth/LoginScreen.svelte`** — z
`appInfoStore.auth`: `local: false` → skrýt formulář; tlačítka providerů
(label z konfigurace, navigace na start URL); zobrazení `login_error` přes
i18n. Vizuální oddělovač formulář vs. tlačítka jen pokud je obojí.

**i18n cs/en** — klíče: `login.error.oidc_denied`, `oidc_invalid_state`,
`oidc_no_account`, `oidc_email_ambiguous`, `oidc_account_inactive`,
`oidc_login_conflict`, `oidc_provider_error` (+ obecné `login.providerHint`
dle potřeby). `check:i18n` musí projít.

**`docs/auth.md`** (nový) — architektura RP vrstvy, auth politika, flow
diagram, mapovací pravidla, break-glass, návod na test s dockerovým
Keycloakem. **`docs/rest-api.md`** — 3 řádky do tabulky endpointů + odstavec
k exempt výjimkám. **`docs/architecture.md`** — zmínka v middleware tabulce.

## Testy

PHPUnit (úzké `--filter`!):

- `OidcClientTest` — validace id_tokenu proti fixture JWKS (vlastní RSA pár
  v `tests/Fixtures/oidc/`): platný token; špatný `iss`/`aud`/`nonce`;
  expirovaný; `alg: none` a HS256 → odmítnout; neznámý `kid`.
- `IdentityMapperTest` — existující identita; auto-link právě 1 e-mail;
  duplicitní e-mail → `oidc_email_ambiguous`; JIT on/off; `emailVerified:
  false` → žádný auto-link; neaktivní uživatel.
- `AuthControllerTest` — `local: false` → 403 `AUTH_METHOD_DISABLED`;
  `password_hash NULL` → 401.
- `OidcControllerTest` — start: 302 + transakce v DB + korektní authorize
  URL (state/nonce/challenge/redirect_uri); callback: invalid state,
  úspěšná větev s mock exchange; exchange: platný kód (envelope shodný
  s loginem), druhé použití → 401, expirovaný → 401.
- `RouterTest` — 3 nové routy + method not allowed.
- Frontend (node): exchange flow v `auth.js`, podmíněné renderování
  `LoginScreen` (local off / providers).

## Commit strategie

1. `auth: session service, identity/transaction tables, per-DS auth policy`
   — Commit 1 výše + testy politiky a schémat.
2. `auth: OIDC relying party (start/callback/exchange), break-glass CLI`
   — Commit 2 + OidcClient/IdentityMapper/Controller testy.
3. `auth: login screen providers, OIDC handoff, i18n, docs` — Commit 3.

Po commitech 1 a 2: `ds-upgrade` na dev DS (nové tabulky + MODIFY
password_hash + `cache/oidc`).

## Hotovo když

- [ ] `config/main.json` bez `auth` klíče → chování beze změny (regrese 0).
- [ ] DS s `local: false` odmítá `/_auth/login` (403) i při správném heslu;
      login screen formulář nezobrazí.
- [ ] Proti dockerovému Keycloaku projde celý flow: start → přihlášení →
      callback → redirect s handoff → exchange → funkční `shpd_st_` session
      → normální práce v aplikaci vč. refresh/logout.
- [ ] Druhé použití handoff kódu i state → 401 / `oidc_invalid_state`.
- [ ] Auto-link: předem založený uživatel se shodným e-mailem se propojí,
      druhé přihlášení jde přes `(issuer, sub)` i po změně e-mailu u IdP.
- [ ] Duplicitní e-mail v DS → `oidc_email_ambiguous`, žádný link nevznikl.
- [ ] JIT: `jitProvision: true` založí uživatele s `password_hash NULL`;
      lokální login takového uživatele → 401.
- [ ] `auth-emergency-login` funguje na DS s `local: false`.
- [ ] Všechny nové PHPUnit testy zelené (úzké filtry), `check:i18n` prochází.
- [ ] `docs/auth.md` existuje, rest-api.md a architecture.md aktualizované.
