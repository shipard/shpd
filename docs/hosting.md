# Shipard — Hosting (modul `hosting.core`)

**Designový dokument.** Centrální správa zdrojů dat: portál pro uživatele
s více DS, centrální identita (vlastní minimální OIDC Provider), automatické
zakládání zdrojů dat, automatické napojení na mail-router a AI gateway.
Vychází z myšlenek modulu `hosting` ze starého Shipardu, ale nekopíruje ho —
přebírá pull-based provisioning a princip „hosting je sám Shipard DS",
vynechává fakturaci, helpdesk a HW evidenci.

> **Stav:** Design schválen (D1–D12). **Fáze 0 hotová** (2026-08-05):
> `adminOnly` mechanismus (task 0a), modul `hosting.core` + `install.hosting`,
> tabulky servers / data_sources / ds_users, admin viewery, portálový endpoint
> `/_hosting/portal/my-datasources` + `PortalScreen` (task 0b).
> **Fáze 1 hotová** (2026-08-05): OIDC OP — `OpKeyStore` + CLI
> `hosting-oidc-init`/`hosting-oidc-client`, endpointy discovery/jwks/
> authorize/approve/token, `hosting_core_oidc_codes`, issuer setting
> `hosting.oidc.issuer`, SPA `op_auth` flow (task `hosting-02-oidc-op`).
> **Fáze 2 hotová** (2026-08-05): provisioning agent — endpointy
> `/_hosting/server/reconcile|queue|confirm` + klíče serverů `shpd_hk_`
> (CLI `hosting-server-key`), požadavek na nový DS z admin formuláře
> (beforeSave generuje ds_id/secret/URL, setting `hosting.baseDomain`),
> `shpd-server hosting-sync` + cron slot `two-minutes`, `ds-create
> --ds-id`, `user-create --if-not-exists` + předpropojení identity
> (task `hosting-03-provisioning-agent`).
> Fáze 3+ nezačaly; fázování v §8, každá fáze dostane vlastní PRD.

---

## 0. Schválená rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Hosting = modul `hosting.core` běžící na běžném DS. Portál = frontend tohoto DS. Kdokoli (např. účetní kancelář) si může rozjet vlastní hosting aktivací modulu. |
| D2 | Centrální identita = **vlastní minimální OIDC Provider (OP)** na hosting DS. Jen authorization code + PKCE, RS256, discovery + JWKS. Žádné externí IdP v deploymentu — jsou náročné na údržbu a jejich funkce nejsou potřeba. |
| D3 | Provisioning DS = **pull agent** `shpd-server hosting-sync` (cron na DS serveru). Polluje hosting, lokálně provede založení, potvrdí zpět. Součástí je rekonciliace (inventura existujících DS + verzí). |
| D4 | Mail-router lookup: `lookup.json` generuje **pull proces na mail-router stroji** z hosting API. Per-DS `shpd_ak_` tokeny mintuje DS server při provisioningu (`mail-router-setup`) a **hlásí je hostingu**; hosting je broker (uložené v `encrypted_text`). |
| D5 | AI gateway na hostingu: PHP endpoint, Anthropic Messages **passthrough** (včetně SSE). V1 = auth + metering (per-DS log tokenů); limity a model allowlist v2, schéma logu na ně připravené. Jen Anthropic. |
| D6 | Vlastní AI klíč zůstává **rovnocennou cestou** — gateway je jen jiná data v `core_ai_backends` (`base_url` = gateway, `api_key` = gateway token). Nulová změna kódu na straně DS — `base_url` respektuje PHP `AnthropicLlmClient` i Python `ai_analyzer`. |
| D7 | Přehled napříč DS = **push agregátů** z DS do hostingu (agent, cron). On-demand fetch lze doplnit kdykoli později. |
| D8 | Portálové účty = `core_system_users` hosting DS. Žádná nová tabulka uživatelů — OP autentizuje proti běžné user bázi hostingu (lokální login, pozvánky/reset z Fáze 0b fungují beze změny). |
| D9 | **Admin-only tabulky**: definice tabulky může deklarovat `"adminOnly": true`; `TableAccessGuard` to vynucuje plošně (CRUD/viewer/form → 403 pro ne-admina) stejně jako dnes prefix `core_system_`. Všechny `hosting_core_*` tabulky flag nesou. Malé rozšíření jádra s hodnotou i mimo hosting; nejhrubší stupeň budoucího RBAC. |
| D10 | **Jedno přihlášení** = session na hosting DS. Ne-admin po přihlášení vidí **pouze portál** — dedikované endpointy `/_hosting/portal/*` scopované na session uživatele (žádné generické viewery nad hosting tabulkami); admin navíc standardní aplikaci s hosting viewery (= administrace hostingu). OIDC `authorize` používá tutéž session — SSO: uživatel se session proletí na DS bez zastávky. |
| D11 | Hosting DS je **dedikovaný** — install modul `install.hosting`; vlastní agenda provozovatele (účetnictví, pošta…) žije v samostatném běžném DS. Doporučení, ne tvrdý zámek — D9+D10 chrání i smíšený případ. |
| D12 | OIDC **issuer je explicitně uložený v nastavení hostingu**, ne odvozovaný z requestu. `(issuer, sub)` je klíč identit na všech DS — změna domény portálu ho nesmí tiše zneplatnit. Doménu portálu volit s rozmyslem hned na začátku. |

Vědomě mimo scope (lze přidat později jako samostatné moduly / fáze):
fakturace (partners, invoicingGroups), helpdesk, monitoring (updown.io,
netdata), evidence HW/LXC v plné šíři, automatizace TLS certifikátů
a web-proxy konfigurace, pozastavení/mazání DS lifecycle, RBAC jemnější
než `is_admin`.

---

## 1. Motivace

1. **Noví uživatelé** — ruční zakládání DS je pracné (ds-create, ds-upgrade,
   domain-add, user-create, mail-router-setup, ai-analyzer-set-key — šest
   ručních kroků na různých strojích).
2. **Napojení na mail-router a AI** je ruční a chybové (editace `lookup.json`,
   distribuce API klíčů).
3. **Portál** — uživatelé s více DS potřebují jeden vstupní bod: seznam svých
   DS, tlačítka pro vstup, přehled „co je kde potřeba řešit".
4. **Centrální autorita pro přihlašování** — RP infrastruktura (authorization
   code + PKCE, `IdentityMapper`, handoff) existuje (`docs/auth.md`), chybí OP.
   `docs/auth.md` s „centrálním Shipard ID" explicitně počítá.

## 2. Co se přebírá ze starého hostingu a co ne

**Přebírá se:**

- *Hosting je sám Shipard DS s modulem* — tabulky, docStates, viewery,
  server-driven UI. Žádná zvláštní aplikace.
- *Pull-based provisioning* — starý `DSCreator.php`: server polluje
  `get-new-data-source-request?serverGID=…` s API klíčem, lokálně provede
  založení, potvrdí `confirm-new-data-source-request`. Firewall-friendly,
  hosting nikam nepotřebuje SSH, výpadek agenta nic nerozbije.
- *Server ví „kam jít"* přes `/etc/shipard/server.json` (staré `useHosting`,
  `hostingDomain`, `hostingApiKey`, `serverGID` → nová sekce `hosting`, §5.1).

**Nepřebírá se:**

- Spojování účtů přes e-mail (starý `dsUsers` + e-mail jako klíč) → nahrazeno
  stabilním `(issuer, sub)` z OIDC.
- Push statistik vlastním protokolem (`HostingUserSummaryUpload`,
  `DSHostingInfoReceiver`) → jednodušší agregát v rámci `hosting-sync`.
- Vše ze sekce „mimo scope" výše.

## 3. Architektura — komponenty

```
                       ┌──────────────────────────────────────────┐
                       │  HOSTING DS  (modul hosting.core)        │
                       │                                          │
   prohlížeč ─────────►│  Portál (frontend hosting DS)            │
                       │  OIDC OP   /_hosting/oidc/*  (D2)        │
                       │  Portál API /_hosting/portal/* (D10)     │
                       │  Server API /_hosting/server/* (D3, D7)  │
                       │  Mail API  /_hosting/mail/lookup (D4)    │
                       │  AI gateway /_hosting/ai-gw/v1/messages  │
                       └───▲──────────▲──────────▲────────────────┘
                           │          │          │
        pull (cron)        │          │          │        pull (cron)
  ┌────────────────────────┴──┐   ┌───┴──────────┴───────────────┐
  │ DS SERVER                 │   │ MAIL-ROUTER stroj            │
  │ shpd-server hosting-sync  │   │ lookup-sync → lookup.json    │
  │  · založení DS            │   │  (mtime-watch reload beze    │
  │  · rekonciliace, stats    │   │   změny mail_routeru)        │
  │  · mail token → hosting   │   └──────────────────────────────┘
  └───────────────────────────┘
        │ per-DS
        ▼
  DS: auth.providers → OP hostingu (RP strana existuje)
      core_ai_backends → base_url = AI gateway (D5/D6)
```

Všechny integrační směry jsou **pull od klienta k hostingu** — hosting nikdy
aktivně nevolá DS servery ani mail-router. Jediná výjimka: AI gateway volá
ven na `api.anthropic.com` (passthrough).

### 3.1 Endpointy a jejich zařazení

Routy podle precedentu `core.mail`: controllery v `src/Api/Controller/`
(příp. dispatch do `modules/hosting/core/src/`), větve v `src/Api/Router.php`,
funkční jen když je na DS aktivní `hosting.core`. Auth režimy:

| Prefix | Auth |
|---|---|
| `/_hosting/oidc/*` (discovery, jwks, authorize, token) | exempt v `AuthMiddleware` (vzor `/_auth/oidc/*`); authorize vyžaduje session uživatele na hostingu (redirect na login portálu) |
| `/_hosting/portal/*` (my-datasources, my-summary) | session uživatele hostingu; server vrací **jen řádky daného uživatele** (D10) |
| `/_hosting/server/*` | `shpd_hk_` klíč serveru (vlastní prefix; prefix + SHA-256 hash na `hosting_core_servers`, validuje `HostingServerController` sám — `core_system_api_keys` jsou vázané na uživatele a `AuthContext` identitu klíče nenese) |
| `/_hosting/mail/lookup` | `shpd_ak_` API klíč vázaný na řádek mail-routeru |
| `/_hosting/ai-gw/*` | gateway token (vlastní tabulka, ne `core_system_api_keys` — jiná audience) |

## 4. Datový model (náčrt — tableId přidělí `next-table-id` v PRD)

Skupina `modules/hosting/core/`, tabulky `hosting_core_*`. Všechny
s docStates dle konvence a **všechny s `"adminOnly": true`** (D9) —
generické CRUD/viewer/form cesty jsou pro ne-adminy uzavřené, portál jde
výhradně přes `/_hosting/portal/*`.

| Tabulka | Obsah |
|---|---|
| `hosting_core_servers` | DS servery: název, FQDN, stav, příznaky „smí zakládat DS", hash API klíče serveru, last_seen, verze (shpd, OS) z rekonciliace |
| `hosting_core_data_sources` | Evidence DS: ds_id (`xxxx-xxxx-…`), název, web-id slug, server (FK), doména/URL aplikace, install modul, lifecycle stav (požadavek → zakládá se → aktivní → …), mail token (`encrypted_text`, D4), časy |
| `hosting_core_ds_users` | Vazba uživatel (FK `core_system_users` hostingu) ↔ DS + role (admin/člen). Zdroj pro portálový seznam „moje DS" |
| `hosting_core_mail_routers` | Mail-routery: název, obsluhované domény, hash API klíče, last_seen |
| `hosting_core_ds_stats` | Push agregáty per DS (D7): počty karet feedu / alertů / nové pošty, timestamp. Malé, bez osobních dat |
| `hosting_core_ai_tokens` | Gateway tokeny: DS (FK), hash tokenu, aktivní, expirace |
| `hosting_core_ai_usage` | Metering: DS, model, input/output tokeny, timestamp, (rezerva: cache-read tokeny). Schéma připravené na limity v2 |

Poznámky:

- Privátní RS256 klíč OP žije v `secrets/` hosting DS (vedle `secrets.key`),
  ne v DB. Rotace klíčů = v2 (JWKS umí víc klíčů od začátku).
- OIDC OP autorizační kódy / consent transakce: malá tabulka
  `hosting_core_oidc_codes` (obdoba `core_system_auth_transactions` na RP
  straně) — single-use, krátké TTL, oportunistický úklid.
- `hosting_core_data_sources.lifecycle` řídí provisioning frontu (stav
  „požadavek" = ekvivalent starého docState 1100 + `inProgress`).

## 5. Kontrakty

### 5.1 Klientská konfigurace DS serveru

`/etc/shipard/server.json`, volitelná sekce (implementace:
`ServerConfig::getHosting()` → readonly `HostingConfig`):

```jsonc
"hosting": {
    "url": "https://portal.example.com",   // base URL hosting DS (https; http jen localhost dev)
    "serverId": 3,                          // ndx řádku hosting_core_servers (informativní/log)
    "apiKey": "shpd_hk_…"                   // klíč serveru z `shpd-ds hosting-server-key --generate`
}
```

Bez sekce se nic nemění — hosting je plně opt-in. Klíče serverů mají
vlastní prefix `shpd_hk_` (na hostingu jen prefix + SHA-256 hash);
postup připojení serveru: `docs/cli.md` → Workflow scénář 8.

### 5.2 `shpd-server hosting-sync` (D3)

Jeden běh (cron slot `two-minutes`; `--dry-run` = náhled fronty přes
`queue?peek=1` — nepřeklápí stavy a neposílá client_secret):

1. **Rekonciliace** — `POST /_hosting/server/reconcile`, body
   `{version, dataSources: [{ds_id, name, modules}]}`. Hosting
   aktualizuje `last_seen` + `last_version`, rozdíly evidence ↔ realita
   jen loguje (F2).
2. **Provisioning** — `GET /_hosting/server/queue` → požadavky serveru
   (`lifecycle` request/creating; servírování = překlopení na `creating`
   + `claimed_at`; `can_provision = false` → prázdná fronta). Payload
   per item: `{request_id, ds_id, name, install_module, web_id, host,
   owner: {email, name, sub}, oidc: {issuer, client_id, client_secret,
   label}}` — `sub` = (string) id vlastníka (přesně co OP dává do
   id_tokenu), issuer ze settingu (D12), secret dešifrovaný (jediné
   místo, kde opouští hosting — https, jednorázově). Pro každý požadavek
   (chyba jednoho nezastaví další): `ds-create --ds-id` (existující
   adresář = skip) → `ds-upgrade` → `domain-add` → merge položky
   `{id: "shipard-id", label, issuer, clientId, clientSecret,
   autoLinkEmail: false}` do `auth.providers` (atomicky, 0600; U2 —
   identita se předpropojuje) → `user-create --admin --if-not-exists
   --identity-provider shipard-id --identity-issuer … --identity-subject
   {sub}` → `POST /_hosting/server/confirm` `{request_id, ds_id,
   status: "ok"|"failed", error?}`. Confirm `ok` → `lifecycle = active`
   + vazba vlastníka v `hosting_core_ds_users` (role `admin`, U1);
   `failed` → `lifecycle = failed` + `provision_error`, retry = admin
   přepne zpět na `request`. Mail-router a AI kroky doplní Fáze 3/4.
3. **Stats push** (D7) — malý agregát per DS (počty z dashboard feedu /
   alertů). Bez osobních dat — jen čísla. Fáze 5.

### 5.3 Mail lookup (D4)

`GET /_hosting/mail/lookup` (klíč mail-routeru) → JSON ve formátu dnešního
`lookup.json` (`hosts` + `data_sources`: ds_id i web-id slug → `api_url`,
`api_token`). ETag/If-None-Match, ať je cron laciný.

Na mail-router stroji nový proces/cron **`lookup-sync`** (repo `mail_router`):
poll → při změně atomický zápis (temp + rename) → existující mtime-watch
reload. Hosting down ⇒ jede se na stale lookup, pošta se neztrácí.

### 5.4 OIDC OP (D2, D12)

**Implementováno ve Fázi 1** (`HostingOidcController`,
`tasks/hosting-02-oidc-op.md`).

- `GET /_hosting/oidc/.well-known/openid-configuration`,
  `GET /_hosting/oidc/jwks`, `GET /_hosting/oidc/authorize`,
  `POST /_hosting/oidc/approve`, `POST /_hosting/oidc/token`. Gating:
  neaktivní modul nebo nevyplněný issuer setting → 404.
- **Issuer je explicitní setting `hosting.oidc.issuer`** (D12, settings
  stránka „OIDC provider", `adminOnly`) — trvalá forma
  `https://{host}/api/v1/_hosting/oidc`; discovery i id_tokeny ho používají
  doslovně, odvození z requestu se nepoužívá. Nesoulad hostname requestu
  vs. issuer = warning do logu. RP porovnává `iss` claim byte-exact.
- Jen authorization code + PKCE S256, `client_secret_post`, RS256 id_token
  (kid v hlavičce — povinný pro RP keyset lookup) s claimy `sub` (= user id
  hostingu, stabilní), `email`, `email_verified: true`, `name`, `nonce`.
  Přesně to, co náš RP validuje — nic navíc.
- Podpisový klíč: `secrets/oidc-op.key` (PEM RSA 3072, 0600, `OpKeyStore`),
  zakládá `shpd-ds hosting-oidc-init`. Nikdy v DB ani logu.
- Klienti (jednotlivé DS) = řádky v `hosting_core_data_sources`
  (client_id = ds_id, `oidc_client_secret` encrypted_text + sensitive,
  `oidc_redirect_uri` exact match). Fáze 1 plní `shpd-ds
  hosting-oidc-client --ds … --redirect-uri … --generate`; Fáze 2 agent.
- **Session bridge (D10):** SPA drží session token v localStorage, browser
  GET na `authorize` tedy hlavičku nenese. `authorize` (exempt) validuje
  požadavek, založí transakci v `hosting_core_oidc_codes` (TTL 10 min)
  a přesměruje na `/app/?op_auth={txn}`; SPA se session zavolá
  `POST /_hosting/oidc/approve` (Bearer, NENÍ exempt) → server naváže
  uživatele, vydá kód (TTL 60 s) a vrátí RP redirect URL; SPA provede
  `window.location`. Bez session SPA nejdřív ukáže LoginScreen (`op_auth`
  přežije login). SSO = tiché schválení bez interakce.
- Kód je single-use (token endpoint řádek smaže před validací), transakce
  single-use (`user IS NULL` guard v approve). Selhání token endpointu →
  400 `{"error":"invalid_grant"}` (OAuth tvar, bez rozlišení důvodu).
- Rate limit: authorize/approve/token v login bucketu (10/min/IP);
  discovery/jwks v defaultu (RP je cachuje).
- Vědomá volba: OP je čistě identitní autorita — kód vydá kterémukoli
  přihlášenému uživateli hostingu bez kontroly `hosting_core_ds_users`.
  O vpuštění do DS rozhoduje RP (`IdentityMapper`: autoLink/JIT/
  `oidc_no_account`). Centrální gating přes ds_users = případný v2 flag.

Výsledný login flow na DS: LoginScreen → „Přihlásit přes {hosting}" →
portál (session tam) → id_token → RP `IdentityMapper` `(issuer, sub)`,
`autoLinkEmail`/`jitProvision` dle politiky DS.

### 5.5 AI gateway (D5/D6)

- `POST /_hosting/ai-gw/v1/messages` — klienti si na `base_url` sami
  připojují `/v1/messages`, gateway tedy servíruje tuto cestu.
- Ověří gateway token → nahradí autorizaci skutečným klíčem organizace
  (uložen jako `encrypted_text` v nastavení hostingu) → passthrough na
  `api.anthropic.com` včetně SSE streamu → z odpovědi vytěží `usage`
  → zápis do `hosting_core_ai_usage`.
- Provisioning zapíše do nového DS: backend `default` s `base_url` =
  gateway a `api_key` = gateway token. Vlastní klíč = uživatel si backend
  přepne/založí jiný (D6).
- Vědomý limit v1: streamované proxy spojení drží PHP-FPM worker. Pro
  desítky klientů OK; při růstu se gateway vydělí do samostatného daemonu
  (rozhraní se nemění — je to jen `base_url`).

## 6. Portál a přístupový model (D8–D11)

Frontend hosting DS, žádná zvláštní aplikace. **Jedno přihlášení = session
na hosting DS**; co uživatel vidí, řídí `is_admin`:

- **Ne-admin (portálový uživatel)**: frontend na DS s aktivním
  `hosting.core` renderuje místo standardního app shellu **portálovou
  obrazovku** — seznam „moje DS" (název, doména, vstupní tlačítko,
  agregát z `hosting_core_ds_stats`). Data výhradně z
  `/_hosting/portal/*`; příznak režimu nese login envelope / `/_app/info`
  (vzor `is_admin`). Typický portálový uživatel jinak hosting DS vůbec
  „nepoužívá" — přichází přes OIDC redirect ze svého DS a se session
  proletí bez zastávky (SSO).
- **Admin hostingu**: standardní aplikace s hosting viewery (servery,
  zdroje dat — včetně založení nového: formulář vytvoří řádek ve stavu
  „požadavek", zbytek udělá agent —, mail-routery, AI spotřeba) + přístup
  na portálovou obrazovku.

Bariéry nejsou v UI, ale na serveru: `adminOnly` tabulky (D9) + portálové
endpointy scopované na session uživatele (D10). Vědomý kompromis v1:
`is_admin` je jediný stupeň — admin hostingu má i `core_system_*`
(uživatelé, API klíče); jemnější RBAC mimo scope.

Branding portálu = existující app-settings (název, logo) — starý
`hostings` číselník není potřeba, jeden hosting DS = jeden hosting.

### 6.1 Doména portálu

Hosting DS je obyčejný DS — doména se přidá standardně:

```
shpd-server domain-add --host shpd.dev --ds <hosting-ds-id>
```

`domains.json` mapuje hostname (apex funguje), jednotlivé DS zůstávají na
`*.shpd.dev`. DNS + TLS na úrovni nginx/proxy jako dnes. **Doména = issuer**
(D12) — volit s rozmyslem, pozdější změna zneplatňuje `(issuer, sub)`
identity na všech DS.

## 7. Bezpečnostní poznámky

- Hosting DB drží citlivé hodnoty (mail tokeny DS, klíč organizace pro AI,
  client_secrets) — vše `encrypted_text` (per-DS šifrování hostingu).
- API klíče serverů/routerů: jen SHA-256 hash (vzor `core_system_api_keys`).
- OP privátní klíč mimo DB, v `secrets/`.
- `adminOnly` tabulky (D9): skutečná bariéra je na serveru
  (`TableAccessGuard`), UI jen nezobrazuje mrtvé odkazy — stejný princip
  jako Fáze 0a.
- `hosting_core_ds_stats` záměrně jen čísla — žádné názvy partnerů, částky,
  předměty mailů.
- Rate-limit na OP endpointech (login-class bucket jako u `/_auth/*`).

## 8. Fázování

| Fáze | Obsah | Hotovo když |
|---|---|---|
| **0 — Modul, evidence, přístupový model** | `adminOnly` mechanismus v jádru (`TableAccessGuard` + table-definitions, D9); skeleton `hosting.core` + `install.hosting`, tabulky servers / data_sources / ds_users, admin viewery; `/_hosting/portal/*` + portálová obrazovka pro ne-adminy (D10) | Portál běží na vlastním DS; ne-admin vidí jen svůj seznam DS a proklikne se; admin spravuje evidenci; generické CRUD nad `hosting_core_*` vrací ne-adminovi 403 |
| **1 — OIDC OP** | discovery + JWKS + authorize + token, `hosting_core_oidc_codes`, klíč v secrets, explicitní issuer setting (D12); ruční napojení jednoho DS přes `auth.providers` | Login na DS přes „Přihlásit přes {hosting}" funguje end-to-end proti RP z `docs/auth.md`, včetně SSO průletu se session |
| **2 — Provisioning agent** | `server.json` sekce `hosting`, `shpd-server hosting-sync` (rekonciliace + fronta požadavků + confirm), formulář „nový DS" na portálu | Nový DS vznikne z portálu bez ručního zásahu na serveru, včetně `auth.providers` |
| **3 — Mail-router** | `/_hosting/mail/lookup`, mail token flow v agentovi (mint + report), `lookup-sync` v repu `mail_router` | Nový DS přijímá poštu bez ruční editace `lookup.json` |
| **4 — AI gateway** | ai-gw passthrough + tokeny + metering, krok v provisioningu (backend řádek) | Nový DS má funkční AI (chat, analýza pošty) od založení; hosting vidí spotřebu per DS |
| **5 — Přehled** | Stats push v agentovi, `hosting_core_ds_stats`, agregáty na portálu | Uživatel na portálu vidí, kolik čeho v jednotlivých DS čeká |

Pořadí 1↔2 lze prohodit; OP dřív znamená, že provisioning zapisuje
`auth.providers` od první verze a DS se rodí rovnou s centrálním loginem.

## 9. Dotčené projekty

| Projekt | Dopad |
|---|---|
| `nov_shipard` | Rozšíření jádra: `adminOnly` v table-definitions + `TableAccessGuard` (D9). Nová skupina `modules/hosting/` + `install.hosting`, controllery + routy, `hosting-sync` v `shpd-server`, rozšíření `server.json`, portálový režim frontend |
| `mail_router` | Nový proces/cron `lookup-sync` (fáze 3); běhové jádro beze změny |
| `ai_analyzer` | Beze změny (jen jiná data v `core_ai_backends`) |
| `shipard_node` | Beze změny ve fázích 0–5; odchozí pošta per DS je samostatné budoucí téma |
