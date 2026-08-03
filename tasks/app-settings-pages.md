# Nastavení aplikace — settings pages + branding (název, ikona, logo)

**Stav:** hotovo

## Kontext

Uživatel si dnes nemůže v UI nastavit základní vlastnosti zdroje dat — název je v `config/main.json` (editace jen v terminálu), zkrácený název, ikona prohlížeče a firemní logo neexistují vůbec. Sidebar má natvrdo `Shipard`, `frontend/index.html` natvrdo `<title>Shipard</title>`, favicon žádný.

Cílem je:

1. **Obecný mechanismus „settings pages"** — třetí typ položky v Nastavení (vedle `viewer` a `table`): server-driven stránka s formulářem vlastností, hodnoty v key-value tabulce `core_system_settings`. Mechanismus bude znovupoužitelný pro budoucí parametrické stránky dalších modulů.
2. **První konkrétní stránka „Aplikace"** — název, zkrácený název, ikona (favicon + sidebar), firemní logo (později výstupní sestavy/faktury).
3. **Branding storage** — adresář `branding/` v DS pro obrázkové sloty, veřejný GET endpoint.
4. **Propsání do frontendu** — titulek tabu prohlížeče, text v sidebaru, favicon, vše dynamicky z API.

## Návaznost

- Tabulka `core_system_settings` (tableId 3) už existuje (key-value, unique `key`, JSON `value`, sloupec `modified`), je v `keepOnReset` modulu `core.system` — **zatím ji žádný kód nepoužívá**, tohle je její první konzument.
- Settings navigace: `SettingsController::navigation()` + `/_ui/settings/navigation`, sekce z `global.settingsSections` (`modules/install/base/config/settingsSections.jsonc`).
- `ModuleDefinition::fromArray()` dnes validuje settingsItems jako „právě jedno z viewer|table".
- Frontend: `ContentArea.svelte` přepíná podle `activeItem.type` (`dashboard|chat|viewer|table`), settings strom načítá `Sidebar.svelte` z `/_ui/settings/navigation`.
- Logo firmy ze slotu `companyLogo` později použije generátor faktur — proto `SettingsStore` a čtení brandingu navrhnout tak, aby šly volat i mimo HTTP kontext (CLI, sestavy).

## Před implementací přečti

- `src/Api/Controller/SettingsController.php` — navigace, struktura nav items (`type`, `id`, `label`)
- `src/Core/Module/ModuleDefinition.php` — parsování `settingsItems` (řádky ~44–59)
- `src/Api/Router.php` + `src/Api/Middleware/AuthMiddleware.php` (`isExempt()`) — routing a výjimky z auth
- `src/Api/Controller/AttachmentController.php` — vzor pro multipart upload a `sendFile()` (download/thumbnail)
- `modules/core/attachments/src/FileStorage.php`, `ThumbnailGenerator.php` — vzor validace a práce s obrázky (GD)
- `frontend/src/components/layout/ContentArea.svelte`, `Sidebar.svelte`, `App.svelte`
- `frontend/src/components/form/FormElement.svelte` — styling polí, ať SettingsPage vypadá konzistentně
- `frontend/src/api/client.js`, `config.js` — `apiRequest`, `API_BASE_URL`
- `docs/modules.md`, `docs/rest-api.md`

## Scope

**V rozsahu:** SettingsStore (PHP), settings pages mechanismus (deklarace v module.jsonc, GET/POST endpointy, nav typ `page`), stránka `appSettings` v `core.system`, sekce „Aplikace" v settingsSections, branding adresář + endpointy (PUT/DELETE auth, GET veřejný), `GET /_app/info` (veřejný), frontend: `SettingsPage.svelte`, `ImageSlotField.svelte`, appInfo store, dynamický titulek/favicon/sidebar, testy, dokumentace.

**Mimo rozsah:** použití loga na fakturách (jen připravit čtení), další typy polí nad rámec `text`/`image` (select, checkbox apod. přijdou s další stránkou), vícejazyčné hodnoty nastavení, oprávnění per-stránka (zatím každý přihlášený uživatel).

## Co implementovat

### 1. `SettingsStore` — `src/Core/Settings/SettingsStore.php`

Tenká služba nad `core_system_settings`:

- `get(string $key): mixed` — vrátí dekódovanou JSON hodnotu nebo `null`
- `getMany(array $keys): array` — mapa key→value
- `set(string $key, mixed $value): void` — upsert (INSERT … ON DUPLICATE KEY / select+update), nastaví `modified`
- `delete(string $key): void`
- Request-level cache (privátní pole), konstruktor bere `DataSourceConnection` — volatelné z HTTP i CLI.

Konvence klíčů: tečkové namespacy, pro tuto stránku `app.*`.

### 2. Settings pages — deklarace v `module.jsonc`

Nový top-level klíč `settingsPages` (pole objektů, konvence jako `viewers`):

```jsonc
"settingsPages": [
    {
        "id": "appSettings",
        "name": "Application",
        "name:cs": "Aplikace",
        "name:en": "Application",
        "icon": "settings",
        "fields": [
            {
                "id": "app.name",            // klíč v core_system_settings
                "type": "text",
                "name": "Name", "name:cs": "Název zdroje dat", "name:en": "Data source name",
                "hint:cs": "Plný název — výchozí hodnota pochází z instalace.",
                "maxLength": 120
            },
            {
                "id": "app.shortName",
                "type": "text",
                "name:cs": "Zkrácený název", "name:en": "Short name",
                "hint:cs": "Titulek tabu prohlížeče a sidebaru.",
                "maxLength": 30
            },
            {
                "id": "app.icon",
                "type": "image",
                "slot": "icon",
                "name:cs": "Ikona aplikace", "name:en": "Application icon",
                "hint:cs": "Ikona v prohlížeči (favicon) a v sidebaru. Ideálně čtvercová, PNG."
            },
            {
                "id": "app.companyLogo",
                "type": "image",
                "slot": "companyLogo",
                "name:cs": "Logo firmy", "name:en": "Company logo",
                "hint:cs": "Použije se na výstupních sestavách (faktury apod.)."
            }
        ]
    }
]
```

- `ModuleDefinition`: parsovat `settingsPages` (id povinné, fields pole; tolerantní skip vadných položek jako u settingsItems), nová readonly property.
- `settingsItems` rozšířit o třetí variantu `"page": "<pageId>"` — validace „právě jedno z viewer|table|page".
- Typ pole zatím `text` a `image`; struktura připravená na rozšíření (`textarea`, `select`, …).

### 3. Endpointy settings pages

Router — rozšířit větev `/_ui/settings/`:

- `GET  /_ui/settings/page/{pageId}` → `Route('settings', 'page', $pageId)`
- `POST /_ui/settings/page/{pageId}` → `Route('settings', 'savePage', $pageId)`

`SettingsController`:

- `page()` — najde definici stránky napříč resolved moduly, lokalizuje labely/hinty (vzor `localizeViewerName`), načte hodnoty přes `SettingsStore::getMany()`. Pro `image` pole přidá aktuální stav slotu (viz §5: `{url, hash, filename, mime}` nebo `null`). Odpověď: `{ definition: {...}, values: {...} }`.
- `savePage()` — body `{ values: { "app.name": "...", ... } }`. Uloží **jen klíče definované ve stránce** (whitelist z definice), textová pole ořeže (`trim`), validuje `maxLength`, prázdný string ukládá jako `null` (= smazat klíč → fallback). Obrázky se NEukládají tudy (mají vlastní upload endpoint, viz §5) — `image` klíče v `values` ignorovat.
- `navigation()` — `collectItems()` rozšířit o větev `page`: nav item `{ id: 'page:appSettings', label, type: 'page', pageId, icon }`, dedup přes `$seenPages`.

### 4. Sekce „Aplikace" + zapojení stránky

- `modules/install/base/config/settingsSections.jsonc`: nová sekce `{ "id": "app", "name:cs": "Aplikace", "name:en": "Application", "icon": "settings", "order": 1 }` — první, nad Účetnictvím.
- `modules/core/system/module.jsonc`: přidat `settingsPages` (viz §2) a `settingsItems: [{ "page": "appSettings", "section": "app", "order": 10 }]`.

### 5. Branding storage + endpointy

**Adresář:** `branding/` v kořeni DS (vedle `att/`). `DsCreate`/`DsUpgrade` ho zakládají automaticky (stejně jako `att/`). `ds-reset` se ho **nedotýká** (žádný cleanup nepřidávat) a `core_system_settings` je v `keepOnReset` → branding i nastavení přežijí reset bez další práce.

**Sloty:** whitelist `['icon', 'companyLogo']` — konstanta v PHP. Soubor uložen jako `branding/{slot}.{ext}`.

**Validace uploadu:** povolené mime `image/png`, `image/jpeg`, `image/webp`, `image/svg+xml`; pro slot `icon` navíc `image/x-icon`/`image/vnd.microsoft.icon`. Max velikost 2 MB. Mime ověřit přes `finfo` z obsahu, ne z přípony (u SVG sniffing nefunguje spolehlivě — fallback kontrola, že obsah začíná `<svg`/`<?xml`).

**Metadata:** po uploadu zapsat přes `SettingsStore` klíč pole (`app.icon` / `app.companyLogo`) = `{ "filename": původní jméno, "storedAs": "icon.png", "mime": "...", "size": 12345, "hash": sha256 zkrácený na 16 zn., "modified": ISO }`. Delete endpoint smaže soubor i klíč (= `null`).

**Endpointy** (Router: nový prefix `/_app/`, nový `AppController` v `src/Api/Controller/`):

- `POST /_app/branding/{slot}` — multipart upload (pole `file`), vyžaduje auth. Při nahrání nového souboru s jinou příponou smazat starý soubor slotu. Vrací nová metadata.
- `DELETE /_app/branding/{slot}` — vyžaduje auth.
- `GET /_app/branding/{slot}` — **veřejný** (výjimka v `AuthMiddleware::isExempt()`). Vrací binárně přes `sendFile` vzor z AttachmentController. Headers: `Cache-Control: public, max-age=31536000, immutable` (URL nese `?h={hash}` pro cache-busting), správný `Content-Type`. **Pro SVG navíc `Content-Security-Policy: default-src 'none'` a `X-Content-Type-Options: nosniff`** (ochrana proti XSS při přímé navigaci na URL). 404 když slot prázdný.

### 6. `GET /_app/info` — veřejný

`AppController::info()`, výjimka v `isExempt()`. Odpověď:

```json
{
    "name": "Moje firma s.r.o.",        // app.name, fallback main.json "name"
    "shortName": "Moje firma",          // app.shortName, fallback name
    "icon": { "url": "/_app/branding/icon?h=ab12…", "hash": "ab12…" },   // nebo null
    "companyLogo": { "url": "…", "hash": "…" }                            // nebo null
}
```

Veřejné vědomě — název a ikona se zobrazí už na login obrazovce; nic citlivého (DB jméno, moduly, uživatelé) sem nepatří. URL relativní k `API_BASE_URL` (frontend si prefix doplní sám, viz `config.js`).

### 7. Frontend

- **`api/app.js`** — `getAppInfo()`, `uploadBranding(slot, file)` (multipart — pozor, `apiRequest` posílá JSON; upload udělat vlastním `fetch` s Bearer hlavičkou bez `Content-Type`, vzor v `api/attachments.js`), `deleteBranding(slot)`, `brandingUrl(slot, hash)`.
- **`stores/appInfo.svelte.js`** — `$state` s `{name, shortName, icon, companyLogo}`, `load()` (volá getAppInfo), `apply()`:
  - `document.title = shortName`
  - favicon: najít/vytvořit `<link rel="icon">`, `href = brandingUrl('icon', hash)`; bez ikony ponechat default
  - Volat při bootu aplikace (v `App.svelte`, nezávisle na přihlášení — endpoint je veřejný) a znovu po uložení stránky Aplikace / uploadu ikony.
- **`Sidebar.svelte`** — `<span class="shpd-sidebar__logo">` místo natvrdo `Shipard` zobrazí `appInfoStore.shortName ?? 'Shipard'`.
- **Login obrazovka** — zobrazit `name` (a logo, pokud je) nad přihlašovacím formulářem; decentně, bez velkých zásahů.
- **`ContentArea.svelte`** — nová větev `activeItem?.type === 'page'` → `<SettingsPage tab={activeItem} />`.
- **`components/settings/SettingsPage.svelte`** — načte `GET /_ui/settings/page/{pageId}`, vyrenderuje pole:
  - `text` → label + input, styling konzistentní s form fieldy (převzít CSS třídy/vzor z FormElement, ale bez závislosti na FormEditor — settings page není vázaná na tabulku/záznam)
  - `image` → `ImageSlotField.svelte`
  - Tlačítko **Uložit** → `POST` values, úspěch/chyba hlášení; po uložení `appInfoStore.load()`.
- **`components/settings/ImageSlotField.svelte`** — náhled (`<img src={brandingUrl(slot, hash)}>` nebo placeholder), tlačítka Nahrát (file input, hned uploadne přes `uploadBranding`) a Odebrat; po změně refresh metadat ve `values` + `appInfoStore.load()`. Ukázat jméno souboru a velikost.
- **i18n** — nové klíče v `frontend/src/i18n/` (cs + en): uložit, nahrát, odebrat, hlášky úspěch/chyba, „Nastavení aplikace" texty.

### 8. Testy

- `SettingsStoreTest` — get/set/delete, JSON round-trip, upsert, cache (integration, `SHIPARD_INTEGRATION_DS_PATH`).
- `SettingsControllerTest` — page() vrací definici+hodnoty, savePage() whitelist (klíč mimo definici se neuloží), maxLength, prázdný string → delete.
- `AppControllerTest` — info() fallbacky (bez app.name → main.json name; shortName fallback na name), branding upload (mime validace, size limit, slot whitelist), GET headers (Cache-Control, CSP u SVG), DELETE.
- `AuthMiddlewareTest` — rozšířit o výjimky `_app/info` a `GET _app/branding/{slot}` (POST/DELETE auth vyžadují).
- `ModuleDefinitionTest` — settingsPages parsing, settingsItems s `page`.
- PHPUnit spouštět s úzkým filtrem (`--filter 'Settings|App|ModuleDefinition'`).

### 9. Dokumentace

- `docs/modules.md` — sekce o `settingsPages` + `settingsItems` s `page`.
- `docs/rest-api.md` — `/_ui/settings/page/{id}`, `/_app/info`, `/_app/branding/{slot}` (vč. poznámky o veřejných endpointech a cache).
- Nový krátký `docs/app-settings.md` — koncept settings pages, klíče `app.*`, branding sloty, jak přidat další stránku/pole.

## Hotovo když

1. V Nastavení existuje sekce **Aplikace** se stránkou, kde jdou editovat název, zkrácený název, ikona a logo — bez sahání do souborů.
2. Po uložení zkráceného názvu se změní titulek tabu prohlížeče a text v sidebaru (po reloadu i okamžitě po uložení).
3. Po nahrání ikony se objeví favicon v prohlížeči; po odebrání zmizí (fallback default).
4. `GET /_app/info` a `GET /_app/branding/{slot}` fungují bez tokenu; `POST`/`DELETE` branding bez tokenu vrací 401.
5. `app.name` má přednost před `main.json` → název v UI, ale `main.json` zůstal nezměněn.
6. `ds-reset` nezničí nastavení ani nahrané obrázky (ověřit ručně na dev DS).
7. Upload odmítne nepovolený mime a soubor > 2 MB se srozumitelnou chybou.
8. Login obrazovka zobrazuje název (a logo, je-li nahráno).
9. Testy zelené (úzký filtr), dokumentace doplněna.

## Doporučené pořadí

1. `SettingsStore` + testy
2. `ModuleDefinition` — settingsPages + page item + testy
3. Backend endpointy: settings page GET/POST → branding (AppController, auth výjimky) → `/_app/info`
4. Deklarace: settingsSections sekce `app`, `core.system` module.jsonc (page + item)
5. Frontend: appInfo store + boot (titulek/favicon/sidebar) → SettingsPage + ImageSlotField → login obrazovka
6. Testy, dokumentace, ruční ověření ds-reset

Commity logicky oddělit: (1) SettingsStore + module parsing, (2) backend endpointy, (3) frontend, (4) docs.

## Rozhodnutí ✓

- Hodnoty v `core_system_settings` (key-value, klíče `app.*`), ne v souborech v `config/` — web proces do `config/` nezapisuje.
- `app.name` má **přednost** s fallbackem na `main.json` `name`; `main.json` zůstává read-only instalační config.
- Obrázky jako soubory ve `branding/` se single-slot sémantikou + metadata v settings; **ne** přes attachments subsystém.
- Branding i settings **přežívají `ds-reset`** (settings přes existující `keepOnReset`, branding tím, že se ho reset nedotýká).
- Obecný mechanismus **settings pages** (nový typ položky `page`), ne jednoúčelová stránka.
- `GET /_app/info` a `GET /_app/branding/{slot}` jsou **veřejné** (login obrazovka, favicon bez tokenu); zápisové operace vyžadují auth.
- SVG povoleno, ale servírováno s `CSP: default-src 'none'` + `nosniff`.
- Nová sekce Nastavení „Aplikace" s `order: 1`.

## Otevřené body

- Limit 2 MB a seznam mime typů — navržené hodnoty, případně upravit dle praxe.
- Resize/normalizace ikony na straně serveru (např. generovat 32/180/512 px varianty přes GD jako ThumbnailGenerator) — v této fázi vynecháno, ikona se servíruje tak, jak byla nahrána; doplnit, až bude potřeba (PWA manifest apod.).
- Oprávnění: zatím může nastavení měnit každý přihlášený uživatel — až přijde role/permission systém, omezit na admina.
- Vícejazyčný název DS — zatím jedna hodnota.
