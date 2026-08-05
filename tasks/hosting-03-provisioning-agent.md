# Hosting — Task 2: Provisioning agent (Fáze 2)

**Stav:** hotovo — 2026-08-05; odchylky a poznámky k implementaci na konci
souboru

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D3** (+ D2/D12 pro zápis `auth.providers`), kontrakty
> §5.1 a §5.2. Fáze 2 z §8: nový DS vznikne z portálu bez ručního
> zásahu na serveru.

## Kontext

Pull agent `shpd-server hosting-sync` (cron na DS serveru): rekonciliace
→ fronta požadavků → lokální založení DS → confirm. Hosting nikdy
nevolá server; výpadek agenta nic nerozbije. Mail-router a AI kroky
z §5.2 jsou Fáze 3/4 — smyčka agenta je ale navržená tak, aby se do ní
jen přidaly další kroky.

**Vědomé volby (potvrzeno v chatu):**

1. **`ds_id` generuje hosting** při vytvoření požadavku (logika
   `IdGenerator::generateRandom`, unikátnost proti vlastní evidenci);
   `ds-create` dostane `--ds-id`. Hosting tak zná ID, doménu i OIDC
   client_id **před** založením — confirm je jen překlopení stavu
   a opakovaný běh agenta je přirozeně idempotentní (existence
   adresáře = kroky založení přeskočit).
2. **Klíče serverů mají vlastní prefix `shpd_hk_`** a validuje je
   hosting controller sám (sloupce hash + prefix na
   `hosting_core_servers`, endpointy exempt) — `core_system_api_keys`
   jsou vázané na uživatele a `AuthContext` nenese identitu klíče;
   vlastní validace drží vazbu klíč ↔ řádek serveru bez umělých
   strojových uživatelů. Vzor `AuthMiddleware::handleApiKey`.
3. **Vlastník nového DS** (U1+U2, potvrzeno v chatu): vlastník je
   **FK na `core_system_users` hostingu** (výběr ve formuláři, žádný
   volný text — překlep e-mailu nesmí odříznout vlastníka od DS).
   Agent na novém DS založí lokální admin účet bez hesla
   (`user-create --admin`, login/e-mail/jméno z účtu vlastníka)
   a **rovnou předpropojí identitu** (`core_system_user_identities`:
   issuer OP + sub = id vlastníka na hostingu) — první login je
   deterministický i po změně e-mailu. `auth.providers` proto
   s `autoLinkEmail: false` — těsnější výchozí politika, žádné
   automatické párování dalších účtů podle e-mailu, dokud ho admin DS
   vědomě nezapne. Při confirmu `ok` hosting založí vazbu
   `hosting_core_ds_users` (role `admin`) → DS se vlastníkovi ihned
   ukáže na portálu. Žádná závislost na odchozí poště (invite flow)
   — ta na čerstvém DS ještě není.

## Cíl

1. `server.json` sekce `hosting` (§5.1) v `ServerConfig`.
2. Hosting API: `/_hosting/server/reconcile|queue|confirm` + klíče
   serverů (`shpd_hk_`, CLI `hosting-server-key`).
3. Požadavek na nový DS: admin formulář → řádek `lifecycle = request`
   (beforeSave generuje ds_id, secret, doménu); setting
   `hosting.baseDomain`.
4. Agent `shpd-server hosting-sync`: rekonciliace + provisioning smyčka
   (ds-create → ds-upgrade → domain-add → auth.providers → owner) +
   confirm; zapojení do server cronu.
5. `ds-create --ds-id`, `user-create --if-not-exists` +
   `--identity-issuer/--identity-subject` (předpropojení identity).
6. E2E na dev serveru: požadavek z portálu → funkční DS s loginem přes
   hosting.

## Před implementací přečti

- `docs/hosting.md` §5.1, §5.2, §7
- `src/Core/Config/ServerConfig.php` — load/validace, kam přidat sekci
- `src/Command/Server/DsCreateCommand.php` — celý (main.json zápis,
  IdGenerator, DB založení); `src/Core/Utils/IdGenerator.php`
- `src/Command/Server/DsUpgradeAllCommand.php` — subprocess vzor
  (`cd {dsDir} && shpd-ds …`, passthru) — agent volá stejně
- `src/Command/Server/DomainAddCommand.php` + domains.json — ověř
  chování při existujícím záznamu; když není idempotentní (stejný
  host→ds = no-op, jiný ds = chyba), udělej ho tak
- `src/Command/Server/CronCommand.php` + `CronInstallCommand.php` —
  jak se registrují periodické úlohy; hosting-sync zapoj dle vzoru
- `src/Command/DataSource/UserCreateCommand.php` — pro `--if-not-exists`
  a identity options
- `modules/core/system/tables/core_system_user_identities.jsonc` +
  `src/Core/Auth/IdentityMapper.php` — tvar řádku identity (unikát
  `(issuer, subject)`), který `user-create` založí
- `src/Api/Middleware/AuthMiddleware.php` — `handleApiKey` (vzor hash
  validace pro `shpd_hk_`), `isExempt`
- `src/Command/DataSource/HostingOidcClientCommand.php` +
  `modules/hosting/core/src/HostingDataSourceDocument.php` (z F1) —
  šifrování `oidc_client_secret`, beforeSave konvence
- `src/Core/Auth/OidcClient.php` — `performHttpPost` (vzor HTTP
  klienta s timeouty pro agenta)
- `src/Core/Auth/OidcProviderConfig.php` — přesný tvar položky
  `auth.providers` (id `[a-z0-9-]+`, povinné klíče)
- `docs/cli.md` — kam doplnit nové commandy/options

## Změny po souborech

### Schéma a konfigurace (hosting DS)

**`hosting_core_servers.jsonc`** — aditivní sloupce: `api_key_prefix`
(varchar 12, nullable), `api_key_hash` (varchar 64, nullable,
`sensitive: true`), `last_seen` (datetime, nullable), `last_version`
(varchar 30, nullable), `can_provision` (bool, default false).

**`hosting_core_data_sources.jsonc`** — aditivní sloupce: `owner`
(int FK → core_system_users, nullable — U1), `provision_error`
(text, nullable), `claimed_at` (datetime, nullable).

**`hosting.core.dsLifecycle`** cfgItem: přidat hodnotu `failed`.

**Settings** (stránka z F1): nové pole `hosting.baseDomain`
(text; např. `shpd.dev` — z `web_id` se skládá `{web_id}.{baseDomain}`).

Po změnách rebuild compiled cfg + `ds-upgrade` na dev hosting DS.

### `HostingDataSourceDocument::beforeSave` (rozšíření z F1)

Insert s `lifecycle = request`: vygenerovat `ds_id` (pokud prázdné;
formát IdGeneratoru, unikátnost SELECTem do evidence),
`oidc_client_secret` (pokud prázdný; random 43, šifrovat — konvence
z F1), a z `web_id` + `hosting.baseDomain` odvodit `url_app`
(`https://{web_id}.{baseDomain}`) a `oidc_redirect_uri`
(`{url_app}/api/v1/_auth/oidc/callback`), pokud nevyplněné. Validace:
request vyžaduje `web_id`, `server`, `install_module`, `owner`
(existující aktivní uživatel hostingu).

### `src/Command/DataSource/HostingServerKeyCommand.php` (nový)

`shpd-ds hosting-server-key --server <ndx> --generate`: vygeneruje
`shpd_hk_` + 43 random znaků, uloží prefix (12) + sha256 hash na řádek
serveru, **vytiskne jednou** (vzor `hosting-oidc-client --generate`).
`--revoke` vynuluje. Registrace v `bin/shpd-ds`.

### `src/Api/Controller/HostingServerController.php` (nový)

Gating: chybí tabulka → 404. Vlastní autentizace (endpointy exempt):
`Authorization: Bearer shpd_hk_…` → prefix lookup + `hash_equals`
sha256 (vzor `handleApiKey`), nalezený řádek serveru = identita;
selhání → 401. Update `last_seen`. Akce:

- **`reconcile`** (POST): body `{version, dataSources: [{ds_id, name,
  modules}]}`. Uloží `last_version`; DS v evidenci (tento server)
  chybějící v inventuře a naopak → warning do logu hostingu (F2 jen
  loguje, žádné automatické akce). Response `{ok: true}`.
- **`queue`** (GET): řádky `server = <tento>` AND `lifecycle IN
  (request, creating)` (creating = retry po pádu agenta). Served
  requesty: `lifecycle = creating`, `claimed_at = now`. Payload per
  item: `{request_id: ndx, ds_id, name, install_module, web_id,
  host, owner: {email, name, sub}, oidc: {issuer, client_id: ds_id,
  client_secret, label}}` — owner data se čtou z účtu vlastníka při
  servírování (`sub` = (string) jeho id, přesně co OP dává do
  id_tokenu), issuer ze settingu (D12), secret
  dešifrovaný (jediné místo, kde opouští hosting — https, jednorázově
  při provisioningu). `can_provision = false` → prázdná fronta.
- **`confirm`** (POST): `{request_id, ds_id, status: "ok"|"failed",
  error?}`. Kontrola: řádek patří serveru, ds_id sedí. `ok` →
  `lifecycle = active`, `provision_error = NULL` + **INSERT
  `hosting_core_ds_users`** (user = owner, role `admin`; idempotentně
  přes unikát `(user, data_source)`) — U1, DS se vlastníkovi objeví
  na portálu; `failed` →
  `lifecycle = failed`, `provision_error = error`. Idempotentní
  (opakovaný confirm téhož výsledku = no-op). Retry po `failed`:
  admin přepne lifecycle zpět na `request`.

Router + index.php dispatch + `isExempt` pro `hostingServer` akce
(auth si dělá controller). Rate limit netřeba (klíč + malý povrch),
ale limituj velikost body (vzor jinde v dispatch).

### `src/Core/Config/ServerConfig.php`

Volitelná sekce `hosting` (§5.1): `url` (https), `serverId` (int ndx
řádku serveru — informativní/log), `apiKey` (`shpd_hk_…`). Getter
vrací malý readonly objekt nebo null; validace až při použití
(chybějící sekce nesmí rozbít ostatní commandy).

### `src/Command/Server/HostingSyncCommand.php` (nový) + `src/Core/Server/HostingSyncRunner.php`

`shpd-server hosting-sync` (+ `--dry-run` — vypíše frontu bez akcí).
Bez sekce `hosting` v server.json → informativní exit 0. Běh:

1. **Reconcile** — inventura z `data-sources/*/config/main.json`
   (id, name, modules) + verze; POST.
2. **Queue** — GET; pro každý požadavek postupně (pokračuj dalším
   i po chybě předchozího):
   a. `data-sources/{ds_id}` neexistuje → subprocess
      `shpd-server ds-create --ds-id … --name … --module …`;
      existuje → skip (idempotence).
   b. `cd {dsDir} && shpd-ds ds-upgrade` (subprocess, vzor
      DsUpgradeAll).
   c. `shpd-server domain-add --host {host} --ds {ds_id}`
      (idempotentní dle úpravy výše).
   d. **auth.providers**: read-modify-write `main.json` — položka
      `{id: "shipard-id", label, issuer, clientId, clientSecret,
      autoLinkEmail: false}` (U2 — identita se předpropojuje; merge
      dle `id`, replace existující;
      zachovat ostatní klíče beze změny, zápis atomicky temp+rename,
      chmod 0600). Implementuj jako čistou funkci
      (`MainConfigPatcher::mergeAuthProvider(array $config, …): array`)
      — unit-testovatelné.
   e. `cd {dsDir} && shpd-ds user-create --login {owner.email}
      --email {owner.email} --name {owner.name} --admin
      --if-not-exists --identity-issuer {oidc.issuer}
      --identity-subject {owner.sub}` (U2).
   f. Vše OK → confirm `ok`; jakákoli chyba → confirm `failed`
      s výstižnou zprávou (stderr posledního kroku, oříznuto).
3. Stats push = Fáze 5 (do runneru jen komentář-kotva).

HTTP: malý klient (vzor `OidcClient::performHttpPost` — curl, timeout
10 s, jen https; http pro localhost dev). Logování průběhu na stdout
(cron log).

**Cron**: zapoj `hosting-sync` do `shpd-server cron` dle existujícího
vzoru periodických úloh (interval ~2 min).

### `src/Command/Server/DsCreateCommand.php`

Nová option `--ds-id` (validace `^[a-z0-9]{4}(-[a-z0-9]{4}){3}$`,
existující adresář → chyba); bez ní chování beze změny (IdGenerator).

### `src/Command/DataSource/UserCreateCommand.php`

- Nová option `--if-not-exists`: login už existuje → info + exit 0.
- Nové options `--identity-issuer` + `--identity-subject` (jen spolu):
  po založení/nalezení uživatele zajistí řádek
  `core_system_user_identities` `(issuer, subject)` → user (INSERT
  pokud chybí; existující vazba na **jiného** uživatele → chyba).
  S `--if-not-exists` idempotentní: existující user → identita se
  jen doplní. Bez nových options beze změny chování.

### Dokumentace

- `docs/cli.md`: `hosting-sync`, `hosting-server-key`, `--ds-id`,
  `--if-not-exists`.
- `docs/operations/` příp. `docs/hosting.md` §5.1/§5.2: skutečný tvar
  payloadů + postup „připojení serveru k hostingu" (založit řádek
  serveru → vygenerovat klíč → server.json → cron); status Fáze 2.

## Testy

- `HostingServerControllerTest`: auth matice (chybějící/špatný/
  revokovaný klíč → 401); queue vrací jen požadavky svého serveru,
  překlápí request→creating + claimed_at, `can_provision=false` →
  prázdná; confirm ok/failed přechody + idempotence + cizí request →
  404/403.
- `HostingDataSourceDocumentTest` (rozšíření): beforeSave generuje
  ds_id/secret/url_app/redirect_uri, respektuje předvyplněné,
  validace povinných polí requestu vč. `owner` (FK na aktivního
  uživatele).
- Confirm `ok` zakládá `hosting_core_ds_users` (a opakovaný confirm
  nezaloží duplicitu).
- `MainConfigPatcherTest`: merge nového providera, replace dle id,
  zachování cizích klíčů, žádná mutace nesouvisejících sekcí.
- `IdGenerator` formátová validace `--ds-id` (test DsCreate na úrovni
  validace option).
- `UserCreateCommand`: `--if-not-exists` (duplicitní login → exit 0);
  identity options — nový user + identita, existující user + doplnění
  identity, kolize identity s jiným userem → chyba.
- PHPUnit `--filter 'HostingServer|MainConfigPatcher|HostingDataSource'`.

## E2E na dev serveru (součást tasku)

1. Na gn5c: řádek serveru (dev server, `can_provision = true`),
   `hosting-server-key --generate`; nastavit `hosting.baseDomain`.
2. Na dev serveru: `server.json` sekce `hosting` (url gn5c, klíč).
3. Admin formulář: nový požadavek (name, web_id, server,
   `install.base`, owner = testovací hosting uživatel — výběr, ne
   text).
4. `shpd-server hosting-sync` ručně → ověřit: DS založen, doména
   v domains.json, `auth.providers` (`autoLinkEmail: false`)
   v main.json, owner admin účet **s předpropojenou identitou**;
   lifecycle `active` na hostingu a vazba v `hosting_core_ds_users`
   → nový DS vidět na portálu vlastníka.
5. Login na nový DS přes „Přihlásit přes {hosting}" — průchod přes
   `(issuer, sub)` lookup bez autoLinku, admin práva (tím se
   prokliká i `OpAuthScreen` z F1); jiný hosting uživatel →
   `oidc_no_account`.
6. Druhý běh hosting-sync → žádné duplicity (idempotence);
   simulovaná chyba (např. obsazená doména) → `failed` +
   `provision_error`, po opravě retry přes `request`.

## Commit strategie

1. `hosting: server API — reconcile/queue/confirm + shpd_hk_ keys (D3)`
2. `hosting: DS request lifecycle — beforeSave generation, baseDomain setting (D3)`
3. `server: hosting-sync agent + ds-create --ds-id + user-create --if-not-exists (D3)`

## Hotovo když

- [x] Nový DS vznikne z admin formuláře na portálu bez ručního zásahu
      na serveru — včetně domény, `auth.providers` a owner admin účtu
      s předpropojenou identitou
- [x] Owner se na nový DS přihlásí přes hosting OP (lookup
      `(issuer, sub)`, bez autoLinku) a má admin práva; nový DS vidí
      na svém portálu (vazba ds_users z confirmu)
- [x] Opakovaný běh agenta je idempotentní (žádný druhý DS, žádná
      duplicitní doména ani provider položka)
- [x] Pád agenta uprostřed → požadavek zůstane `creating` a další běh
      ho dokončí; chyba → `failed` + `provision_error`, retry přes
      `request` funguje
- [x] Klíč serveru je na hostingu jen jako hash+prefix; client_secret
      opouští hosting jedině v queue payloadu přes https
- [x] `main.json` po patchi: zachované ostatní klíče, mode 0600
- [x] Server bez sekce `hosting` v server.json: všechny commandy beze
      změny chování
- [x] Testy zelené, `docs/cli.md` + hosting.md aktualizované

## Poznámky k implementaci (odchylky od zadání)

1. **Dry-run přes `queue?peek=1`** — GET queue jinak frontu překlápí na
   `creating`; peek nepřeklápí, neoznačuje nesplnitelné požadavky
   `failed` a payload neobsahuje `client_secret`.
2. **Cron**: nový slot `two-minutes` + registr server-level jobů
   `CronCommand::SERVER_SLOT_JOBS` (běží `shpd-server <cmd>` jednou za
   slot, ne per DS). `CronProvisioner::TEMPLATE_VERSION` → 2 — na
   serverech je po nasazení potřeba `cron-install` (ohlásí i doctor).
3. **Owner pole = `select`** s přednačtenými options (vzor DsUsersForm),
   ne lookup — `LookupController` nemá `TableAccessGuard`, lookup na
   `core_system_users` by vystavil seznam uživatelů ne-admin portálovým
   účtům.
4. **`user-create` má navíc `--identity-provider`** (default `oidc`) —
   sloupec `provider` v `core_system_user_identities` je NOT NULL;
   agent posílá `shipard-id` (shodné s `auth.providers[].id`).
5. **Zadání odkazovalo na „vzor limitu body v dispatch" — žádný
   neexistoval**; check content-length (512 KB → 413) je zavedený nově
   v `dispatchHostingServer()`.
6. **`domain-add` + `domain-remove` zapisují domains.json atomicky**
   (tmp + rename) — soubor čte `DataSourceResolver` při každém requestu.
7. Nesplnitelný požadavek ve frontě (chybějící owner, nedešifrovatelný
   secret, url_app bez hosta) jde rovnou do `failed` + `provision_error`
   — nezůstává viset jako věčný `request`. Chybějící issuer setting
   frontu jen pozdrží (misconfigurace hostingu, ne chyba požadavků).
8. **E2E na dev boxi (2026-08-05)**: plný průchod — požadavek přes
   Document → runner (reálné HTTP + subprocesy) → DS `vlm9-ynfy-8hbe-3ipe`
   založen, provider zapsán, owner admin s identitou; login přes OP
   curl-em vč. exchange (admin práva), `oidc_no_account` pro cizího
   uživatele, idempotence po překlopení na `creating`, failed + retry
   přes `request`. Dvě odchylky prostředí: (a) `/etc/shipard` je na dev
   boxi root-only → sekce `hosting` v server.json a zápis
   `/etc/shipard/domains.json` se testovaly přes harness s injektovanou
   cestou (reálný `DomainAddCommand` kód); kroky vyžadující root zbývá
   proklepnout na skutečném serveru dle `docs/cli.md` scénáře 8.
   (b) Setting `hosting.oidc.issuer` byl na gn5c přepnutý na LAN IP
   (`http://10.199.6.215/…`), což **rozbíjelo login 4l3j** —
   `isAllowedIssuerUrl` pouští http jen pro localhost; vráceno na
   `http://127.0.0.1/…` (odpovídá identitám z F1 E2E) a opraven issuer
   v `auth.providers` na 4l3j. Poznámka: `DomainAddCommand` ignoruje
   `domainsFile` override ze server.json, který HTTP resolver
   respektuje — drobný dluh na někdy.
