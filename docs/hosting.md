# Shipard — Hosting (modul `hosting.core`)

**Designový dokument.** Centrální správa zdrojů dat: portál pro uživatele
s více DS, centrální identita (vlastní minimální OIDC Provider), automatické
zakládání zdrojů dat, automatické napojení na mail-router a AI gateway.
Vychází z myšlenek modulu `hosting` ze starého Shipardu, ale nekopíruje ho —
přebírá pull-based provisioning a princip „hosting je sám Shipard DS",
vynechává fakturaci, helpdesk a HW evidenci.

> **Stav:** Design schválen (D1–D12), implementace nezačala.
> Fázování v §8; každá fáze dostane vlastní PRD.

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
| `/_hosting/server/*` | `shpd_ak_` API klíč hostingu vázaný na řádek serveru |
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

`/etc/shipard/server.json`, nová volitelná sekce:

```jsonc
"hosting": {
    "url": "https://portal.example.com",   // base URL hosting DS
    "serverId": "…",                        // identita přidělená hostingem
    "apiKey": "shpd_ak_…"                   // klíč serveru vydaný hostingem
}
```

Bez sekce se nic nemění — hosting je plně opt-in.

### 5.2 `shpd-server hosting-sync` (D3)

Jeden běh (cron, řádově minuty):

1. **Rekonciliace** — POST inventura: seznam lokálních DS (id, moduly,
   verze), verze shpd. Hosting aktualizuje `last_seen`, páruje evidenci
   s realitou, hlásí rozdíly do svého logu.
2. **Provisioning** — GET fronta požadavků pro tento server → pro každý:
   `ds-create` → `ds-upgrade` → `domain-add` → zápis `auth.providers`
   (OP hostingu) do `main.json` → `mail-router-setup` → zápis AI backend
   řádku (gateway token z požadavku, D5) → POST confirm s výsledky
   (ds_id, doména, **mail token** pro D4). Idempotentní — confirm až po
   úspěchu všech kroků, opakovaný požadavek nesmí založit DS dvakrát.
3. **Stats push** (D7) — malý agregát per DS (počty z dashboard feedu /
   alertů). Bez osobních dat — jen čísla.

### 5.3 Mail lookup (D4)

`GET /_hosting/mail/lookup` (klíč mail-routeru) → JSON ve formátu dnešního
`lookup.json` (`hosts` + `data_sources`: ds_id i web-id slug → `api_url`,
`api_token`). ETag/If-None-Match, ať je cron laciný.

Na mail-router stroji nový proces/cron **`lookup-sync`** (repo `mail_router`):
poll → při změně atomický zápis (temp + rename) → existující mtime-watch
reload. Hosting down ⇒ jede se na stale lookup, pošta se neztrácí.

### 5.4 OIDC OP (D2, D12)

- `GET /_hosting/oidc/.well-known/openid-configuration` (přesná cesta
  discovery vůči issuer URL se doladí v PRD), `GET /_hosting/oidc/jwks`,
  `GET /_hosting/oidc/authorize`, `POST /_hosting/oidc/token`.
- **Issuer je explicitní setting hostingu** (D12) — zapisuje se při zřízení,
  discovery i id_tokeny ho používají doslovně; odvození z requestu se
  nepoužívá. Nesoulad hostname requestu vs. issuer = warning do logu.
- Jen authorization code + PKCE S256, `client_secret_post`, RS256 id_token
  s claimy `sub` (= user id hostingu, stabilní), `email`, `email_verified`,
  `name`, `nonce`. Přesně to, co náš RP validuje — nic navíc.
- Klienti (jednotlivé DS) = řádky v `hosting_core_data_sources`
  (client_id = ds_id, client_secret generovaný při provisioningu).
- `authorize` bez session → redirect na login portálu a zpět; se session
  SSO průlet (D10). Mechanismus návratu doladí PRD; portálový login je
  běžný login hosting DS.

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
