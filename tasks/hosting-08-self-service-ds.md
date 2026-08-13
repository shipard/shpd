# Hosting 08 — Self-service zakládání zdrojů dat z portálu

**Stav:** hotovo — implementováno 2026-08-13 (6 commitů dle strategie;
odchylky od PRD: `hosting.selfService.maxOwned` je settings klíč na
settings page Hosting, ne cfgItem — rebuild compiled cfg není potřeba;
regex web_id zpřísněn na `^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$`, aby
skutečně vynucoval 3–50 znaků; dokument navíc vyžaduje `name` pro
request). Zbývá ruční proklik na dev DS + po nasazení `ds-upgrade`
na hosting DS.
**Návaznost:** hosting.md Fáze 2 (provisioning agent — pipeline
`lifecycle = request` → hosting-sync → confirm je hotová a beze změny),
hosting-07/07b (portál v shellu, portálový panel).

## Cíl

Každý přihlášený uživatel hostingu (admin i ne-admin) si může z portálu
založit nový zdroj dat. Jednoduchý modal wizard: název, `web_id`
(s živou kontrolou), země a jazyk (předvyplněno cz/cs), install modul
implicitně `install.base`. Založení = vytvoření řádku
`hosting_core_data_sources` s `lifecycle = request` a `owner` = session
uživatel — o zbytek se stará existující provisioning pipeline (agent
založí DS, předpropojí OIDC identitu, vytvoří admin účet, confirm
naváže `hosting_core_ds_users`). Žádná nová provisioning logika.

## Schválená rozhodnutí (2026-08-13)

| # | Rozhodnutí |
|---|---|
| D1 | Nový boolean `provision_default` na `hosting_core_servers` („Výchozí server pro nové DS"). Smí ho mít jen server s `can_provision = true`; nejvýše jeden — uložení řádku s příznakem ostatním příznak shodí (bez chybové hlášky). **Existence default serveru = zapnutí self-service** (žádný další setting). |
| D2 | Install moduly pro self-service označuje `"selfService": true` v jejich `module.jsonc`. `install.base` ano, `install.hosting` ne. Roletka ve wizardu se kreslí jen při >1 nabízeném modulu; při jediném se použije implicitně. |
| D3 | `web_id`: regex `^[a-z0-9]([a-z0-9-]{1,48}[a-z0-9])?$` (3–50 znaků, bez podtržítka — DNS/certifikáty, nesmí začínat/končit pomlčkou), lowercase normalizace v `beforeSave`, blocklist rezervovaných hodnot. Vše v `HostingDataSourceDocument` — platí i pro adminský formulář. |
| D4 | Tři portálové endpointy (`create-meta`, `check-web-id`, `create-datasource`). Create zapisuje server-side přes `TableGateway::saveDocument` (validace dokumentu, beforeSave odvození, korektní `docStateMain`), `lifecycle = request`, `owner` = session user, server = default, install modul z nabídky. |
| D5 | `my-datasources` nově vrací i řádky `owner = user AND lifecycle IN (request, creating, failed)` — karty „Připravuje se…" / „Založení se nepodařilo" (bez detailu chyby, bez tlačítka vstupu). Frontend při existenci pending karty polluje refresh po ~15 s. |
| D6 | Limity v create endpointu: max **1 otevřený požadavek** (request/creating) na uživatele; strop vlastněných DS = setting `hosting.selfService.maxOwned` (default 5, null = bez limitu). |
| D7 | UI: tlačítko „+ Nový zdroj dat" v hlavičce portálového panelu + CTA v empty state; modal wizard (vzor MailUploadModal). Ne user menu. Tlačítko se kreslí jen při `create-meta.canCreate` nebo s disabled stavem dle `reason`. |

## Scope

**Patří sem:** schéma serverů (`provision_default`), validace web_id
v dokumentu, ServersForm/dokument (jediný default), InstallModuleRegistry
(selfService filtr + lokalizace), tři portálové endpointy + rozšíření
`my-datasources`, setting `hosting.selfService.maxOwned`, frontend
(tlačítko, modal, pending karty, polling), docs, testy.

**Nepatří sem:** změny provisioning agenta / hosting-sync (payload i
confirm beze změny), správa/rušení DS z portálu, notifikace o dokončení,
retry failed požadavků uživatelem (retry = admin, beze změny), RBAC.

## Změny po souborech

### Schéma a moduly

#### 1. `modules/hosting/core/tables/hosting_core_servers.jsonc`

Nový sloupec (skupina `status`):

```jsonc
{
    "id": "provision_default",
    "name": "Default for new DS",
    "name:cs": "Výchozí pro nové DS",
    "name:en": "Default for new DS",
    "type": "boolean",
    "default": 0,
    "group": "status"
}
```

Po nasazení `ds-upgrade` na hosting DS.

#### 2. `modules/hosting/core/src/ServersForm.php` + dokument serveru

- Checkbox „Výchozí pro nové DS" do formuláře.
- Validace: `provision_default = true` vyžaduje `can_provision = true`.
- `beforeSave`/`afterSave` dokumentu: při uložení řádku s
  `provision_default = true` shodit příznak všem ostatním řádkům
  (jeden UPDATE; poslední uložený vyhrává, D1). Pokud servery dnes
  dokumentovou třídu nemají, založit ji (vzor HostingDataSourceDocument,
  registrace v module.jsonc).

#### 3. `modules/install/base/module.jsonc`

Přidat `"selfService": true` (top-level klíč vedle name/description).
`install.hosting` beze změny.

#### 4. `modules/hosting/core/module.jsonc` — settings

Nový cfgItem `hosting.selfService.maxOwned` (int, nullable,
default 5, popis: strop počtu DS vlastněných jedním uživatelem;
null/0 = bez limitu). Po změně **rebuild compiled cfg**.

### Server — validace a registry

#### 5. `modules/hosting/core/src/HostingDataSourceDocument.php`

a) **Validace web_id (D3)** ve `validate()` (vedle stávajících kontrol):
   - povinné pro `lifecycle = request` (dnes už je), formát regexem
     `^[a-z0-9]([a-z0-9-]{1,48}[a-z0-9])?$` — chybová hláška vyjmenuje
     pravidla (3–50 znaků, a–z, 0–9, pomlčka, nesmí krajní pomlčka),
   - blocklist: konstanta `RESERVED_WEB_IDS = ['www','mail','smtp',
     'imap','api','portal','admin','home','ns','dev','test','app',
     'auth','oidc']` → hláška „Tento identifikátor je rezervovaný.",
   - explicitní kontrola duplicity SELECTem (hezká hláška; unique index
     `unq_web_id` zůstává poslední autoritou).
b) **Normalizace** v `beforeSave`: `web_id` → trim + `mb_strtolower`
   (před odvozením `url_app`, které z něj vychází).
c) Pozn.: validace platí i pro adminský formulář — stávající řádky na
   alfě/dev regexu vyhovují (ověřeno: `nsa-*`, `home`… — `home` je
   v blocklistu, ale blocklist se vyhodnocuje jen při **změně** web_id
   nebo novém řádku, ne při editaci řádku, kde web_id zůstává beze
   změny; implementovat porovnáním s `originalData`).

#### 6. `src/Core/Module/InstallModuleRegistry.php`

- `list()` rozšířit o parametry `?string $language = null` a
  `bool $selfServiceOnly = false`: filtr na `"selfService": true`
  v definici, lokalizovaný `name`/`description` dle `$language`
  (fallback default) — `ModuleDefinition` lokalizace už nese, použít
  stejný přístup jako NavigationController u labelů.
- Zpětná kompatibilita: volání bez parametrů beze změny chování.

### Server — portálové endpointy

#### 7. `src/Api/Router.php` + `public/index.php` (dispatch portal)

Nové routy pod `/_hosting/portal/` (vzor `my-datasources`):
- `GET  /_hosting/portal/create-meta`
- `GET  /_hosting/portal/check-web-id` (query `value`)
- `POST /_hosting/portal/create-datasource`

Dispatch předá navíc `InstallModuleRegistry`, `TableGateway`
(hosting DS scoped) a `ConfigRuntime` (setting maxOwned) — dle
stávajícího wiringu portal dispatch větve.

#### 8. `src/Api/Controller/HostingPortalController.php`

Společný guard všech nových endpointů = stejný jako `myDatasources`
(modul aktivní, autentizace).

a) **`createMeta()`** →
```json
{
  "canCreate": true|false,
  "reason": null | "no_server" | "open_request" | "max_owned",
  "installModules": [{"id","name","description"}],
  "languages": [...], "countries": [...],
  "defaults": {"language": "cs", "country": "cz"}
}
```
   - `no_server` = žádný server s `provision_default = 1` (a
     `can_provision = 1`, docState aktivní) — feature vypnutá (D1),
   - `open_request` = uživatel má řádek `owner = user AND lifecycle IN
     (request, creating)` (D6),
   - `max_owned` = počet aktivních DS s vazbou uživatele v
     `hosting_core_ds_users` + jeho pending požadavky ≥ maxOwned,
   - jazyky/země z cfgItemů `world.base.languages` / `world.base.countries`
     (id + lokalizovaný label), defaults cs/cz.

b) **`checkWebId()`** → `{available: bool, reason: null|"format"|
   "reserved"|"taken"}`. Formát/blocklist vyhodnotit týmž kódem jako
   dokument (sdílená statická metoda na HostingDataSourceDocument,
   ať pravidla nežijí dvakrát); `taken` = SELECT na web_id.
   Endpoint je informativní — finální autorita je validace při create.

c) **`createDatasource()`** — body `{name, web_id, language, country,
   install_module?}`:
   1. guard + znovu vyhodnotit `canCreate` (race: meta ≠ create),
   2. `install_module`: z body jen pokud je v selfService nabídce,
      jinak/chybějící → jediný nabízený; mimo nabídku → 400,
   3. `language`/`country` proti cfgItemům, jinak 400,
   4. default server SELECTem (viz a; není → 409 `no_server`),
   5. `TableGateway::saveDocument` na `hosting_core_data_sources`:
      `{name, web_id, language, country, server, install_module,
      lifecycle: 'request', owner: $auth->userId}` — validace
      dokumentu vrací strukturované chyby → 422 s per-field hláškami
      (mapovat na pole wizardu),
   6. response `{item}` ve tvaru pending karty z `my-datasources`
      (frontend ji vloží bez refetche).

d) **`myDatasources()` rozšíření (D5):** druhý dotaz
   `SELECT id, ds_id, name, url_app, lifecycle FROM
   hosting_core_data_sources WHERE owner = %i AND lifecycle IN
   ('request','creating','failed') AND docState IN %in`; položky dostanou
   `state: 'creating'|'failed'` (request i creating shodně `creating` —
   rozlišení je pro uživatele bezcenné), `role: 'owner'`, `stats: null`.
   Stávající položky dostanou `state: 'active'`. Deduplikace přes `id`
   (failed řádek s existující ds_users vazbou nečekáme, ale pojistka).
   Řazení: pending první (nejnovější nahoře), pak active dle názvu.

### Frontend

#### 9. `frontend/src/api/portal.js`

Nové funkce `fetchCreateMeta()`, `checkWebId(value)`,
`createDatasource(payload)`.

#### 10. `frontend/src/components/portal/NewDatasourceModal.svelte` (nový)

Modal (vzor MailUploadModal): pole název, web_id (debounced
`checkWebId` ~400 ms, inline stav ✓/✗ s důvodem), roletky země/jazyk
(z meta, předvyplněno defaults), roletka install modulu jen při >1
(D2). Submit → `createDatasource`; 422 mapuje chyby k polím; úspěch →
zavřít, předat pending item rodiči, toast.

#### 11. `frontend/src/components/portal/PortalContent.svelte`

- Načíst `create-meta` spolu s přehledem; tlačítko „+ Nový zdroj dat"
  v hlavičce (skryté při `reason: no_server`; disabled s tooltip
  hláškou při `open_request`/`max_owned`), CTA v empty state (D7).
- Karty dle `state`: `creating` → badge „Připravuje se…", spinner, bez
  tlačítka vstupu; `failed` → badge „Založení se nepodařilo" + text
  „Kontaktujte správce." (bez detailu chyby); `active` beze změny.
- Polling: existuje-li `creating` karta, refetch přehledu každých
  15 s (cleanup v onDestroy; po zmizení pending karet polling stop,
  po dokončení refetch i `create-meta` — limity se změnily).

#### 12. i18n (`cs.js`, `en.js`)

Nové klíče `portal.create.*` (tlačítko, nadpis modalu, pole, stavy
web_id kontroly, chybové hlášky limitů, badge stavů, toast).
`npm run check:i18n` musí projít.

### Dokumentace

#### 13. `docs/hosting.md`

Nová podsekce „Self-service zakládání DS" (D1–D7: default server jako
feature switch, selfService install moduly, web_id pravidla + blocklist,
limity, pending karty) + řádek do stavového bloku. U Fáze 2 doplnit
odkaz, že request může vzniknout i z portálu (payload agenta beze změny).

## Testy

PHPUnit (úzké `--filter`):

1. **Web_id validace (dokument):** matice formátů (krátké, dlouhé,
   velká písmena → normalizace, podtržítko, krajní pomlčka, diakritika),
   rezervovaná hodnota (nový řádek / změna / editace beze změny web_id
   projde), duplicita.
2. **Servery — jediný default:** uložení druhého serveru s příznakem
   shodí první; `provision_default` bez `can_provision` → chyba validace.
3. **createMeta:** bez default serveru `no_server`; s otevřeným
   požadavkem `open_request`; maxOwned dosažen → `max_owned`;
   selfService filtr vrací jen `install.base`.
4. **createDatasource:** happy path (řádek má lifecycle request, owner,
   default server, odvozené ds_id/url_app/docStateMain); install modul
   mimo nabídku → 400; druhý požadavek → 409/422 `open_request`;
   validační chyby → 422 s poli.
5. **myDatasources:** pending/failed položky pro ownera se `state`
   a bez stats; cizí pending se nevrací; řazení pending první.
6. **Guardy:** neautentizovaný 401, DS bez hosting.core 404
   (rozšířit stávající testy portálu, existují-li).

Frontend: ruční ověření (viz Hotovo když) + `check:i18n`.

## Strategie commitů

1. `hosting: provision_default server flag + single-default enforcement` (body 1, 2, test 2)
2. `hosting: web_id validation & normalization in DS document` (bod 5, test 1)
3. `install: selfService flag + localized registry listing` (body 3, 6)
4. `hosting: portal self-service endpoints (meta, check, create, pending in my-datasources)` (body 4, 7, 8, testy 3–6)
5. `frontend: portal new-datasource wizard + pending cards` (body 9–12)
6. `docs: hosting self-service DS` (bod 13)

Po nasazení: `ds-upgrade` na hosting DS (nový sloupec serverů) +
rebuild compiled cfg (nový cfgItem). Zapnutí feature = admin označí
server příznakem „Výchozí pro nové DS".

## Hotovo když

- [ ] Admin označí server jako výchozí; druhému serveru se příznak
      při přeoznačení automaticky odebere.
- [ ] Ne-admin i admin vidí na portálu „+ Nový zdroj dat"; wizard
      založí požadavek a v přehledu se objeví karta „Připravuje se…";
      po doběhu hosting-sync se sama (polling) změní na aktivní kartu
      se vstupem.
- [ ] Živá kontrola web_id: formát, rezervovaná hodnota, obsazenost —
      včetně hodnot obsazených jiným pending požadavkem.
- [ ] Druhý požadavek při otevřeném prvním nelze založit (disabled
      tlačítko i 4xx při obejití); maxOwned strop funguje.
- [ ] Bez default serveru se tlačítko nekreslí a create vrací
      `no_server`.
- [ ] Adminský formulář DS odmítne web_id s podtržítkem/velkými
      písmeny/krajní pomlčkou; editace stávajících řádků beze změny
      web_id prochází.
- [ ] Failed požadavek se uživateli ukáže bez detailu chyby; detail
      vidí admin v evidenci DS (beze změny).
- [ ] PHPUnit testy 1–6 zelené (úzký --filter), `check:i18n` prochází.
- [ ] `docs/hosting.md` aktualizovaný; `ds-upgrade` + rebuild compiled
      cfg poznamenané v release krocích.
