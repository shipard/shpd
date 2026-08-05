# Hosting — Task 0b: skeleton modulu, evidence, portálová obrazovka

**Stav:** hotovo — 2026-08-05; unique index `web_id` založen rovnou (MariaDB
nullable unique funguje, odklad na Fázi 3 nebyl potřeba), subsekce
`other.hosting` přidána i do `install.base` (settings položky v nedefinované
subsekci se tiše zahodí), bonus fix: `ds-upgrade` mail-router provisioning
guard na aktivní `core.mail` (na DS s `install.hosting` padal)

> PRD pro jednu Claude Code session. Design: `docs/hosting.md`,
> rozhodnutí **D1, D8, D9, D10, D11**; portál a přístupový model §6,
> datový model §4, endpointy §3.1. Dokončuje Fázi 0 z §8.

## Kontext

Zakládá se modul `hosting.core` a install modul `install.hosting`.
Fáze 0 = **ručně plněná evidence** (servery, zdroje dat, vazby
uživatel↔DS přes admin viewery) + **portálová obrazovka** pro ne-adminy:
seznam „moje DS" se vstupními tlačítky. Žádný OP, žádný agent, žádné
stats — to jsou Fáze 1+.

Přístupový model (D10): jedno přihlášení = session na hosting DS.
Ne-admin vidí **jen portál** (data z dedikovaného endpointu scopovaného
na session uživatele), admin standardní aplikaci s hosting viewery.
Bariéra je na serveru: všechny `hosting_core_*` tabulky mají
`"adminOnly": true` (D9), portálový endpoint vrací výhradně řádky
přihlášeného uživatele.

## Cíl

1. Modul `modules/hosting/core/` (`hosting.core`): tabulky
   `hosting_core_servers`, `hosting_core_data_sources`,
   `hosting_core_ds_users` (vše `adminOnly`), admin viewery + formuláře,
   settingsItems.
2. Install modul `modules/install/hosting/` (`install.hosting`) — D11.
3. Endpoint `GET /_hosting/portal/my-datasources` (session auth).
4. Frontend: `PortalScreen.svelte` — ne-admin na DS s aktivním
   hostingem ji dostane místo standardního app shellu; signál nese
   `/_app/info` (`hasPortal`).

## Před implementací přečti

- `docs/hosting.md` — celý §0–§6 (krátké), závazné D1/D8–D11
- `docs/modules.md` — struktura modulu, JSONC, i18n polí `:cs`/`:en`
- `docs/table-definitions.md` — formát tabulek; **nový flag `adminOnly`**
  z tasku 0a
- `modules/core/units/module.jsonc` + `modules/core/units/` — vzor
  malého modulu s viewers/forms/settingsItems
- `modules/core/ai/tables/core_ai_backends.jsonc` — vzor tabulky
  s docStates (`core.system.docStatesArchive`), columnGroups, indexy
- `modules/install/base/module.jsonc` — vzor install modulu (globální
  configy settingsSections/accountSections/navSections)
- `src/Api/Router.php` — vzor větví `/_mail/*` (ř. ~154+); router je
  module-unaware, gating níž
- `src/Api/Controller/MailController.php` — vzor modulového controlleru
  v `src/Api/Controller/`
- `public/index.php` — dispatch vzory; mapa `$tables` ve scope
- `src/Api/Controller/AppController.php` — `info()` (ř. ~52)
- `frontend/src/App.svelte` — větvení obrazovek (LoginScreen /
  SetPasswordScreen precedens), `authStore.isAuthenticated`
- `frontend/src/stores/` — `authStore` (`isAdmin`), appInfo store
- `modules/core/system/src/UsersViewer.php` — vzor settings vieweru

## Změny po souborech

### `modules/hosting/core/module.jsonc`

- `id: hosting.core`, name/description cs+en, `dependencies:
  ["core.system"]`.
- `tables`: tři níže. `settingsItems`: tři viewery, `section: "other"`,
  `subsection: "other.hosting"` (vzor `core.mail` — funguje pod
  jakýmkoli install modulem). `viewers` + `forms` registrace.
- `config`: `hosting.core.dsLifecycle` (request / creating / active /
  suspended — F0 používá jen active, zbytek připraven pro Fázi 2)
  a `hosting.core.dsUserRoles` (admin / member), soubory v `config/`.

### Tabulky (`modules/hosting/core/tables/*.jsonc`)

`tableId` přiděl přes `shpd-server next-table-id` (postupně tři).
Všechny: `"adminOnly": true`, docStates
`core.system.docStatesArchive`, sloupce `created`/`modified`,
displayPattern `{name}` resp. viz níže.

**`hosting_core_servers`** — F0 jen evidence (klíče a příznaky přijdou
ve Fázi 2): `name` (varchar 100), `fqdn` (varchar 200), `note`
(text, nullable). Unique index `fqdn`.

**`hosting_core_data_sources`**: `ds_id` (varchar 19, formát
`xxxx-xxxx-xxxx-xxxx`), `name` (varchar 200), `web_id` (varchar 50,
nullable — slug pro mail adresy, zatím jen evidence), `server`
(int FK → hosting_core_servers, nullable), `url_app` (varchar 200 —
cíl vstupního tlačítka), `install_module` (varchar 50, nullable),
`lifecycle` (enumString, cfgItem `hosting.core.dsLifecycle`, default
`active`). Unique `ds_id`; unique `web_id` řeš dle možností formátu
(partial unique JSONC neumí — pokud nejde nullable unique čistě,
vynech index a nech na Fázi 3, poznamenej do .md tabulky).
displayPattern `{name}`. Sloupce pro mail token / client_secret
**nezakládat** — přijdou ve svých fázích (schema changes jsou aditivně
bezpečné).

**`hosting_core_ds_users`**: `user` (int FK → core_system_users),
`data_source` (int FK → hosting_core_data_sources), `role`
(enumString, cfgItem `hosting.core.dsUserRoles`, default `member`),
`last_entered` (datetime, nullable — rezerva, F0 neplní). Unique
`(user, data_source)`.

Ke každé tabulce `.md` dle `docs/documentation.md`.

### `modules/install/hosting/module.jsonc`

- `id: install.hosting`, dependencies: `core.system`, `core.alerts`,
  `hosting.core` (minimální sada — hosting DS je dedikovaný, D11).
- `config`: vlastní `settingsSections.jsonc` / `accountSections.jsonc` /
  `navSections.jsonc` — zkopíruj z `install.base` a osekej na potřebné
  sekce (settings: system + other; nav: minimum).

### Viewery + formuláře (`modules/hosting/core/src/`)

- `ServersViewer`, `DataSourcesViewer`, `DsUsersViewer` — settings
  viewery (vzor `UsersViewer`): název, klíčové sloupce, badge lifecycle
  u data sources. Formuláře nejjednodušší cestou dle
  `docs/edit-forms-cookbook.md` (JSONC form nebo `TableForm` — zvol
  vzor odpovídající okolním modulům).
- Namespace dle konvence modulů (`Shipard\Module\Hosting\Core\…`),
  ověř autoload vzor existujících modulů.

### `src/Api/Controller/HostingPortalController.php` (nový)

- `myDatasources(AuthContext $auth, DataSourceConnection $db,
  array $tables): Response`
  - gating: `!isset($tables['hosting_core_data_sources'])` →
    404 `NOT_FOUND` (modul neaktivní; vzor gatingu jinde v dispatch);
  - nepřihlášený se sem přes AuthMiddleware nedostane (endpoint není
    exempt) — v kontroleru přesto ověř `$auth->userId`;
  - SELECT join `hosting_core_ds_users` × `hosting_core_data_sources`
    WHERE `user = $auth->userId` AND lifecycle = 'active' (a doc state
    ne-archiv), ORDER BY name;
  - výstup: `[{id, ds_id, name, url_app, role}]` — **nic navíc**
    (žádné serverové detaily).

### `src/Api/Router.php` + `public/index.php`

- Router: větev `GET /_hosting/portal/my-datasources` →
  `new Route('hostingPortal', 'myDatasources')` (vzor `/_mail/*`).
- index.php: `dispatchHostingPortal(...)` — předá `$auth`, `$db`,
  `$tables`.

### `src/Api/Controller/AppController.php`

- `info()`: nové pole `hasPortal` (bool). Hodnotu odvoď z přítomnosti
  `hosting_core_data_sources` v `$tables` — controller dnes mapu nemá,
  přidej parametr/property dle stylu okolního wiringu v index.php.
  Veřejný endpoint → žádná citlivá data, jen bool.

### Frontend

- `frontend/src/api/portal.js`: `fetchMyDatasources()`.
- `frontend/src/components/portal/PortalScreen.svelte`: hlavička
  s brandingem (name/logo z appInfo store), karty DS — název, role
  (badge jen pro `admin`), tlačítko „Vstoupit" → `url_app`
  (`target="_blank" rel="noopener"`), prázdný stav („Zatím nemáte
  přiřazený žádný zdroj dat…"), loading/chyba.
- `frontend/src/App.svelte`: přihlášený && `appInfo.hasPortal` &&
  `!authStore.isAdmin` → `<PortalScreen/>` místo standardního shellu.
  Admin dostává standardní aplikaci (přístup admina na portálovou
  obrazovku je mimo F0 — testuj ne-admin účtem).
- i18n: všechny texty přes i18n klíče (cs + en), na závěr
  `npm run check:i18n` z `frontend/`
  (`PATH=/home/sebik/.nvm/versions/node/v24.14.0/bin:$PATH`).

## Testy

- `tests/Unit/Api/Controller/HostingPortalControllerTest.php`:
  ne-admin vidí jen své řádky; uživatel bez vazeb → prázdné pole;
  neaktivní modul (chybějící tabulka v `$tables`) → 404; archivované /
  ne-active lifecycle řádky se nevrací.
- Definice tabulek: ověř načtení přes existující vzor testů definic
  (fromArray na parsed JSONC) — minimálně smoke, že `adminOnly` je true.
- Frontend: pure logika (pokud vznikne helper) přes node:test; jinak
  stačí `check:i18n`.
- PHPUnit s úzkým `--filter 'HostingPortal'`.

## Commit strategie

1. `hosting: module skeleton — tables, viewers, install.hosting (D1, D9, D11)`
2. `hosting: portal endpoint /_hosting/portal/my-datasources (D10)`
3. `hosting: PortalScreen for non-admin users (D10)`

## Hotovo když

- [x] Nový DS s `install.hosting` projde `ds-upgrade`; admin v Nastavení
      vidí a edituje servery / zdroje dat / vazby uživatelů
- [x] Ne-admin dostane na generické CRUD/viewer/form nad
      `hosting_core_*` 403 (`FORBIDDEN_ADMIN_ONLY` z tasku 0a)
- [x] `GET /_hosting/portal/my-datasources` vrací přihlášenému jen jeho
      aktivní DS; na DS bez hosting modulu 404
- [x] `/_app/info` nese `hasPortal`; ne-admin po přihlášení vidí
      portálovou obrazovku s kartami a funkčními vstupními tlačítky,
      admin standardní aplikaci
- [x] i18n check zelený, testy zelené, `.md` dokumentace tabulek existuje
