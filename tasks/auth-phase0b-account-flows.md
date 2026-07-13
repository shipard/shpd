# Auth Fáze 0b — pozvánky, reset hesla, změna hesla, správa sessions

## Kontext

Lokální účty dnes nemají žádnou samoobsluhu: heslo nastavuje admin při
CLI vytvoření uživatele a nikdy nejde změnit, zapomenuté heslo nemá
řešení, uživatel nevidí své relace. Tento task doplňuje kompletní
hygienu lokálních účtů nad odchozí poštou (mail-outbound) a admin
modelem (0a).

**Potvrzená rozhodnutí:**

- **D19 — tokeny:** jedna tabulka `core_system_auth_tokens` s `purpose`
  (`invite` | `password_reset`), v DB pouze SHA-256 hash (vzor API
  klíče), single-use, TTL reset 1 h / pozvánka 7 dní. Pozvánka je
  technicky reset s delším TTL a jinou šablonou — jeden mechanismus,
  jedna landing page.
- **D20 — anti-enumerace:** forgot endpoint vrací **vždy 200**; vstup
  login nebo e-mail; e-mail se shodou na více účtech → mail per účet
  (v každém uveden login).
- **D21 — politika hesel:** min. 12 znaků, nesmí se rovnat loginu, nic
  dalšího (komplexitní pravidla jsou security theater). Reset zneplatní
  všechny sessions uživatele; změna hesla všechny kromě aktuální.

## Návaznost

- **Vyžaduje 0a** (is_admin — invite akce; sensitive — nic nového) a
  **mail-outbound** (`MailOutboxService::enqueueAndSend()`).
- Set-password obrazovka reusuje startup vzor z OIDC handoff
  (`?login=oidc&code=` v App.svelte) — stejná mechanika, jiný parametr.
- `SessionService` (z OIDC fáze) dostane hromadné invalidace.

## Před implementací přečti

- `src/Api/SessionService.php`, `src/Api/Controller/AuthController.php`.
- `src/Api/Controller/OidcController.php` — vzor public browser-flow
  endpointů + chybové redirecty; `src/Api/Router.php` + `public/
  index.php` dispatchAuth.
- `src/Core/Security/ApiKeyService.php` — vzor hashovaných tokenů.
- `src/Core/Mail/MailOutboxService.php` (po mail-outbound tasku).
- `frontend/src/App.svelte` — OIDC handoff startup větev;
  `frontend/src/components/auth/LoginScreen.svelte`;
  `frontend/src/components/account/` — navigace účtu (`/_ui/account/*`).
- `docs/edit-forms.md` — custom akce ve formuláři (invite tlačítko);
  pokud formulářový systém custom akce zatím nepodporuje, fallback viz
  níže.

## Scope

1. Tabulka tokenů + `AuthTokenService`.
2. Endpointy: forgot / reset / change password, invite, sessions
   (list + revoke).
3. Mailové šablony (pozvánka, reset) cs/en s minimálním rendererem.
4. Frontend: forgot form, set-password obrazovka, sekce účtu (změna
   hesla, moje relace), invite akce v detailu uživatele.

**Non-goals:** e-mail verifikace při změně adresy; 2FA/passkeys; expirace
hesel; historie hesel; interaktivní OIDC linking (samostatný follow-up);
notifikace „přihlášení z nového zařízení".

## Schéma a služby

**`core_system_auth_tokens`** (tableId **426**): `id` PK AI, `purpose`
varchar(20) NOT NULL, `user_id` int NOT NULL (idx), `token_hash` char(64)
NOT NULL (idx unique), `created` datetime NOT NULL, `expires` datetime
NOT NULL, `used_at` datetime NULL. `module.jsonc` core.system: registrace
+ `keepOnReset`.

**`src/Core/Auth/AuthTokenService.php`** — `issue(int $userId, string
$purpose): string` (plaintext `shpd_pt_` + 32 B urlsafe, uloží hash;
existující nepoužité tokeny stejného purpose+user zneplatní — poslední
mail platí); `consume(string $token, string $purpose): int` (user_id;
validace hash+purpose+expirace+used_at, označení used v jedné transakci
— single-use i při souběhu); `prune()` pro expirované (přidat do
`AlertsPrune`-style úklidu či vlastní volání z `mail-outbox-run`? →
jednoduše: úklid v rámci `consume` miss větve + CLI není třeba).

**`SessionService`** — `invalidateAllForUser(int $userId): int`,
`invalidateOthers(int $userId, string $currentToken): int`.

## Změny po souborech

### Commit 1 — tokeny, endpointy, šablony

**`src/Api/Controller/PasswordController.php`** (nový):
- `forgot(Request)` — public. Vstup `{identifier}` (login nebo e-mail).
  Lookup: přesná shoda loginu, jinak všechny aktivní účty s daným
  e-mailem. Pro každý nalezený účet s neprázdným e-mailem: issue token
  + `enqueueAndSend()` šablony reset. **Vždy** `{"status": "ok"}`,
  žádný rozdíl v odpovědi ani času (mail jde přes outbox, ne inline
  SMTP). Účty `is_system` a bez e-mailu se tiše přeskočí.
- `reset(Request)` — public. `{token, password}`: politika (≥12 znaků,
  ≠ login case-insensitive) → 400 `PASSWORD_POLICY`; consume → 400
  `INVALID_TOKEN` (jednotně pro neplatný/expirovaný/použitý); set
  bcrypt hash; `invalidateAllForUser`; 200.
- `change(Request)` — authenticated. `{currentPassword, newPassword}`:
  účet s `password_hash NULL` → 400 `NO_LOCAL_PASSWORD` (OIDC/JIT
  účet); špatné současné heslo → 401; politika; set hash;
  `invalidateOthers` (aktuální session zůstává); 200.
- `invite(Request, int $userId)` — **admin only** (is_admin z 0a).
  Cílový uživatel musí být aktivní a mít e-mail (jinak 400
  `NO_EMAIL`). Issue token purpose `invite` (TTL 7 dní) +
  `enqueueAndSend()` šablony pozvánka. Funguje i opakovaně
  (přeposlání — starý token se zneplatní v issue()).
- `sessions(Request)` / `sessionDelete(Request, int $id)` /
  `sessionsRevokeOthers(Request)` — authenticated; list vlastních
  sessions (`id, created, expires, ip_address, current` — current
  podle tokenu z kontextu, **token samotný se nikdy nevrací**); delete
  jen vlastní session (cizí id → 404, žádný leak existence); revoke
  others přes `SessionService`.

**`src/Api/Router.php` + `public/index.php`** — routy: `POST
/_auth/password/forgot|reset|change`, `POST /_users/{id}/invite`,
`GET /_auth/sessions`, `DELETE /_auth/sessions/{id}`, `POST
/_auth/sessions/revoke-others`; dispatch wiring.

**`AuthMiddleware::isExempt()`** — += `passwordForgot`, `passwordReset`.
**`RateLimitMiddleware`** — login-class limit (10/min/IP) pro forgot
i reset.

**Šablony** — `src/Core/Mail/MailTemplate.php`: minimální renderer
(soubor + strtr placeholders, text i html varianta). Šablony
`modules/core/system/mail/{cs,en}/invite.{txt,html}` a
`reset.{txt,html}`; placeholders `{full_name}`, `{login}`, `{ds_name}`,
`{link}`, `{ttl}`. Jazyk dle DS (`DataSourceConfig`). Link:
`https://{host}/app/?auth_action=set-password&token={t}` (host
z requestu, dev prefix jako u OIDC redirectů).

### Commit 2 — frontend

**`frontend/src/App.svelte`** — startup větev `?auth_action=set-password
&token=` → `SetPasswordScreen` (vzor OIDC handoff vč.
`history.replaceState`).

**`frontend/src/components/auth/SetPasswordScreen.svelte`** (nový) —
nové heslo + potvrzení, hint politiky, submit → `POST password/reset`;
úspěch → login s flash „Heslo nastaveno, přihlaste se"; `INVALID_TOKEN`
→ hláška s odkazem na forgot.

**`LoginScreen.svelte`** — odkaz „Zapomenuté heslo?" → inline forgot
form (identifier, submit, vždy „Pokud účet existuje, poslali jsme
e-mail"). Zobrazit jen když politika povoluje lokální login
(`appInfo.auth.local`).

**Sekce účtu** — `ChangePasswordPanel.svelte` (tři pole, hint politiky;
skrýt pro účty bez lokálního hesla — informace z login/me odpovědi) a
`SessionsPanel.svelte` (tabulka: vytvořeno, IP, aktuální badge; revoke
per řádek + „Odhlásit ostatní zařízení") do `/_ui/account/*` navigace.

**Invite akce** — detail uživatele (admin): tlačítko „Poslat pozvánku"
→ `POST /_users/{id}/invite` + toast. Mechanismus custom akce dle
`docs/edit-forms.md`; pokud formulářový systém custom akce zatím
nepodporuje, fallback: akce v settings vieweru uživatelů (řádkové
tlačítko). Implementátor ověří v edit-forms.md a zvolí; nerozšiřovat
formulářový systém nad rámec nutného.

**`frontend/src/api/auth.js`** — funkce forgot/reset/change/invite/
sessions. **i18n cs/en** — klíče pro všechny texty a chybové kódy
(`PASSWORD_POLICY`, `INVALID_TOKEN`, `NO_LOCAL_PASSWORD`, `NO_EMAIL`).

### Commit 3 — dokumentace

`docs/auth.md` — kapitoly: tokeny (životní cyklus, TTL), forgot/reset
flow diagram, invite flow, politika hesel, sessions endpointy;
`docs/rest-api.md` — nové endpointy + exempt; `docs/cli.md` beze změny
(user-create zmínit doporučení: heslo nezadávat, poslat pozvánku).

## Testy

- `AuthTokenServiceTest` — issue/consume, single-use při souběhu
  (transakce), expirace, purpose mismatch, re-issue zneplatní starý,
  v DB jen hash.
- `PasswordControllerTest` — forgot: neznámý identifier → 200 bez
  outbox řádku; login match → 1 mail; e-mail match na 2 účtech → 2
  maily s odlišnými tokeny; is_system/bez e-mailu přeskočeny. reset:
  úspěch + všechny sessions pryč; použitý/expirovaný token → 400;
  politika. change: špatné současné → 401; NULL hash → 400; ostatní
  sessions pryč, aktuální žije. invite: ne-admin → 403; bez e-mailu →
  400; re-invite zneplatní starý token. sessions: list bez tokenů,
  current flag, delete cizí → 404, revoke-others.
- Frontend: SetPasswordScreen flow, forgot form, panely účtu.

## Commit strategie

1. `auth: token service, password forgot/reset/change, invite, sessions API`
2. `auth: set-password screen, forgot form, account panels, invite action`
3. `auth: docs for account flows`

Po commitu 1: rebuild compiled cfg + `ds-upgrade` (nová tabulka).

## Hotovo když

- [ ] End-to-end na dev: `user-create` bez hesla → invite mail →
      set-password → login → změna hesla → ostatní relace odhlášeny.
- [ ] Forgot je časově i obsahově neodlišitelný pro existující a
      neexistující účet; token v DB jen jako hash; druhý pokus o použití
      tokenu → 400.
- [ ] Reset zneplatní všechny sessions (ověřeno 401 na starém tokenu).
- [ ] OIDC/JIT účet bez hesla: change vrací `NO_LOCAL_PASSWORD`, UI
      panel se nezobrazí, invite/reset mu heslo nastaví (a tím získá
      lokální login, pokud to politika DS povoluje).
- [ ] Rate limity na forgot/reset aktivní.
- [ ] PHPUnit + frontend testy zelené (úzké filtry), `check:i18n`
      prochází, docs aktualizované.
