# Hosting — Task 1: OIDC Provider (Fáze 1)

**Stav:** hotovo

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D2, D8, D10, D12**, kontrakt §5.4. Protistrana (RP) je
> hotová a závazná: `docs/auth.md` §OIDC flow + §Validace id_tokenu —
> OP musí vydávat přesně to, co RP validuje, nic víc.

## Kontext

Hosting DS se stává centrální identitou: minimální OIDC Provider —
authorization code + PKCE S256, `client_secret_post`, RS256. Klienti =
zdroje dat z `hosting_core_data_sources` (client_id = `ds_id`).
Uživatelé = `core_system_users` hostingu (D8), portálové účty.

**Session bridge (doladění D10):** SPA drží session token
v localStorage (`auth.svelte.js`), `authorize` je top-level browser GET
bez `Authorization` hlavičky. OP proto session ověřuje obráceným
handoff patternem: `authorize` (exempt) validuje požadavek, založí OP
transakci a přesměruje na `/app/?op_auth={txn}`; SPA se session zavolá
`POST /_hosting/oidc/approve` (Bearer) → server naváže uživatele, vydá
autorizační kód a vrátí RP redirect URL; SPA provede
`window.location`. Bez session SPA nejdřív ukáže LoginScreen
(`op_auth` param přežije login). SSO = tiché schválení bez interakce.

**Issuer (D12):** explicitní setting `hosting.oidc.issuer`, trvalá
forma `https://{host}/api/v1/_hosting/oidc` — `OidcDiscovery` skládá
discovery URL konkatenací, issuer s cestou funguje bez zásahu do
nginx. Endpointy v discovery = `{issuer}/authorize`, `{issuer}/token`,
`{issuer}/jwks`.

**Vědomá volba (potvrzeno v chatu):** OP je čistě identitní autorita —
kód vydá kterémukoli přihlášenému uživateli hostingu bez kontroly
`hosting_core_ds_users`. O vpuštění do DS rozhoduje RP
(`IdentityMapper`: autoLink/JIT/`oidc_no_account`), přesně dle OIDC
sémantiky. Centrální gating přes ds_users = případný v2 policy flag.

## Cíl

1. RSA klíč OP v `secrets/` + CLI `hosting-oidc-init`.
2. Endpointy: discovery, JWKS, authorize, approve, token.
3. Tabulka `hosting_core_oidc_codes`; klientské sloupce
   na `hosting_core_data_sources`.
4. Frontend: zpracování `op_auth` v SPA (schválení + redirect).
5. E2E na dev serveru: druhý DS s `auth.providers` → login přes hosting.

## Před implementací přečti

- `docs/hosting.md` §5.4, §7; `docs/auth.md` §OIDC flow, §Validace
  id_tokenu, §Chybové kódy — **závazný kontrakt RP**
- `src/Core/Auth/OidcClient.php` — co přesně RP posílá na authorize
  (parametry) a token endpoint (`client_secret_post` + PKCE verifier);
  `ALLOWED_ALGS`, leeway ±60 s
- `src/Core/Auth/OidcDiscovery.php` — povinné klíče discovery,
  issuer match (rtrim compare), skládání URL
- `src/Core/Auth/OidcProviderConfig.php` — tvar `auth.providers[]`
  v `main.json` (pro E2E konfiguraci klientského DS)
- `src/Api/Controller/AuthController.php` — oidcStart/Callback/Exchange
  (vzor transakcí, handoff, rate limit loginu — stejný bucket použij
  na authorize/approve/token)
- `modules/core/system/tables/core_system_auth_transactions.jsonc` —
  vzor transakční tabulky (TTL, single-use, oportunistický úklid)
- `src/Api/Middleware/AuthMiddleware.php` — `isExempt()` (ř. ~52)
- `src/Core/Security/DsSecretCipher.php` — práce se `secrets/`
  (permissions 0600/0700, chybové hlášky); šifrování `encrypted_text`
- `src/Command/DataSource/AiAnalyzerSetKeyCommand.php` — vzor DS CLI
  commandu
- `modules/core/system/module.jsonc` — `settingsPages` vzor (pro pole
  issuer); `docs/app-settings.md` §6
- `frontend/src/App.svelte` + `frontend/src/stores/auth.svelte.js` —
  boot, zpracování URL parametrů (`?login=oidc&code=` z RP exchange),
  kam zapadne `op_auth`
- `composer.json` — `firebase/php-jwt` je k dispozici i pro podpis

## Změny po souborech

### Schéma

**`modules/hosting/core/tables/hosting_core_oidc_codes.jsonc`** (nová,
`adminOnly: true`, bez docStates — čistě transakční, vzor
auth_transactions): `txn` (varchar 64, unique — identifikátor
transakce pro `op_auth`), `client` (int FK →
hosting_core_data_sources), `user` (int FK → core_system_users,
nullable — plní approve), `code` (varchar 64, nullable, unique — plní
approve), `state` (varchar 200), `nonce` (varchar 64), `code_challenge`
(varchar 128), `redirect_uri` (varchar 250), `expires` (datetime),
`created`. Úklid expirovaných: oportunistický DELETE při každém
`authorize` (vzor RP).

**`hosting_core_data_sources.jsonc`** — aditivní sloupce:
`oidc_client_secret` (`encrypted_text`, nullable, `"sensitive": true`
— klient OP je aktivní jen s vyplněným secretem), `oidc_redirect_uri`
(varchar 250, nullable — exact match; F1 plní admin ručně, F2 agent).
Po změně `.jsonc` rebuild compiled cfg + `ds-upgrade` (dev DS
`gn5c-mm2v-pd5m-u43x`).

### `src/Command/DataSource/HostingOidcInitCommand.php` (nový)

`shpd-ds hosting-oidc-init`: vygeneruje RSA 3072 privátní klíč →
`{ds}/secrets/oidc-op.key` (PEM, 0600, dir 0700 — validace jako
`DsSecretCipher`), existující soubor → chyba (bez `--force`). Vypíše
`kid` (prefix sha256 otisku veřejného klíče). Registrace commandu dle
okolních.

### `src/Core/Hosting/OpKeyStore.php` (nový)

Načtení privátního klíče, odvození veřejného JWK (`n`/`e` přes
openssl), `kid`, podpis id_tokenu (`firebase/php-jwt` `JWT::encode`,
RS256, header `kid`). Chybějící klíč → jasná výjimka s hintem na
`hosting-oidc-init`.

### `src/Api/Controller/HostingOidcController.php` (nový)

Gating všech akcí: modul neaktivní (chybí tabulka) nebo nevyplněný
issuer setting → 404. Akce:

- **`discovery`** — `GET {…}/.well-known/openid-configuration`:
  `issuer` (setting doslovně, D12 — žádné odvozování z requestu;
  nesoulad hostu requestu vs. issuer → warning do logu), endpointy
  `{issuer}/authorize|token|jwks`, `response_types_supported: ["code"]`,
  `subject_types_supported: ["public"]`,
  `id_token_signing_alg_values_supported: ["RS256"]`,
  `token_endpoint_auth_methods_supported: ["client_secret_post"]`,
  `code_challenge_methods_supported: ["S256"]`,
  `scopes_supported: ["openid","profile","email"]`.
- **`jwks`** — `{"keys":[{kty,n,e,alg:"RS256",use:"sig",kid}]}`.
- **`authorize`** (GET, exempt): validace v pořadí —
  1. `client_id` existuje, lifecycle `active`, `oidc_client_secret`
     i `oidc_redirect_uri` vyplněné; `redirect_uri` **exact match**.
     Selhání → **400 chybová stránka, nikdy redirect** na neověřené
     redirect_uri.
  2. Ostatní (`response_type!=code`, chybějící `state`/`nonce`/
     `code_challenge`, `code_challenge_method!=S256`) → 302 na
     redirect_uri s `?error=invalid_request&state={state}`.
  3. OK → oportunistický úklid, INSERT transakce (`txn` =
     `random 43 znaků` vzor handoff, TTL 10 min), 302 →
     `/app/?op_auth={txn}` (dev mód s DS prefixem — odvoď cestu
     stejně jako RP redirect URI, viz auth.md).
- **`approve`** (POST, **není exempt** — Bearer session): body
  `{txn}`. Transakce existuje, neexpirovaná, `user IS NULL` (single
  use) → UPDATE `user` = session user, `code` = random 43, `expires` =
  now + 60 s (zkrácení na kódové TTL). Response
  `{redirect: "{redirect_uri}?code={code}&state={state}"}`. Neplatná
  transakce → 400 `OIDC_TXN_INVALID`.
- **`token`** (POST, exempt, form-encoded): `grant_type=
  authorization_code` + `code` + `redirect_uri` + `client_id` +
  `client_secret` + `code_verifier`. Lookup dle `code` →
  **okamžitý DELETE řádku** (single-use i při následném selhání) →
  validace: expirace, `client_id` odpovídá, secret
  (`hash_equals` proti dešifrovanému), `redirect_uri` shodné,
  `base64url(sha256(code_verifier)) === code_challenge`. Selhání →
  400 `{"error":"invalid_grant"}` (OAuth formát, RP čte tělo).
  Úspěch → `{"access_token": random, "token_type":"Bearer",
  "expires_in":300, "id_token": …}`. id_token claims: `iss` (setting),
  `sub` = (string) user id, `aud` = client_id, `exp` = iat+300, `iat`,
  `nonce` z transakce, `email`, `email_verified: true` (účty hostingu
  vznikají pozvánkou/adminem), `name` = full_name. RS256 + `kid`.
- Rate limit: authorize/approve/token do login bucketu (vzor
  `AuthController::login`).

### `src/Api/Router.php`, `public/index.php`, `AuthMiddleware`

- Router: `GET /_hosting/oidc/.well-known/openid-configuration`,
  `GET /_hosting/oidc/jwks`, `GET /_hosting/oidc/authorize`,
  `POST /_hosting/oidc/approve`, `POST /_hosting/oidc/token` →
  controller `hostingOidc`.
- `isExempt()`: `hostingOidc` akce `discovery|jwks|authorize|token`
  (approve záměrně ne).
- index.php: dispatch — token endpoint čte form-encoded body, authorize
  vrací 302/HTML (ověř, že Response vrstva umí obojí; vzor callback
  redirect v RP).

### Settings

`modules/hosting/core/module.jsonc`: `settingsPages` — stránka
`hostingOidc` s polem `hosting.oidc.issuer` (text; popis: trvalá
hodnota, změna zneplatní identity — D12). Gating stránky = stejný
mechanismus jako appSettings.

### Frontend

- `frontend/src/api/oidcOp.js`: `approveOpAuth(txn)`.
- `frontend/src/App.svelte`: boot detekce `?op_auth=` —
  přihlášený → approve → `window.location = redirect` (loading stav
  „Přesměrování…"); nepřihlášený → LoginScreen, `op_auth` přežije
  login (vzor RP `?login=oidc` větve), po loginu approve. Chyba
  approve → hláška + tlačítko zpět na portál. Funguje pro adminy
  i ne-adminy (větev před PortalScreen rozhodováním).
- i18n cs+en, `npm run check:i18n`
  (`PATH=/home/sebik/.nvm/versions/node/v24.14.0/bin:$PATH`).

### Dokumentace

- `docs/hosting.md` §5.4: doplnit approve krok (session bridge) +
  status Fáze 1 po dokončení.
- `docs/auth.md`: krátká podsekce „Shipard ID jako provider" — odkaz
  na hosting.md, tvar `auth.providers` položky pro hosting OP.

## Testy

- `OpKeyStoreTest`: JWK odvození, kid stabilní, podpis ověřitelný
  `JWT::decode` s veřejným klíčem (round-trip bez sítě).
- `HostingOidcControllerTest` — matice:
  - authorize: neznámý/neaktivní klient a redirect mismatch → 400 bez
    redirectu; chybějící nonce/challenge → redirect s `error`; OK →
    transakce + 302;
  - approve: bez session 401 (middleware), cizí/expirovaná/použitá
    txn → 400; OK → kód + správný redirect vč. `state`;
  - token: replay (druhé volání téhož code) → invalid_grant; špatný
    verifier / secret / redirect_uri / expirace → invalid_grant;
    OK → id_token s přesnými claims (dekóduj a assertni iss/aud/sub/
    nonce/email_verified/exp−iat);
  - id_token projde `OidcClient::validateIdToken` ekvivalentní
    kontrolou claims (bez HTTP — JWKS lokálně).
- PHPUnit `--filter 'HostingOidc|OpKeyStore'`.

## E2E na dev serveru (součást tasku)

1. `hosting-oidc-init` na dev hosting DS `gn5c-mm2v-pd5m-u43x`,
   nastavit issuer setting.
2. Na hosting DS: vyplnit `oidc_client_secret` + `oidc_redirect_uri`
   u řádku testovacího klientského DS (dev DS dle výběru).
3. Na klientském DS: `auth.providers` položka (issuer = setting,
   clientId = ds_id, clientSecret, autoLinkEmail dle testu).
4. Ověřit: login tlačítko → portál login → návrat → session na
   klientském DS; SSO druhý průchod bez interakce; `oidc_no_account`
   větev pro nespárovaný účet.

## Commit strategie

1. `hosting: OIDC OP key store + hosting-oidc-init (D2)`
2. `hosting: OIDC OP endpoints — discovery, jwks, authorize, approve, token (D2, D12)`
3. `hosting: SPA op_auth approval flow (D10)`

## Hotovo když

- [x] Discovery + JWKS odpovídají issueru ze settingu; RP
      `OidcDiscovery` je přijme (4 povinné klíče, issuer match)
- [x] End-to-end login z klientského DS přes hosting funguje vč. SSO
      průletu a `oidc_no_account` větve
- [x] Kód je single-use (replay → invalid_grant), PKCE a client_secret
      se vynucují, redirect_uri exact match, žádný redirect na
      neověřenou adresu
- [x] id_token: RS256 s kid, claims přesně dle RP validace
      (iss/aud/exp±60s/nonce/email_verified)
- [x] Privátní klíč jen v `secrets/` (0600), nikdy v DB ani logu;
      `oidc_client_secret` se nevrací ve form/API odpovědích
      (sensitive)
- [x] Testy zelené, i18n check zelený, dokumentace aktualizovaná

## Poznámky k implementaci (2026-08-05)

Vědomé odchylky/doplňky nad rámec zadání:

1. **CLI `hosting-oidc-client`** (`--ds --redirect-uri --generate|--secret`)
   — zadání mechanismus plnění `oidc_client_secret` nespecifikovalo
   (sensitive sloupec formulář nepustí). Šifrování přes nový
   `HostingDataSourceDocument::beforeSave` (konvence encrypted_text);
   `--generate` vytiskne secret jednou — rozhraní pro F2 agenta.
2. **`adminOnly` flag na settings pages** — mechanismus appSettings neměl
   admin check a hosting DS má z definice ne-admin portálové uživatele,
   kteří by jinak mohli issuer číst i přepsat. `ModuleDefinition` parse +
   403 v `SettingsController::page/savePage` + skrytí z navigace.
3. **docState check klienta v authorize** (`[10, 40, 80]`, konzistence
   s `HostingPortalController`) — archivovaný klient se nesmí autentizovat.
4. Chybějící `state` v authorize error redirectu se vynechá (RP na
   neznámém state stejně bezpečně selže).

E2E ověřeno na dev serveru curl průchodem (gn5c hosting, klient 4l3j):
plný login vč. exchange, SSO druhý průchod, `oidc_no_account` větev,
replay kódu → `invalid_grant`. Proklik SPA obrazovky `OpAuthScreen`
v prohlížeči zatím ručně neproběhl (flow kryjí node testy + PHP matice).
