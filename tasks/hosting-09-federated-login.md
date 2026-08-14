# Hosting 09 — Federované přihlašování (Google, GitHub) + návrat do OP transakce

**Stav:** částečně — kód + testy + docs hotové 2026-08-14 (commity 1–3); zbývá ds-upgrade na hostingu, zapnutí Google/GitHub na alfě dle runbooku (mutace — David) a ruční E2E řetěz
**Návaznost:** docs/auth.md (RP strana, IdentityMapper), docs/hosting.md
§5.4 (OIDC OP, session bridge D10), hosting-07 (OpAuthScreen v shellu).

## Cíl

Hosting DS se sám stane relying party vůči externím IdP (Google config-only,
GitHub přes nový provider kind), takže uživatel se na hosting — a skrze
Shipard Id do všech svých DS — přihlásí bez hesla. Klientské DS se nemění
a o federaci nevědí.

Řetěz: DS → Shipard Id (hosting OP) → Google/GitHub. Scénář „aktivní
hosting session → průlet" funguje dnes; scénář „bez session → login
heslem → návrat do DS" funguje dnes. Chybí: **návrat do rozjednané OP
transakce po plné navigaci na externího IdP** (in-memory `opAuth` store
navigaci nepřežije — uživatel dnes skončí v portálu místo v DS).

## Schválená rozhodnutí (2026-08-14)

| # | Rozhodnutí |
|---|---|
| D1 | Návrat = **server-side průnos** (varianta b): RP transakce (`core_system_auth_transactions`) dostane sloupec `return_to`; `/_auth/oidc/start` přijme parametr `return`, callback ho po úspěchu připojí k handoff redirectu. Hodnota = **query-suffix** (tvar `?klic=hodnota…`), striktní validace na startu i v callbacku (žádný open redirect — nikdy plná URL). |
| D2 | OP flow kontinuita: LoginScreen při pending `opAuth.txn` staví start URL s `return=?op_auth={txn}`; callback vrátí `/app/?login=oidc&code={handoff}&op_auth={txn}`; main.js obslouží **kombinovaný** příchod (exchange handoff + naplnění opAuth store) → App ukáže OpAuthScreen → approve → návrat do DS. Hosting OP txn TTL 600 s zůstává (výlet k IdP pokryje); RP `TRANSACTION_TTL_SECONDS` ověřit ≥ 600, jinak zvednout. |
| D3 | GitHub = nový `kind: "github"` na `OidcProviderConfig` (default `"oidc"`, chování beze změny). GitHub nemluví OIDC: bez discovery/id_tokenu/JWKS; vlastní `GithubOauthClient` (authorize + token endpoint + `/user` + `/user/emails`). Identita: `issuer = "https://github.com"` (syntetický, stabilní), `subject` = **numerické `user.id`** (login se dá přejmenovat), `emailVerified` z primárního ověřeného e-mailu. `IdentityMapper` beze změny (dostane standardní `OidcIdentity`). |
| D4 | Politika obou providerů na hostingu: `autoLinkEmail: true`, `jitProvision: false` — externí IdP je jen způsob přihlášení **existujících** účtů (vzniklých pozvánkou/self-servicem), nikoli otevřená registrace. |
| D5 | Apple mimo scope (ES256 JWT secret, form_post — samostatný task, bude-li třeba). Lokální login heslem na hostingu zůstává zapnutý (vypnutí `local: false` je provozní rozhodnutí, ne součást tasku). |

## Scope

**Patří sem:** sloupec `return_to` + start/callback úpravy
(`OidcController`), frontend kontinuita (oidc.js, LoginScreen, main.js,
opAuth store), `kind` na provider configu, `GithubOauthClient`, testy,
docs (auth.md — return_to a GitHub kind; hosting.md — runbook zapnutí
Google/GitHub na hostingu).

**Nepatří sem:** změny hosting OP (`HostingOidcController` beze změny),
klientské DS, Apple, správa identit v UI, vypínání lokálního loginu.

## Fáze A — návrat do OP transakce (D1, D2)

### A1. `modules/core/system/tables/core_system_auth_transactions.jsonc`

Nový sloupec `return_to` (varchar 200, nullable). Po nasazení
`ds-upgrade` (tabulka je na všech DS; pro funkci stačí hosting DS,
ale sloupec je neškodný všude).

### A2. `src/Api/Controller/OidcController.php`

- **`start()`**: query param `return` → validace
  `self::isValidReturnTo()` (viz níže); nevalidní → ignorovat (log
  warn), validní → uložit do `return_to` transakce.
- **`callback()`** (úspěšná větev, po vystavení handoff kódu):
  `return_to` z transakce **znovu validovat** (defense in depth —
  hodnota mohla vzniknout starším kódem) a připojit:
  `?login=oidc&code={handoff}` + `'&' . substr($returnTo, 1)`.
- **`isValidReturnTo(string $v): bool`** (privátní statická):
  začíná `?`, délka ≤ 200, celé matchuje
  `^\?[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+(&[A-Za-z0-9_\-]+=[A-Za-z0-9_\-]+)*$`
  — jen klíč=hodnota páry bez URL-významových znaků; žádné cesty,
  žádná URL, žádné procentové kódování. (Pro `?op_auth={txn}` stačí;
  budoucí použití ať pravidlo rozšíří vědomě.)
- `TRANSACTION_TTL_SECONDS`: ověřit hodnotu; je-li < 600, zvednout na
  600 (konzistence s hosting OP TXN_TTL — uživatel na straně IdP může
  otálet).

### A3. `frontend/src/api/oidc.js`

`buildOidcStartUrl(apiBaseUrl, providerId, returnTo = null)` — třetí
volitelný parametr, připojí `&return=` + `encodeURIComponent`. Pure
helper, doplnit node:test případy.

### A4. `frontend/src/components/auth/LoginScreen.svelte`

Tlačítka providerů: když `opAuth.txn` není null, předat
`returnTo = '?op_auth=' + opAuth.txn`. Jinak beze změny.

### A5. `frontend/src/main.js` + `frontend/src/stores/opAuth.svelte.js`

Kombinovaný příchod `?login=oidc&code=…&op_auth=…`: dnešní větve jsou
vzájemně výlučné (`!oidcRedirect`) — upravit tak, aby OIDC handoff
větev po úspěšném `exchangeOidc()` navíc naplnila `opAuth.set(txn)`,
je-li `op_auth` v URL přítomné. `history.replaceState` čistí oba
parametry (dnešní úklid URL zachovat). Neúspěšný exchange → opAuth
nenastavovat (uživatel skončí na LoginScreen s chybou; txn na hostingu
doexpiruje sama).

Výsledný tok: DS → authorize → `?op_auth=T` → LoginScreen → „Přihlásit
přes Google" (`return=?op_auth=T`) → Google → callback →
`/app/?login=oidc&code=H&op_auth=T` → exchange → session + opAuth →
OpAuthScreen → approve → DS s kódem.

## Fáze B — GitHub provider kind (D3)

### B1. `src/Core/Auth/OidcProviderConfig.php`

- Nová property `public string $kind = 'oidc'` (povolené `oidc`,
  `github`; jiné → RuntimeException).
- Pro `kind: "github"`: `issuer` v konfiguraci nepovinný — vynutit
  konstantu `https://github.com` (identity klíč musí být stabilní,
  ne věc konfigurace); `clientId`/`clientSecret` povinné jako dosud;
  default scopes `['read:user', 'user:email']`.

### B2. `src/Core/Auth/GithubOauthClient.php` (nový)

Konstanty: authorize `https://github.com/login/oauth/authorize`,
token `https://github.com/login/oauth/access_token`, API
`https://api.github.com`. Stejné timeouty/https pravidla jako
OidcClient; všechna API volání s `User-Agent: shipard` (GitHub bez něj
vrací 403) a `Accept: application/json`.

- `buildAuthorizeUrl(provider, state, redirectUri)`: client_id,
  redirect_uri, state, scope. **Bez PKCE a nonce** — GitHub OAuth apps
  je nepodporují; CSRF drží `state` (single-use transakce, dnešní
  replay ochrana platí).
- `fetchIdentity(provider, code, redirectUri): OidcIdentity`:
  1. POST token endpoint (code exchange; `error` v odpovědi →
     OidcException `oidc_provider_error`),
  2. GET `/user` → `id`, `login`, `name`,
  3. GET `/user/emails` → první položka `primary && verified`
     (nenalezena → email null, emailVerified false → autoLink
     nenastane a flow skončí `oidc_no_account`, korektní),
  4. `new OidcIdentity('https://github.com', (string) $user['id'],
     $email, $emailVerified, $name ?: $login)`.

### B3. `src/Api/Controller/OidcController.php`

Větvení podle `provider->kind` na dvou místech:
- `start()`: github → `GithubOauthClient::buildAuthorizeUrl` (transakce
  se ukládá stejně; `pkce_verifier`/`nonce` se vygenerují a nepoužijí —
  neškodné, žádná schema změna),
- `callback()`: github → `fetchIdentity` místo
  `exchangeCode + validateIdToken`; dál (IdentityMapper, session,
  handoff, return_to) **společná cesta beze změny**.

## Fáze C — dokumentace a zapnutí

### C1. `docs/auth.md`

- Podsekce „Návrat po loginu (`return_to`)": parametr, validační
  pravidlo, použití OP flow kontinuitou.
- Podsekce „GitHub jako provider": kind, identita (numerické id,
  syntetický issuer), scopes, absence PKCE/nonce, příklad konfigurace:

```json
{
    "id": "github",
    "label": "GitHub",
    "kind": "github",
    "clientId": "<z GitHub OAuth App>",
    "clientSecret": "<z GitHub OAuth App>",
    "autoLinkEmail": true,
    "jitProvision": false
}
```

### C2. `docs/hosting.md` — runbook „Federované přihlašování hostingu"

- Google: OAuth client v Google Cloud Console (Web application,
  redirect URI `https://{hosting-host}/api/v1/_auth/oidc/callback`),
  položka `auth.providers` hosting DS
  (`issuer: "https://accounts.google.com"`, autoLinkEmail true,
  jitProvision false — D4).
- GitHub: OAuth App (Authorization callback URL týž), položka dle C1.
- Poznámka: editace `config/main.json` hosting DS = mutace na alfě
  (per-action schválení, provádí David); žádný restart není třeba
  (config se čte per-request), ale ověřit dle provozních zvyklostí.
- Zmínka D5: lokální login zůstává; případné `local: false` je
  provozní volba.

## Testy

PHPUnit (úzké `--filter`):

1. **isValidReturnTo matice:** `?op_auth=abc` ✓; prázdné, `/cesta`,
   `?a=b&`, `https://…`, `?a=b#f`, `?a=%2F`, >200 znaků, `//x` ✗.
2. **start s return:** validní → uloženo v transakci; nevalidní →
   transakce bez return_to (a start projde).
3. **callback s return_to:** úspěch → redirect obsahuje
   `&op_auth=…`; transakce s nevalidním return_to (podvržený řádek) →
   redirect bez suffixu.
4. **OidcProviderConfig kind:** default oidc; github vynutí issuer
   `https://github.com` (i při odlišné hodnotě v configu); neznámý
   kind → výjimka.
5. **GithubOauthClient::fetchIdentity** (HTTP mock): happy path
   (subject = string numerického id, primary+verified e-mail);
   žádný verified e-mail → emailVerified false; token error →
   OidcException.
6. **Callback větvení kind** (integrační s mockem): github flow
   projde IdentityMapperem na existující identitu.

Frontend node:test: `buildOidcStartUrl` s/bez returnTo;
`parseOidcRedirect` na kombinované URL (vrací i op_auth, dle
implementace A5).

Ruční E2E (dev, poté alfa): plný řetěz DS → Shipard Id → Google →
zpět do DS přihlášený; totéž GitHub; scénář s aktivní session
(beze změny); heslo (regrese).

## Strategie commitů

1. `auth: return_to continuation for OIDC login (op_auth handoff)` (A1–A5, testy 1–3 + frontend)
2. `auth: github oauth provider kind` (B1–B3, testy 4–6)
3. `docs: federated hosting login (google, github) + return_to` (C1, C2)

Po nasazení: `ds-upgrade` (sloupec `return_to`). Zapnutí na alfě =
editace `auth.providers` hosting DS dle runbooku (mutace — provádí
David po schválení; Claude ověří read-only průchod E2E scénářů).

## Hotovo když

- [ ] Z klientského DS: klik na Shipard Id bez hosting session →
      LoginScreen hostingu → „Přihlásit přes Google" → Google →
      návrat **do klientského DS** jako přihlášený (bez dalších kliků).
- [ ] Totéž přes GitHub (identita mapovaná na numerické id).
- [ ] S aktivní hosting session průlet beze změny; login heslem
      v OP flow beze změny (regrese).
- [ ] Google/GitHub identita se propojí jen na existující účet
      (ověřený e-mail); cizí účet skončí `oidc_no_account`, žádný
      JIT (D4).
- [x] Nevalidní `return` parametr nikdy neovlivní redirect
      (žádný open redirect — testy 1–3).
- [x] PHPUnit + node:test zelené (úzké filtry), `check:i18n` prochází
      (nové klíče se nečekají).
- [x] docs/auth.md + docs/hosting.md runbook aktualizované;
      `ds-upgrade` poznamenaný v release krocích.
