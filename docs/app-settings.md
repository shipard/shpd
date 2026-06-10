# Nastavení aplikace — settings pages + branding

Mechanismus **settings pages** (server-driven stránky vlastností v režimu
Nastavení) a jeho první konzument — stránka **Aplikace** (název, zkrácený
název, ikona, firemní logo).

## 1. Koncept

Settings page je třetí typ položky v Nastavení vedle vieweru a tabulky:
formulář vlastností, jehož hodnoty žijí v key-value tabulce
`core_system_settings` (tableId 3, unique `key`, JSON `value`). Tabulka je
v `keepOnReset` modulu `core.system` — nastavení přežívá `ds-reset`.

Tok dat:

```
module.jsonc (settingsPages + settingsItems)        deklarace
        │
        ▼
GET /_ui/settings/navigation     → nav item { type: 'page', pageId }
GET /_ui/settings/page/{pageId}  → { definition, values }
POST /_ui/settings/page/{pageId} → uložení textových polí (whitelist)
        │
        ▼
SettingsStore (PHP) ←→ core_system_settings
```

### SettingsStore — `src/Core/Settings/SettingsStore.php`

Tenká služba nad `core_system_settings`: `get` / `getMany` / `set` /
`delete`, hodnoty JSON-encodované, request-level cache. Konstruktor bere
jen `DataSourceConnection` — volatelná z HTTP i CLI (sestavy, generátor
faktur). `set($key, null)` klíč maže; klíče používají tečkové namespacy.

### Pravidla ukládání (`savePage`)

- Ukládají se **jen textová pole definovaná ve stránce** (whitelist);
  klíče mimo definici a `image` pole se tiše ignorují.
- Hodnoty se trimují; validuje se `maxLength` (422 s details per pole).
- **Prázdný string = smazání klíče** → čtenáři padnou na fallback
  (např. `app.name` → `main.json` `name`).

## 2. Klíče `app.*`

| Klíč | Obsah | Fallback |
|------|-------|----------|
| `app.name` | Plný název zdroje dat | `main.json` → `name` (read-only instalační config) |
| `app.shortName` | Titulek tabu prohlížeče + text v sidebaru | `app.name` → `main.json` name |
| `app.icon` | Metadata branding slotu `icon` (favicon, sidebar) | žádná ikona (browser default) |
| `app.companyLogo` | Metadata branding slotu `companyLogo` (login, později sestavy/faktury) | žádné logo |

Metadata obrázkových klíčů zapisuje upload endpoint:
`{ filename, storedAs, mime, size, hash (sha256, 16 zn.), modified }`.

## 3. Branding sloty

Obrázky jako soubory v adresáři **`branding/`** v kořeni DS (vedle
`att/`), single-slot sémantika — slot drží nejvýš jeden soubor
`branding/{slot}.{ext}`, nový upload smaže starý soubor i s jinou
příponou. Adresář zakládají `ds-create` i `ds-upgrade`; `ds-reset` se ho
nedotýká → branding přežívá reset.

- **Sloty:** `icon`, `companyLogo` — whitelist `BrandingStorage::SLOTS`.
- **Povolené mime:** PNG, JPEG, WebP, SVG; pro slot `icon` navíc ICO.
  Detekce přes `finfo` z obsahu (ne z přípony); SVG bez XML deklarace
  finfo nepozná — fallback kontrola začátku obsahu (`<svg` / `<?xml`).
- **Max velikost:** 2 MB (`BrandingStorage::MAX_FILE_SIZE`).
- Logika v `src/Core/Settings/BrandingStorage.php` — bez závislosti na
  HTTP, logo čte i CLI/generátor sestav.

## 4. Endpointy — `AppController`

| Metoda | URL | Auth | Popis |
|--------|-----|------|-------|
| `GET` | `/_app/info` | **veřejné** | `{ name, shortName, icon, companyLogo }` — icon/logo jako `{url, hash}` nebo `null` |
| `GET` | `/_app/branding/{slot}` | **veřejné** | Binární obsah; `Cache-Control: immutable` (cache-busting přes `?h={hash}`); SVG s CSP + nosniff |
| `POST` | `/_app/branding/{slot}` | auth | Multipart upload (pole `file`), vrací metadata |
| `DELETE` | `/_app/branding/{slot}` | auth | Smaže soubor i settings klíč |

Veřejné GET jsou vědomé rozhodnutí — login obrazovka zobrazuje název/logo
a favicon se načítá bez tokenu. Do `/_app/info` nesmí přibýt nic citlivého
(DB jméno, moduly, uživatelé). Výjimky z auth: `AuthMiddleware::isExempt()`
matchuje akce `('app', 'info')` a `('app', 'brandingGet')` — zápisové akce
mají vlastní action názvy (`brandingUpload`, `brandingDelete`), takže
exempt nejsou.

## 5. Frontend

- **`stores/appInfo.svelte.js`** — načítá `/_app/info` při bootu
  (`main.js`, před loginem) a po každé změně nastavení/obrázku. `apply()`
  nastavuje `document.title` a `<link rel="icon">`; sidebar/header/login
  čtou store reaktivně.
- **`components/settings/SettingsPage.svelte`** — render definice
  (label vlevo, input + hint vpravo), Uložit → POST, po uspěchu
  `appInfoStore.load()`.
- **`components/settings/ImageSlotField.svelte`** — náhled + Nahrát/Odebrat,
  upload jde okamžitě (mimo tlačítko Uložit) přes `api/app.js`.
- Nav typ `page`: `ContentArea.svelte` → `<SettingsPage tab={activeItem} />`,
  `navigation.svelte.js` normalizuje `pageId`.

## 6. Jak přidat další stránku / pole

1. Do `module.jsonc` modulu přidej `settingsPages` (id, name+`:cs`/`:en`,
   icon, fields) a `settingsItems: [{ "page": "<id>", "section": "...", "order": N }]`.
   Sekce musí existovat v `modules/install/base/config/settingsSections.jsonc`.
2. Klíče polí namespacuj podle modulu (`mail.signature`, ne `signature`).
3. Textové pole: `type: "text"` (+ `maxLength`, `hint:cs`). Obrázkové pole
   vyžaduje branding slot — slot je potřeba přidat do
   `BrandingStorage::SLOTS` + `SLOT_SETTINGS_KEYS` (whitelist je vědomě
   v PHP, ne v JSONC).
4. `vendor/bin/shpd-ds ds-upgrade` v dev DS (kvůli rekompilaci
   settingsSections, pokud přibyla sekce).
5. Hodnoty čti v PHP přes `new SettingsStore($db)->get('klíč')` s fallbackem
   na rozumný default — klíč nemusí existovat.

Další typy polí (`select`, `checkbox`, `textarea`) přijdou s první
stránkou, která je potřebuje — struktura definice je na to připravená
(parser v `ModuleDefinition::fromArray()` whitelistuje typy).

## 7. Testy

- `tests/Integration/Settings/SettingsStoreTest.php` — get/set/delete,
  JSON round-trip, upsert, cache (vyžaduje `SHIPARD_INTEGRATION_DS_PATH`).
- `tests/Unit/Api/Controller/SettingsControllerTest.php` — page/savePage
  nad reálnou definicí appSettings, mockovaná DB.
- `tests/Unit/Api/Controller/AppControllerTest.php` — info fallbacky,
  upload validace, hlavičky, delete.
- `tests/Unit/Core/Settings/BrandingStorageTest.php` — mime detekce
  (vč. SVG fallbacku), sloty, store/replace/delete.

Spouštění: `vendor/bin/phpunit --filter 'Settings|App|Branding'`.
