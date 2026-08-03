# Task: Vlastní vzhledy — Fáze 3 (per-user persistence + Nastavení účtu)

**Stav:** hotovo

## Status / Cíl

Fáze 1 a 2 (`custom-theme-phase1.md`, `custom-theme-phase2.md`) přinesly
vzhled sidebaru (solid + gradienty + opacity) s persistencí v
**localStorage** — tedy per-prohlížeč/per-zařízení. Fáze 3 dělá z volby
vzhledu (a jazyka) **per-uživatelské nastavení**, které se drží na
serveru a přenáší se mezi zařízeními.

Vstup pro uživatele je nový režim aplikace **Nastavení účtu** (`account`)
— třetí mód vedle `app` a `settings`. Otevírá se z dropdownu v patce
sidebaru (položka „Nastavení účtu", dnes prázdný TODO handler), opouští
tlačítkem „← Zpět do aplikace" v hlavičce (stejný vzor jako Nastavení
aplikace). Sidebar v tomto módu zobrazuje vlastní strom se sekcí
**Základní**, kde žije stránka s výběrem vzhledu a jazyka.

Po dokončení platí:

- Existuje per-user key-value úložiště `core_system_user_settings` +
  služba `UserSettingsStore` (analogie `SettingsStore`, scoped na
  `user_id`).
- Settings page mechanismus má nové pole `scope` (`ds` | `user`, default
  `ds`); user-scope stránky čtou/píší přes `UserSettingsStore`.
- Settings page podporuje dva nové field typy: `theme` a `language`
  (řízené widgety vázané na klientské stores, hodnota se ukládá na
  server).
- Existuje třetí navigační mód `account` s vlastním stromem
  (`/_ui/account/navigation`), definovaným přes `global.accountSections`
  + pole `accountItems[]` v `module.jsonc`. Stránka **Základní**
  (`accountBasic`, scope `user`) má pole `theme` + `language`.
- **Server je zdroj pravdy** pro vzhled a jazyk; localStorage zůstává
  anti-flash cache. Po loginu se preference načtou ze serveru a
  sesynchronizují do `themeStore` / `language` + cache.
- Volba vzhledu z patky sidebaru (dropdown) funguje dál beze změny pro
  rychlé přepínání — jen nově po sobě synchronizuje server.

## Návaznost

- Závisí na: hotové Fázi 1+2 (`theme.svelte.js`, `themeColor.js`,
  `ThemePanel.svelte`); hotovém Settings módu a settings-page
  mechanismu (`docs/app-settings.md`, `SettingsController`,
  `SettingsStore`, `SettingsPage.svelte`); `core_system_users`.
- Sousední vzory: `frontend-settings-app.md` (mód + sidebar mode-aware,
  `settingsSections.jsonc`, `accountItems`-style parsing v
  `ModuleDefinition`), `docs/app-settings.md` (settings pages, scope,
  endpointy, `appInfoStore` jako vzor pro account-prefs store).
- Otevírá: další sekce Nastavení účtu (heslo, profil, notifikace),
  Fáze 4 (DS-wide default vzhledu od správce — efektivní téma =
  user pref ?? DS default ?? Shipard).

## Před implementací přečti

- `tasks/custom-theme-phase1.md` + `phase2.md` — potvrzená rozhodnutí,
  formát `shpd_theme_custom`, anti-flash bootstrap
- `docs/app-settings.md` — celý (settings pages, SettingsStore,
  branding, `appInfoStore` vzor pro prefs store, endpointy)
- `docs/frontend.md` — sekce 4 (mode systém app/settings), sekce 9
  (Konvence Svelte — `$effect` + fetch, dropdown/popover past),
  sekce 11 (Theme management)
- `frontend/src/stores/theme.svelte.js` — celý (`setMode`, `setCustom`,
  `applyTheme`, klíče, cache)
- `frontend/src/stores/language.svelte.js` — celý (`setMode`,
  `location.reload()`, bootstrap sync)
- `frontend/src/stores/navigation.svelte.js` — celý (mode pattern,
  `enterSettings`/`exitSettings`, activeItem per mode)
- `frontend/src/stores/appInfo.svelte.js` — vzor prefs storu (load při
  bootu, reaktivní čtení)
- `frontend/src/components/settings/SettingsPage.svelte` — celý
  (render definice, splitValues, save flow)
- `frontend/src/components/layout/Sidebar.svelte` — celý (860+ řádků;
  `handleSettings` TODO ~ř. 175, `$effect` na mode ~ř. 80, back-bar
  markup, dropdown patky)
- `src/Api/Controller/SettingsController.php` — celý (`navigation`
  dvouúrovňový strom, `page`/`savePage`, `collectItems`, `findPage`,
  store výběr)
- `src/Core/Settings/SettingsStore.php` — celý (vzor pro
  `UserSettingsStore`)
- `src/Core/Module/ModuleDefinition.php` — `fromArray` (parsing
  `settingsItems`, `settingsPages`; field typ whitelist `['text','image']`)
- `modules/core/system/module.jsonc` — `settingsPages`, `settingsItems`,
  `tables`, `keepOnReset`
- `modules/core/system/tables/core_system_users.jsonc` — FK target
- `modules/install/base/config/settingsSections.jsonc` — vzor pro
  `accountSections.jsonc`
- `src/Api/Router.php` (~ř. 59–75 settings routes) + `public/index.php`
  (`dispatchSettings` ~ř. 670, dispatch map ~ř. 272)
- `frontend/index.html` — theme + language bootstrap

## Scope

### V rozsahu

- **Backend — per-user storage:**
  - Tabulka `core_system_user_settings` (+ `tables[]`, `keepOnReset[]`
    v core.system)
  - Společné rozhraní `KeyValueStore` (interface) implementované
    `SettingsStore` i novým `UserSettingsStore`
  - `UserSettingsStore` (scoped na `user_id`)
- **Backend — scope + nové field typy v settings pages:**
  - `scope` (`ds`|`user`) v `settingsPages[]` + parsing v
    `ModuleDefinition`
  - Field typy `theme`, `language` v whitelistu + parsing
  - `SettingsController::page/savePage` — výběr storu podle scope,
    save logika pro `theme`/`language` (strukturovaná hodnota, ne jen
    text)
- **Backend — account mód:**
  - `global.accountSections` config (`accountSections.jsonc` v
    install.base) + registrace v `module.jsonc`
  - Pole `accountItems[]` v `ModuleDefinition` (sdílený parser se
    `settingsItems`)
  - `SettingsController::navigation()` parametrizace `kind`
    (`settings`|`account`)
  - Endpoint `GET /_ui/account/navigation` (Router + dispatch)
  - Page `accountBasic` (scope `user`, pole `theme` + `language`) +
    `accountItems` v core.system
  - `findPage` hledá i napříč moduly bez ohledu na kind (page lookup
    je společný)
- **Frontend — account mód:**
  - `navigation.svelte.js`: mód `account`, `accountActiveItem`,
    `enterAccount`/`exitAccount`
  - `Sidebar.svelte`: `handleSettings` → `enterAccount`; `$effect` URL
    o `account`; back button pro account
  - `SettingsPage.svelte`: větve pro `theme` a `language` field typy
- **Frontend — server sync:**
  - Store `accountPrefs.svelte.js` (vzor `appInfo`): load ze serveru
    po bootu/loginu, sync do `themeStore`/`language` + localStorage cache
  - `themeStore` / `language`: po lokální změně zapsat i na server
    (přes account prefs API)
- **Dokumentace:** `docs/app-settings.md` (scope, user store, nové
  field typy, account mód), `docs/frontend.md` (account mód),
  `docs/design-system.md` (sekce 9 — persistence per-user), `CLAUDE.md`
- **Testy:** `UserSettingsStore` (integration), `ModuleDefinition`
  (scope, nové field typy, `accountItems`), `SettingsController`
  (user-scope page save, account navigation)

### Mimo rozsah (budoucí fáze)

- **DS-wide default vzhledu od správce** (efektivní téma = user pref ??
  DS default ?? Shipard) — Fáze 4
- Další sekce Nastavení účtu (změna hesla, profil, e-mail, notifikace)
  — jen připravujeme infrastrukturu, ne obsah
- Migrace existující localStorage volby do serveru při prvním loginu
  (one-shot upload) — viz Rozhodnutí; zatím server vyhrává, lokální
  volba se přepíše po prvním fetchi
- Soft jazykový přepínač bez reloadu — jazyk dál přes `location.reload()`

## Datový tok

```
module.jsonc (accountItems + settingsPages se scope:user)   deklarace
        │
        ▼
GET /_ui/account/navigation       → strom { sekce Základní → page:accountBasic }
GET /_ui/settings/page/accountBasic   → { definition (theme/language fields), values }
POST /_ui/settings/page/accountBasic  → uložení theme JSON + language do user store
        │                                   (scope:user → UserSettingsStore($db, userId))
        ▼
UserSettingsStore ←→ core_system_user_settings (user_id, key, value)

Frontend boot/login:
  accountPrefs.load() → GET page/accountBasic values
      → themeStore.applyFromServer({mode, custom})  (cache do localStorage)
      → language sync (pokud se liší od cache → reload jednou)

Frontend změna (panel/dropdown/Základní stránka):
  themeStore.setMode/setCustom → aplikuje + localStorage cache + POST na server
  language.setMode             → localStorage + POST na server + reload
```

Klíče v user store:

| Klíč | Obsah |
|---|---|
| `account.theme` | JSON `{ mode: 'light'|'dark'|'custom', custom: {...config} }` |
| `account.language` | String `'cs'` | `'en'` | `'auto'` |

---

## Implementace

### Krok 1 — Per-user storage (tabulka + interface + store)

**1a. Tabulka** `modules/core/system/tables/core_system_user_settings.jsonc`:

```jsonc
{
    "tableId": <další volné id>,
    "name": "User settings",
    "name:cs": "Nastavení uživatelů",
    "name:en": "User settings",
    "hideFromNavigation": true,
    "columns": [
        { "id": "id", "name": "ID", "type": "int", "autoIncrement": true, "primaryKey": true },
        { "id": "user_id", "name": "User", "type": "int", "nullable": false },
        { "id": "key", "name": "Key", "type": "varchar", "length": 191, "nullable": false },
        { "id": "value", "name": "Value", "type": "text", "nullable": true },
        { "id": "modified", "name": "Modified", "type": "datetime", "nullable": true }
    ],
    "indexes": [
        {
            "id": "unq_user_key",
            "type": "unique",
            "columns": [ {"column": "user_id"}, {"column": "key"} ]
        }
    ],
    "foreignKeys": [
        {
            "id": "fk_user",
            "column": "user_id",
            "references": { "table": "core_system_users", "column": "id" },
            "onDelete": "CASCADE"
        }
    ]
}
```

Ověř tvar FK / index bloků proti existující tabulce s FK (např.
`core_system_sessions` nebo jiná v core.system) — syntaxe `foreignKeys`
se musí shodovat s tím, co umí table builder. Pokud projekt FK v JSONC
nepoužívá, vynech `foreignKeys` a spolehni se na `onDelete` logiku v
aplikaci (smazání usera je vzácné); v tom případě to poznamenej do
Rozhodnutí.

V `modules/core/system/module.jsonc` přidej `core_system_user_settings`
do `tables[]` **i** `keepOnReset[]` (preference přežijí reset, stejně
jako `core_system_settings`).

**1b. Interface** `src/Core/Settings/KeyValueStore.php`:

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Settings;

interface KeyValueStore
{
    public function get(string $key): mixed;
    /** @param string[] $keys @return array<string,mixed> */
    public function getMany(array $keys): array;
    public function set(string $key, mixed $value): void;
    public function delete(string $key): void;
}
```

`SettingsStore` necháme `implements KeyValueStore` (signatury už sedí).

**1c. `UserSettingsStore`** `src/Core/Settings/UserSettingsStore.php` —
kopie `SettingsStore`, konstruktor `(DataSourceConnection $db, int $userId)`,
TABLE = `core_system_user_settings`, každý dotaz scoped na `user_id`:

- `get`: `WHERE user_id = %i AND \`key\` = %s`
- `getMany`: `WHERE user_id = %i AND \`key\` IN %in`
- `set` (upsert): `INSERT (user_id, \`key\`, \`value\`, modified) ... ON DUPLICATE KEY UPDATE`
  — pozor, unique je `(user_id, key)`, takže ON DUPLICATE funguje
- `delete`: `deleteWhere(TABLE, 'user_id = %i AND \`key\` = %s', userId, key)`
- Request cache stejná (klíčem stačí `key`, instance je per-user)

Ověř placeholder syntaxi (`%i` / `%s` / `%in`) proti tomu, co
`DataSourceConnection` v projektu používá (viz `SettingsStore`).

### Krok 2 — `ModuleDefinition`: scope, field typy, accountItems

V `src/Core/Module/ModuleDefinition.php`:

**2a.** Field typ whitelist v `settingsPages` parsingu rozšířit:

```php
if (!in_array($type, ['text', 'image', 'theme', 'language'], true)) continue;
```

**2b.** Propustit `scope` na úrovni page (default `ds`):

```php
// uvnitř settingsPages foreach, po sestavení $fields:
$page['scope'] = (isset($page['scope']) && $page['scope'] === 'user') ? 'user' : 'ds';
$page['fields'] = $fields;
$settingsPages[] = $page;
```

**2c.** Sdílený parser settings/account items. Vyextrahuj stávající
`settingsItems` parsing do privátní statické metody
`parseNavItems(array $data, string $key): array` a zavolej ji pro
`'settingsItems'` i `'accountItems'`:

```php
$settingsItems = self::parseNavItems($data, 'settingsItems');
$accountItems  = self::parseNavItems($data, 'accountItems');
```

Přidej `public readonly array $accountItems` do konstruktoru
(default `[]`) a do `new self(...)`.

**Testy** (`tests/Unit/Core/Module/ModuleDefinitionTest.php`, rozšířit):
- `settingsPages` s `scope: 'user'` → `scope === 'user'`; bez scope →
  `'ds'`; neznámý scope → `'ds'`
- field typ `theme` / `language` projde; neznámý typ se zahodí
- `accountItems` se parsuje stejně jako `settingsItems`
- bez `accountItems` → `[]`

### Krok 3 — `SettingsController`: scope store + theme/language save + kind

**3a. Výběr storu podle scope.** Helper:

```php
private function storeForPage(array $pageDef, DataSourceConnection $db, AuthContext $auth): KeyValueStore
{
    if (($pageDef['scope'] ?? 'ds') === 'user') {
        // user scope vyžaduje přihlášeného uživatele
        return new UserSettingsStore($db, (int) $auth->userId);
    }
    return new SettingsStore($db);
}
```

`page()` i `savePage()` použijí `storeForPage(...)` místo natvrdo
`new SettingsStore($db)`. `page()` dnes `$auth` má; `savePage()` taky.
U user scope navíc po `isAuthenticated` check ověř `$auth->userId !== null`
(jinak 401).

**3b. Save logika pro theme/language.** V `savePage()` dnešní smyčka
bere jen `type === 'text'`. Rozšiř:

- `text` — beze změny (trim, maxLength, prázdný = null)
- `image` — ignoruje se dál (vlastní upload endpoint)
- `theme` — hodnota je objekt `{mode, custom}`; validuj `mode ∈
  {light,dark,custom}` a že `custom` je objekt (light/dark může mít
  custom prázdný/poslední známý). Ulož jako JSON (`$store->set('account.theme', $value)`).
  Klíč = `field['id']` (bude `account.theme`).
- `language` — hodnota je string `∈ {cs,en,auto}`; jinak validační chyba.
  Ulož string.

Pozn.: u `theme`/`language` je klíč = `field['id']`. Definuj pole s
`id: "account.theme"` a `id: "account.language"` (namespace `account.`).
Validace per typ, chyby do `$errors` ve stejném formátu (422 + details).

**3c. `buildPageValues`** — pro `theme`/`language` jen vrátí uloženou
hodnotu (`$raw[$id]`), stejně jako text. (Image větev beze změny.)
`localizePageDefinition` — pro `theme`/`language` propustit `type`,
`label`, `hint` (žádný `maxLength`/`slot`). Žádné extra metadaty pole
nepotřebuje (možnosti jsou fixní v klientovi).

**3d. `navigation()` parametrizace `kind`.** Přidej parametr
`string $kind = 'settings'`:

```php
public function navigation(
    DataSourceConfig $config,
    ModulePathResolver $resolver,
    string $language,
    ?ConfigRuntime $configRuntime,
    string $kind = 'settings',
): Response {
    ...
    $cfgItemId   = $kind === 'account' ? 'global.accountSections' : 'global.settingsSections';
    $sectionsCfg = $configRuntime->cfgItem($cfgItemId);
    ...
    $itemsBySection = $this->collectItems($resolvedModules, $resolver, $language, $kind);
}
```

`collectItems(..., string $kind)` — iteruj `$module->accountItems`
když `kind === 'account'`, jinak `$module->settingsItems`. Zbytek
(viewer/table/page tvary, dvouúroveň, řazení) beze změny.

`findPage()` — beze změny (hledá `settingsPages` napříč moduly bez
ohledu na kind; account page je taky v `settingsPages[]`).

### Krok 4 — Router + dispatch pro account navigaci

**Router** (`src/Api/Router.php`, vedle `/_ui/settings/navigation`):

```php
if ($subpath === '/_ui/account/navigation') {
    if ($method !== 'GET') {
        return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
    return new Route('settings', 'accountNavigation');
}
```

(Sdílíme controller `settings`, jen jiná action — page/savePage account
stránky jdou přes existující `/_ui/settings/page/{pageId}`, protože
page lookup je společný a scope řeší definice.)

**Dispatch** (`public/index.php`, `dispatchSettings` match):

```php
'navigation'        => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime, 'settings'),
'accountNavigation' => $ctrl->navigation($config, $modulePathResolver, $language, $configRuntime, 'account'),
```

### Krok 5 — Config sekce + account page v core.system

**5a.** `modules/install/base/config/accountSections.jsonc`:

```jsonc
{
    "sections": [
        {
            "id": "basic",
            "name": "Basic",
            "name:cs": "Základní",
            "name:en": "Basic",
            "icon": "settings",
            "order": 10
        }
    ]
}
```

Registrace v `modules/install/base/module.jsonc` `config[]`:

```jsonc
{ "id": "global.accountSections", "file": "config/accountSections.jsonc" }
```

**5b.** Page `accountBasic` v `modules/core/system/module.jsonc`
`settingsPages[]`:

```jsonc
{
    "id": "accountBasic",
    "scope": "user",
    "name": "Basic",
    "name:cs": "Základní",
    "name:en": "Basic",
    "icon": "settings",
    "fields": [
        {
            "id": "account.theme",
            "type": "theme",
            "name": "Appearance",
            "name:cs": "Vzhled",
            "name:en": "Appearance",
            "hint": "Sidebar color and light/dark base. Synced across your devices.",
            "hint:cs": "Barva sidebaru a světlý/tmavý základ. Synchronizováno mezi vašimi zařízeními.",
            "hint:en": "Sidebar color and light/dark base. Synced across your devices."
        },
        {
            "id": "account.language",
            "type": "language",
            "name": "Language",
            "name:cs": "Jazyk",
            "name:en": "Language",
            "hint": "Interface language.",
            "hint:cs": "Jazyk rozhraní.",
            "hint:en": "Interface language."
        }
    ]
}
```

A `accountItems[]` v core.system:

```jsonc
"accountItems": [
    { "page": "accountBasic", "section": "basic", "order": 10 }
]
```

**5c.** `vendor/bin/shpd-ds ds-upgrade` (manuálně Anna — vytvoří tabulku
`core_system_user_settings`, rekompiluje config sekce). Pak ověř:

```bash
curl -H 'Authorization: Bearer ...' .../api/v1/_ui/account/navigation | jq
curl -H 'Authorization: Bearer ...' .../api/v1/_ui/settings/page/accountBasic | jq
```

První vrátí strom se sekcí Základní → page:accountBasic. Druhý vrátí
definici se dvěma poli (theme, language) a values (zatím null/default).

### Krok 6 — Frontend: account mód v navigaci

`frontend/src/stores/navigation.svelte.js`:

- Přidej `let accountActiveItem = $state(null);`
- `navigate()`: větev `mode === 'account'` → `accountActiveItem = normalized`
- `activeItem` / `activeId` getter: tři-cestný (`account` → accountActiveItem,
  `settings` → settingsActiveItem, jinak appActiveItem)
- `enterAccount()` → `mode = 'account'`, `exitAccount()` → `mode = 'app'`
- Po vstupu do account nastav default activeItem na první page, pokud
  je `accountActiveItem === null` (analogie `ensureDefaultActiveItem`,
  ale pro account — nebo to nech na sidebaru, který po loadu navTree
  vybere první leaf; rozhodni podle toho, jak to dělá settings dnes —
  pokud settings nic nepředvybírá, account taky ne)
- Export `enterAccount`, `exitAccount`

### Krok 7 — Frontend: Sidebar mode-aware pro account

`frontend/src/components/layout/Sidebar.svelte`:

**7a.** `$effect` URL (dnes settings/app) rozšířit:

```js
const url = navigationStore.mode === 'settings'
  ? '/_ui/settings/navigation'
  : navigationStore.mode === 'account'
    ? '/_ui/account/navigation'
    : '/_ui/navigation';
```

`ensureDefaultActiveItem` volej jen pro `mode === 'app'` (beze změny).

**7b.** `handleSettings()` (dnes prázdný TODO) → vstup do account:

```js
function handleSettings() {
  navigationStore.enterAccount();
  if (layoutStore.isMobile) layoutStore.closeDrawer();
  // menu se zavře přes $effect na změnu módu (existující)
}
```

**7c.** Back-bar markup dnes podmíněn `navigationStore.mode === 'settings'`.
Změň na `!== 'app'` (zobraz v settings i account); handler
`handleExitSettings` → zobecni na `handleExitToApp` volající
`exitSettings`/`exitAccount` podle módu, nebo přidej `navigationStore.exitToApp()`
helper (oba módy → `mode = 'app'`). Doporučení: přidat do storu
`exitToApp()` (`mode = 'app'`) a v sidebaru volat ten — jednodušší než
větvení. Back button label `t('sidebar.backToApp')` zůstává.

**7d.** Pozor: `$effect` na změnu módu (zavírá user menu) už existuje a
funguje pro tři hodnoty bez úpravy (`void navigationStore.mode`).

### Krok 8 — Frontend: theme + language field typy v SettingsPage

`frontend/src/components/settings/SettingsPage.svelte` — `splitValues`
dnes dělí na text/image. Přidej zacházení s `theme`/`language`: tato
pole **nejdou přes Uložit tlačítko** (jako image — mění se okamžitě,
vázané na stores). Takže:

- `splitValues`: `theme`/`language` polím nastav lokální stav z hodnoty,
  ale primárně je vázej na `themeStore` / `language` (live).
- Render větev pro `field.type === 'theme'` → komponenta
  `<ThemeField />`, pro `'language'` → `<LanguageField />`.
- Uložit tlačítko zobraz jen když `hasTextFields` (už tak je) — account
  Basic nemá text pole, takže tlačítko zmizí, vše se ukládá okamžitě.

Nové komponenty (drobné, v `components/settings/`):

**`ThemeField.svelte`** — segmented control Shipard/Tmavý/Vlastní
(`themeStore.mode`), klik volá `themeStore.setMode(...)`; u „Vlastní"
tlačítko „Upravit barvu" → otevře ThemePanel (přes callback prop
`onOpenThemePanel`, který SettingsPage probublá z AppShellu — stejně
jako Sidebar dnes probublává `onOpenThemePanel`). Reuse `themeOptions`
tvar a ikony z `icons.js`. Po setMode se navíc volá server sync
(viz Krok 9 — `themeStore` to dělá interně).

**`LanguageField.svelte`** — segmented control / select cs/en/auto
(`language.mode`), klik volá `language.setMode(...)` (ten dělá reload +
nově server zápis). Reuse `languageOptions` tvar.

Pozn.: tyto widgety čtou stores reaktivně, takže panel i dropdown v
sidebaru zůstávají v synchronu se stránkou Základní (jedna pravda v
storu).

### Krok 9 — Frontend: server sync (accountPrefs store + zápisy)

**9a. Store** `frontend/src/stores/accountPrefs.svelte.js` (vzor
`appInfo.svelte.js`):

- `load()` — `GET /_ui/settings/page/accountBasic`, z `values` vezmi
  `account.theme` a `account.language`. Pokud existují, aplikuj:
  - `themeStore.applyFromServer(themeValue)` — nová metoda (viz 9b)
  - jazyk: pokud `account.language` ≠ aktuální `language.mode` →
    `language.setMode(serverLang)` (to reloadne). **Pozor na smyčku:**
    setMode reloadne, po reloadu load() zase porovná — ale po reloadu
    je `language.mode` už serverová hodnota (zapsaná do localStorage
    při setMode), takže shoda → žádný druhý reload. Ověř smoke testem.
  - Guard: aplikuj jazyk jen jednou (flag), ať reload-loop nehrozí.
- Volej `load()` v `main.js` po úspěšném loginu / při bootu, když je
  `authStore.isAuthenticated` (po appInfoStore.load, nebo vedle něj).

**9b. `themeStore` — server zápis + aplikace ze serveru.**

V `theme.svelte.js`:

- `applyFromServer({mode, custom})` — nastaví `mode` + `customConfig`
  ze serverové hodnoty, zavolá `applyTheme`, zapíše localStorage cache
  (klíče beze změny), **bez** dalšího serverového zápisu (zdroj je
  server).
- `setMode` / `setCustom` — po lokální aplikaci a localStorage zápisu
  navíc zavolají `pushToServer()` (debounce ~300 ms u setCustom kvůli
  color pickeru `oninput`). `pushToServer` = `POST /_ui/settings/page/accountBasic`
  s `{ values: { 'account.theme': { mode, custom } } }`. Selhání POST
  je tichý warning (lokál platí pro session, sync se dožene příště).
- Pozor na import cyklus: `theme.svelte.js` by nemělo importovat
  `accountPrefs` (ten importuje theme). Server push dej přímo do
  `theme.svelte.js` přes `api/client.js` `post`, nebo malý
  `api/account.js` helper. Žádný kruhový import.

**9c. `language` — server zápis.** `language.setMode` před `location.reload()`
zapiš `POST account.language`. Protože reload přijde hned, použij
`navigator.sendBeacon` nebo `await` POST před reloadem (await je
jednodušší — drobné zdržení akceptovatelné). Selhání → reload stejně
(lokální volba platí, sync příště).

**9d. localStorage zůstává anti-flash cache** — bootstrap v `index.html`
beze změny. Server je zdroj pravdy, ale aplikuje se až po async loadu;
cache drží poslední známý stav pro první render. Po loginu na novém
zařízení: první render = default/prázdná cache (krátký Shipard vzhled),
pak `accountPrefs.load()` aplikuje serverovou volbu a naplní cache;
další reloady jsou bez flashe. To je akceptováno (viz Rozhodnutí).

### Krok 10 — i18n + ikony

- i18n klíče (cs/en): `sidebar.accountSettings` už existuje (label
  dropdownu). Přidej `account.basic.appearance`, `account.basic.language`
  pokud widgety potřebují vlastní labely nad rámec field `label` ze
  serveru (label chodí lokalizovaný z definice — možná netřeba nic).
  Ověř `npm run check:i18n`.
- Ikony: sekce „Základní" používá `settings` (gear) — už v `iconMap`.
  Žádná nová ikona nutná, pokud nechceš odlišit.

---

## Akceptační kritéria (Hotovo když)

- [ ] `vendor/bin/shpd-ds ds-upgrade` (Anna) vytvoří
      `core_system_user_settings` (vč. unique `(user_id,key)`)
- [ ] `vendor/bin/phpunit` zelené — vč. nových `UserSettingsStore`,
      `ModuleDefinition` (scope, field typy, accountItems),
      `SettingsController` (user-scope save, account navigation)
- [ ] `cd frontend && npm run build 2>&1` — bez chyb a warningů
- [ ] `cd frontend && npm run check:i18n` — OK
- [ ] `curl /_ui/account/navigation` → strom se sekcí Základní →
      page:accountBasic
- [ ] `curl /_ui/settings/page/accountBasic` → definice (theme +
      language pole), values z user store
- [ ] Dropdown patky → „Nastavení účtu" → sidebar se přepne na account
      mód, sekce Základní, stránka s výběrem vzhledu + jazyka
- [ ] „← Zpět do aplikace" vrací do app módu na poslední položku
- [ ] Změna vzhledu na stránce Základní → okamžitý live preview, uloží
      se na server (ověř `core_system_user_settings` řádek
      `account.theme`)
- [ ] Změna vzhledu z dropdownu patky → též uloží na server (sync)
- [ ] Po odhlášení a přihlášení na **jiném prohlížeči** (čistý
      localStorage) se po `accountPrefs.load()` aplikuje uložený vzhled
      i jazyk
- [ ] Změna jazyka → reload + server zápis; po reloadu žádný druhý
      reload (no loop)
- [ ] Per-user izolace: vzhled uživatele A neovlivní uživatele B na
      stejném DS
- [ ] Vzhled (panel, dropdown, stránka Základní) drží jednu pravdu —
      změna na jednom místě se projeví na ostatních
- [ ] Mobil: account mód otevře drawer/obsah, segmented controls fungují
- [ ] Built-in light/dark beze změny chování; anti-flash funguje
- [ ] `docs/app-settings.md`, `docs/frontend.md`, `docs/design-system.md`,
      `CLAUDE.md` aktualizované
- [ ] `tasks/README.md` — task přesunout z Aktivní do hotových (v
      navazující session)

---

## Rozhodnutí k designu (potvrzená s Annou)

- ✓ **Per-user storage = nová tabulka `core_system_user_settings` +
  `UserSettingsStore`** (ne JSON sloupec na `core_system_users`). Čisté,
  rozšiřitelné pro budoucí per-user nastavení.
- ✓ **Nastavení účtu = plný mód `account`** s vlastním sidebarem a sekcí
  Základní (ne jen jedna stránka). Připraveno na další účet-sekce.
- ✓ **Rozsah Fáze 3 = vzhled + jazyk + infrastruktura** pro další sekce.
- ✓ **Základní stránka = deklarativní settings page** s novými field
  typy `theme` a `language` (ne dedikovaná komponenta mimo model).
  Widgety jsou řízené, vázané na stores.
- ✓ **Server = zdroj pravdy**, localStorage = anti-flash cache. Po
  loginu sync ze serveru. Krátký default-vzhled flash při prvním loginu
  na novém zařízení je akceptován (cache se naplní po prvním fetchi).
- ✓ **Account mód = třetí samostatný strom** (`global.accountSections`
  + `accountItems[]`), parametrizací stávajícího `navigation()` přes
  `kind`. Ne sdílení `settingsSections` s `mode` příznakem — čistší
  oddělení, žádná kontaminace settings ↔ account.
- ✓ **Klíče `account.theme` (JSON {mode, custom}) / `account.language`
  (string)** v user store.
- ✓ **Společné rozhraní `KeyValueStore`** pro `SettingsStore` a
  `UserSettingsStore`, aby `SettingsController` nezáviselo na konkrétní
  třídě.
- ✓ **Server vyhrává nad lokální volbou** při prvním fetchi (žádná
  one-shot migrace localStorage → server). Pokud bude vadit (uživatel
  s rozdělanou lokální volbou ji ztratí), migrace je drobný follow-up.

---

## Doporučené pořadí

1. Krok 1 (tabulka + `KeyValueStore` + `UserSettingsStore`) + test
   storu. `ds-upgrade` ověření tabulky.
2. Krok 2 (`ModuleDefinition` scope/typy/accountItems) + test.
3. Krok 3 (`SettingsController` scope store + theme/language save +
   kind) + test. Krok 4 (Router/dispatch). Krok 5 (config + page).
   `ds-upgrade` + curl ověření obou endpointů.
4. Krok 6 (`navigation.svelte.js` account) + Krok 7 (`Sidebar.svelte`
   mode-aware) — build, manuální test vstup/výstup account módu
   (stránka zatím může renderovat „neznámý typ pole" než přijde Krok 8).
5. Krok 8 (theme/language field typy + widgety) — build, vizuální test.
6. Krok 9 (accountPrefs store + server sync v theme/language) — smoke
   testy persistence a cross-device.
7. Krok 10 (i18n/ikony), dokumentace.

Commity granulárně po krocích, konvence `feat(account): ...` /
`feat(theme): ...` s `Co-Authored-By: Claude` footerem. Push dělá Anna.

## Konvence a upozornění

- **Svelte 5 runes**, props přes `$props()`, callback props.
- **`$effect` + fetch**: URL předávej explicitně z `navigationStore.mode`
  (viz `docs/frontend.md` § 9).
- **Žádný kruhový import** theme ↔ accountPrefs — server push v theme
  storu přes `api/client.js` / `api/account.js`, ne přes accountPrefs.
- **PHP 8.3 strict_types**, readonly properties; `composer dump-autoload`
  po nových src souborech.
- **Před `patch_file`** čti celý cílový soubor; `Sidebar.svelte` 860+
  řádků s diakritikou → pro edity s diakritikou Python heredoc workaround.
  `write_file` pro větší strukturální zásahy.
- **`ds-upgrade` spouští Anna ručně** (`run_command` běží jako `anna`
  bez práv k secrets).
- **i18n parita** cs/en + `npm run check:i18n`.
- **Tři synchronizovaná místa** localStorage klíčů/DS regexu
  (`theme.svelte.js`, `index.html`, `api/config.js`) z Fáze 1 — beze
  změny, jen neporušit.
- **Pre-existing test noise**: `Opis\JsonSchema\Validator not found` v
  Exchange/Mail testech je baseline (1 error ve filtrovaných bězích),
  nesouvisí.

## Otevřené otázky k ověření při implementaci

- **FK syntax v JSONC** — ověřit, zda table builder umí `foreignKeys`
  blok; pokud ne, FK vynechat (poznámka výše).
- **`tableId`** pro `core_system_user_settings` — najít další volné id
  (core.system má 1=users, 3=settings… zkontrolovat obsazená).
- **Předvýběr položky v account/settings módu** — sjednotit s tím, jak
  se chová settings dnes (předvybírá první leaf, nebo ne?). Account ať
  se chová stejně.
- **Reload-loop u jazyka** — guard flag v `accountPrefs.load()`, ověřit
  smoke testem #(jazyk).
