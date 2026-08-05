# Hosting — Task 3: Mail-router integrace (Fáze 3)

**Stav:** hotovo — 2026-08-05; odchylky a poznámky k implementaci na konci

> PRD pro jednu Claude Code session, **dva repozitáře**: `nov_shipard`
> (část A) a `mail_router` (část B). Design: `docs/hosting.md`,
> rozhodnutí **D4**, kontrakt §5.3. Fáze 3 z §8: nový DS přijímá poštu
> bez ruční editace `lookup.json`.

## Kontext

Dnes: `lookup.json` na mail-router stroji se edituje ručně — adresa
(`{ds_id|web_id}[+mailbox]@{host}`) → `api_url` + `api_token`
(`shpd_ak_` klíč pro `/_mail/incoming`, mintuje `shpd-ds
mail-router-setup`, plaintext se ukáže jen jednou). Cíl (D4): tokeny
mintuje agent při provisioningu a **hlásí je hostingu v confirmu**;
hosting je broker (`encrypted_text`) a servíruje lookup; na
mail-router stroji je pull proces `lookup-sync` → atomický zápis →
existující mtime-watch reload (`LookupTable._maybe_reload`, jádro
mail_routeru beze změny).

**Vědomé volby (potvrzeno v chatu):**

1. **`mail-router-setup --json`** — agent potřebuje token strojově;
   parsování lidského stdout je křehké. Nová option vypíše
   `{"api_key": …, "user_id": …}` (jinak beze změny chování).
2. **Token cestuje v confirm body** (`mail_token`) — přesně D4;
   žádný nový endpoint.
3. **Klíče routerů = stejné schéma `shpd_hk_`** jako servery; validace
   se vytáhne do sdíleného `HostingApiKeyAuthenticator` (tabulka +
   sloupce jako parametry), použijí ho oba controllery — žádná druhá
   kopie hash logiky.
4. **`lookup-sync` = oneshot CLI + systemd timer** (2 min), ne další
   daemon — žádný stav mezi běhy (kromě ETag cache souboru), pád
   nemá co rozbít, stale lookup dál funguje.
5. **Backfill existujících DS je ruční**: plaintext tokeny nejsou
   nikde uložené, retroaktivně nahlásit nejdou. Postup: `shpd-ds
   mail-router-setup --force --json` na DS → vložit token do evidence
   na hostingu (admin form, sensitive pole). Zdokumentovat.

## Cíl

**A (nov_shipard):** tabulka `hosting_core_mail_routers` + CLI
`hosting-router-key`; sloupec `mail_token` na data_sources; endpoint
`GET /_hosting/mail/lookup` (ETag); krok `mail-router-setup` v agentu
+ `mail_token` v confirmu; `--json` option.

**B (mail_router):** `lookup_sync.py` + config sekce + systemd
service/timer + deploy dokumentace.

## Před implementací přečti

**nov_shipard:**
- `docs/hosting.md` §5.3, §7; `docs/mail/api-contract.md`
- `src/Api/Controller/HostingServerController.php` — auth klíčů
  (základ pro extrakci authenticatoru), gating, dispatch
- `src/Command/DataSource/HostingServerKeyCommand.php` — vzor pro
  `hosting-router-key`
- `src/Command/DataSource/MailRouterSetupCommand.php` — celý (ensure
  user, rotace, výpis klíče)
- `src/Core/Server/HostingSyncRunner.php` — provisioning smyčka,
  confirm body, `runProcess`/`performHttpRequest` seams
- `src/Api/Controller/HostingServerControllerTest.php` (tests/) —
  vzor testů auth + endpointů
- `modules/hosting/core/src/HostingDataSourceDocument.php` —
  šifrování (vzor `oidc_client_secret`)

**mail_router:**
- `mail_router/lookup.py` — formát, `_load` validace, mtime reload
- `mail_router/config.py` — jak se čte config.yaml, přidání sekce
- `mail_router/cli.py` — entry points vzor (`run_admin`)
- `deploy/systemd/*` + `deploy/config/config.example.yaml` — vzory
- `pyproject.toml` — console_scripts registrace

## Část A — nov_shipard

### Schéma a CLI

**`hosting_core_mail_routers.jsonc`** (nová tabulka, `adminOnly`,
docStates archive): `name` (varchar 100), `domains` (varchar 500 —
čárkami oddělené mail domény, na serveru se trimují+lowercase),
`api_key_prefix` (varchar 12, nullable), `api_key_hash` (varchar 64,
nullable, sensitive), `last_seen` (datetime, nullable), `note`
(text, nullable). Viewer + form (vzor servers), settingsItem do
`other.hosting`. `tableId` přes `next-table-id`.

**`hosting_core_data_sources.jsonc`**: aditivní sloupec `mail_token`
(`encrypted_text`, nullable, `sensitive: true`) — beforeSave šifruje
při změně (vzor `oidc_client_secret`); admin ho může vložit ručně
(backfill) a přepíše ho confirm.

**`src/Api/HostingApiKeyAuthenticator.php`** (extrakce ze
`HostingServerController`): validace `Bearer shpd_hk_…` → prefix
lookup + `hash_equals` nad zadanou tabulkou/sloupci, update
`last_seen`, vrací řádek. `HostingServerController` refaktorovat na
něj (beze změny chování — testy musí zůstat zelené).

**`src/Command/DataSource/HostingRouterKeyCommand.php`**:
`hosting-router-key --router <ndx> --generate|--revoke` — zrcadlo
`hosting-server-key` nad mail_routers.

**`MailRouterSetupCommand`**: option `--json` — na stdout jediný JSON
objekt `{"api_key": "shpd_ak_…", "user_id": N}` (žádné dekorace);
bez option beze změny.

### `GET /_hosting/mail/lookup`

Nová akce (controller `hostingMail` nebo rozšíření dispatch —
konzistentně s okolím), exempt, auth přes authenticator nad
mail_routers. Response — **přesně formát `lookup.json`**:

```json
{
  "hosts": ["<domains routeru>"],
  "data_sources": {
    "<ds_id>": {"api_url": "<url_app>", "api_token": "<mail_token>"},
    "<web_id>": {"api_url": "<url_app>", "api_token": "<mail_token>"}
  }
}
```

Zdroj: DS s `lifecycle = active`, živým docState a vyplněným
`mail_token` (dešifrovaný — druhé místo po queue, kde secret opouští
hosting; https only). `web_id` alias jen pokud vyplněný. ETag =
`sha256` kanonizovaného obsahu; `If-None-Match` shoda → 304 bez body.
Deterministické řazení klíčů (stabilní ETag).

### Agent (`HostingSyncRunner`)

Nový krok f. (za user-create, před confirm): pokud má nový DS aktivní
`core.mail` (zjisti z `main.json` po ds-upgrade — vzor gatingu
z odchylky hosting-03), spusť `cd {dsDir} && shpd-ds
mail-router-setup --json`, parsuj `api_key`. Confirm body doplň
`mail_token` (jen při úspěchu kroku). Bez `core.mail` → krok přeskoč,
confirm bez tokenu. Opakovaný běh (retry po pádu za setup krokem):
`mail-router-setup` bez `--force` selže na existující klíč — v retry
kontextu spusť s `--force` (token na hostingu se stejně přepíše
confirmem; rotace je neškodná, DS ještě nepřijímá poštu).

Hosting confirm handler: `mail_token` v body → ulož (šifrovaně) na
řádek.

### Dokumentace A

`docs/hosting.md` §5.3 → skutečný stav; `docs/cli.md`
(`hosting-router-key`, `--json`); `docs/operations/` — postup
„připojení mail-routeru k hostingu" (řádek routeru → klíč → config
na stroji → timer) + ruční backfill existujícího DS.

## Část B — mail_router

### `mail_router/lookup_sync.py` (nový)

Oneshot běh: GET `{url}` s `Authorization: Bearer {api_key}` +
`If-None-Match` z ETag cache (`{lookup_file}.etag`). 304 → konec.
200 → **validace před zápisem**: JSON parse, `hosts` list, každá
položka `data_sources` má neprázdné `api_url` + `api_token` (formát
po vzoru `LookupTable._load` — nikdy nepřepsat funkční soubor
nevalidním obsahem; prázdné `data_sources` je validní stav, jen
warning). Atomický zápis: tmp v témže adresáři + `os.replace`,
mode 0600; pak zapiš ETag. Chyba sítě/HTTP → log + exit 0 se stale
lookupem (exit != 0 jen na lokální I/O chybu). Timeout 10 s.

### Konfigurace + entry point + systemd

`config.py`: volitelná sekce
```yaml
lookup_sync:
  url:     https://portal.example.com/api/v1/_hosting/mail/lookup
  api_key: shpd_hk_XXXX
```
(chybí → lookup-sync odmítne běžet s jasnou hláškou; ostatní procesy
sekci ignorují). `cli.py`: `run_lookup_sync` (vzor `run_admin`) +
console_script `shipard-mail-router-lookup-sync` v `pyproject.toml`.

`deploy/systemd/shipard-mail-router-lookup-sync.service` (Type=oneshot)
+ `.timer` (OnUnitActiveSec=2min, OnBootSec=30s); do targetu timer
nezapojovat (nezávislý). `config.example.yaml` + deploy README
aktualizovat.

### Testy B

`tests/test_lookup_sync.py` (vzor okolních testů, bez sítě — mock
HTTP): 304 nezapisuje; validní 200 → atomický zápis + ETag; nevalidní
JSON/chybějící klíče → soubor nedotčen, nenulový log; síťová chyba →
stale + exit 0; prázdné data_sources → zapíše + warning.

## Testy A

- `HostingApiKeyAuthenticatorTest` (parametrizace tabulkou) +
  stávající `HostingServerControllerTest` zelené po refaktoru.
- Lookup endpoint: auth matice; obsah jen active+token DS; web_id
  alias; dešifrovaný token v response; ETag stabilní + 304;
  revokovaný klíč → 401.
- Confirm s `mail_token` → uloženo šifrovaně; bez tokenu → sloupec
  nedotčen.
- Runner: krok f. gating na core.mail; `--json` parsování; retry
  s `--force` (přes `runProcess` seam).
- `MailRouterSetupCommand --json` výstupní kontrakt.
- PHPUnit `--filter 'HostingMail|HostingApiKey|MailRouterSetup|HostingSync'`.

## E2E na dev (součást tasku)

1. Na gn5c: řádek routeru (domains = testovací doména),
   `hosting-router-key --generate`.
2. Nový DS přes portál (s `core.mail` v install modulu) → confirm
   s tokenem; ověřit `mail_token` na řádku (šifrovaný) a obsah
   lookup endpointu curl-em (vč. 304 na druhý dotaz).
3. Na dev mail-router stroji (nebo lokálně proti gn5c): config sekce
   + jeden běh `shipard-mail-router-lookup-sync` → `lookup.json`
   obsahuje nový DS; testovací mail přes `/_mail/incoming` tokenem
   z lookup souboru projde.
4. Backfill: ruční postup na existujícím dev DS dle dokumentace.

## Commit strategie

nov_shipard:
1. `hosting: mail_routers table + hosting-router-key + shared api key authenticator (D4)`
2. `hosting: /_hosting/mail/lookup endpoint with ETag (D4)`
3. `hosting: mail-router-setup step in hosting-sync + mail_token in confirm (D4)`

mail_router:
4. `lookup-sync: pull lookup.json from hosting (D4)`

## Hotovo když

- [x] Nový DS založený z portálu přijímá poštu bez ruční editace
      `lookup.json` (E2E krok 3)
- [x] Lookup endpoint servíruje jen aktivní DS s tokenem, s web_id
      aliasy, ETag/304 funguje
- [x] `lookup-sync` nikdy nepřepíše funkční soubor nevalidním obsahem;
      výpadek hostingu = stale lookup, pošta se neztrácí
- [x] `mail_token` na hostingu jen šifrovaně, v odpovědích formulářů
      se nevrací (sensitive); dešifrovaný odchází jen lookup
      endpointem přes https
- [x] Refaktor authenticatoru: server endpointy beze změny chování
- [x] Ruční backfill zdokumentovaný a ověřený
- [x] Testy obou repozitářů zelené, dokumentace aktualizovaná

## Poznámky k implementaci (odchylky od zadání)

1. **Backfill přes admin form narazil na `TableAccessGuard`** —
   `rejectSensitiveInput()` vrací 400 pro jakýkoli sensitive sloupec ve
   form save. Řešení (potvrzeno v chatu): nový opt-in mechanismus
   `TableForm::getEditableSensitiveColumns()` — form whitelistem povolí
   konkrétní sloupce (CRUD cesty dál plně blokované). `DataSourcesForm`
   whitelistuje `mail_token` (sekce Pošta, placeholder `●●●●●●`,
   inputType password, prázdný submit nemění). Samostatný commit
   `forms:`, dokumentace `docs/edit-forms.md` kap. 24.
2. **Response bez success envelope** — lookup endpoint vrací raw JSON
   přesně ve formátu `lookup.json` (dle PRD); `data_sources` se
   serializuje jako objekt i prázdná (`(object)` cast). Doplněna 304
   podpora v `Response::send()` (dosud jen 204/redirect) — první
   conditional-GET v repu.
3. **Retry s `--force`**: agent nedetekuje „retry kontext" — první běh
   je vždy bez `--force`, při nenulovém exitu jeden retry s `--force`.
   Selhání obou pokusů = chyba kroku → confirm `failed` (konzistentní
   s ostatními kroky). Token jde do confirm body jen při úspěchu.
4. **Gating `core.mail`**: `main.json` nese jen přímé moduly
   (`install.base`) — runner rezolvuje závislosti přes
   `ModuleLoader`/`ModuleResolver` (protected seam
   `isModuleActiveForDs`); bez čitelného server.json fallback na repo
   `modules/` (vzor bin/shpd-ds).
5. **Confirm ukládá `mail_token` nepodmíněně** — i při idempotentním
   re-confirmu už aktivního DS (lifecycle update se přeskočí, token
   ne) — retry agenta token rotuje a hosting musí držet poslední.
6. **Opravený stale řádek v `docs/hosting.md` §3.1** — lookup uváděl
   `shpd_ak_` klíč, správně `shpd_hk_` (rozhodnutí 3 tohoto tasku).
7. **E2E na dev boxi (2026-08-05)**: řádek routeru + klíč na gn5c;
   401/405/200/304 curl-em; backfill vlm9 (`mail-router-setup --force
   --json` → šifrovaný `mail_token` přes Document, v DB žádný
   plaintext); lookup servíruje ds_id + web_id alias s dešifrovaným
   tokenem; lokální `shipard-mail-router-lookup-sync` proti gn5c
   (200 → atomický zápis 0600 + ETag cache, druhý běh 304 →
   unchanged); testovací mail přes `/_mail/incoming` tokenem ze
   syncnutého souboru → 201 Created. Krok „nový DS z portálu → confirm
   s tokenem" je pokrytý unit testy runneru + confirm handleru — plný
   root průchod na reálném serveru zbývá stejně jako u hosting-03
   (dev box nemá zapisovatelný `/etc/shipard`).
