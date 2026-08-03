# Task: Vlastní vzhledy — Fáze 4 (DS-wide default vzhledu)

**Stav:** hotovo

## Status / Cíl

Fáze 1–3 daly uživateli vlastní vzhled sidebaru s persistencí
localStorage (F1+2) a per-user na serveru (F3, `account.theme`
v `core_system_user_settings`, mód `account` / Nastavení účtu, sync přes
`accountPrefs`). Dnes je efektivní vzhled prostě **user pref ?? Shipard**.

Fáze 4 vsouvá mezičlánek: **DS-wide default vzhledu**, který se nastaví
v Nastavení aplikace → Aplikace a platí pro všechny uživatele daného
zdroje dat, kteří si nezvolili vlastní. Nová efektivní logika:

```
efektivní vzhled = (uživatel má vlastní vzhled) ? user override
                                                 : (DS default ?? Shipard)
```

Vstup uživatele do „mám vlastní" řeší **přepínač „Vlastní vzhled"**
na stránce Nastavení účtu → Základní. Vypnuto = sleduji DS default
(včetně jeho budoucích změn). Zapnuto = mám vlastní volbu a můžu se
přepínačem vrátit zpět ke sledování.

Zároveň z patky sidebaru **mizí dropdown vzhledu** (light/dark/custom).
Vzhled je nadále „nastavení", ne „rychlý přepínač" — vše se řeší
v Nastavení účtu → Základní. Panel s presety/pickerem zůstává, otevírá
ho `ThemeField` na stránce Základní (přes `onOpenThemePanel`, jako dnes).

Po dokončení platí:

- Nastavení aplikace → Aplikace (`appSettings`, scope `ds`) má nové pole
  `app.theme` [type `theme`] — DS default. Uloží se do
  `core_system_settings['app.theme']` jako `{mode, custom}`. Chybí →
  vestavěný Shipard light.
- `account.theme` (scope `user`) má nově `follow` flag:
  `{follow: true}` = sleduj DS default; `{follow: false, mode, custom}`
  = vlastní override. Nový uživatel = `{follow: true}`.
- Klient počítá efektivní vzhled z obou hodnot. DS default se dostane
  na klienta přes `appInfo` store (vedle brandingu) a má vlastní
  localStorage anti-flash cache, aby ani follow-uživatel neviděl flash.
- Dropdown vzhledu v patce sidebaru je odstraněn; přepínání light/dark
  i custom jede jen z Nastavení účtu → Základní.

## Návaznost

- Závisí na: hotové Fázi 1–3 (`theme.svelte.js` s `applyFromServer` /
  `pushToServer` / `follow`-ready formátem `account.theme`;
  `accountPrefs.svelte.js`; `ThemeField.svelte`; `LanguageField.svelte`;
  mód `account` v `navigation.svelte.js` + `Sidebar.svelte`;
  `SettingsController` scope `ds`/`user`; `SettingsStore` +
  `UserSettingsStore` + `KeyValueStore`; field typ `theme` v
  `ModuleDefinition` whitelistu).
- Sousední vzory: `custom-theme-phase3.md` (per-user sync, accountPrefs,
  field typy), `custom-theme-phase1.md` (anti-flash bootstrap, OKLCH
  derivace, formát `shpd_theme_custom`), `docs/app-settings.md`
  (settings pages, scope, `appInfo` jako vzor DS-wide store, branding
  v `appInfo`).
- Otevírá: omezení DS-wide nastavení jen na správce (role/oprávnění) —
  dnes záměrně mimo rozsah, nastavit smí kdokoli s přístupem do
  Nastavení aplikace.

## Před implementací přečti

- `tasks/custom-theme-phase3.md` + `phase1.md` — potvrzená rozhodnutí,
  formát `account.theme`, anti-flash princip, scope mechanismus
- `docs/app-settings.md` — celý (settings pages, scope, `appInfo` store
  + branding, endpointy `page`/`savePage`)
- `docs/design-system.md` — sekce 9 (Vzhledy) — rozšíří se o DS default
- `docs/frontend.md` — sekce 11 (Theme management), sekce 9 (`$effect`
  + fetch konvence)
- `frontend/src/stores/theme.svelte.js` — celý (`applyFromServer`,
  `pushToServer`, `setMode`/`setCustom`, `DEFAULT_CUSTOM`, storageKey,
  applyTheme, MODE/CUSTOM/TOKENS klíče)
- `frontend/src/stores/accountPrefs.svelte.js` — celý (`load`, aplikace
  theme/language ze serveru)
- `frontend/src/stores/appInfo.svelte.js` — celý (load při bootu,
  branding; sem přibude DS default theme)
- `frontend/src/components/settings/ThemeField.svelte` — celý (segmented
  control, presety, `onOpenThemePanel`); přibude follow přepínač +
  `showFollow` prop
- `frontend/src/components/settings/SettingsPage.svelte` — render větev
  `theme` (~ř. 155); appSettings je scope ds, accountBasic scope user
- `frontend/src/components/layout/Sidebar.svelte` — `themeOptions`
  (~ř. 170), `handleThemeChange` (~ř. 176), user-menu blok vzhledu
  (~ř. 435–445), `onOpenThemePanel` prop (~ř. 50)
- `frontend/index.html` — theme bootstrap (~ř. 18–35); přibude DS
  default cache klíč (čtvrté synchronizované místo)
- `src/Api/Controller/SettingsController.php` — `savePage` theme
  validace (ověřit, že větev je klíčovaná typem pole, ne id, takže
  `app.theme` projde stejně jako `account.theme`), `buildPageValues`
- `modules/core/system/module.jsonc` — `appSettings` page (přidá se
  pole `app.theme`)
- `frontend/src/api/account.js` — `pushAccountPrefs` (vzor pro případný
  DS push, pokud bude potřeba; DS default se ale ukládá přes standardní
  savePage appSettings)

## Scope

### V rozsahu

- **Backend — DS default pole:**
  - Nové pole `app.theme` [type `theme`, scope ds implicitně] v
    `appSettings` page (`modules/core/system/module.jsonc`).
  - Ověřit `SettingsController::savePage` — theme validace musí
    pokrýt `app.theme` stejně jako `account.theme` (klíč = `field.id`,
    hodnota `{mode, custom}` bez follow). Pokud je větev klíčovaná
    `field.type === 'theme'`, není co měnit; jen ověřit + test.
  - DS default expozice klientovi: rozšířit `appInfo` endpoint/response
    o `theme` (DS default z `SettingsStore['app.theme']`), vedle
    brandingu.
- **Backend — `account.theme` follow:**
  - Žádná schéma změna (JSON sloupec). Validace v `savePage`: pro
    `account.theme` přijmout i tvar `{follow: true}` (bez mode/custom)
    a `{follow: false, mode, custom}`. Doplnit validační větev.
- **Frontend — efektivní vzhled:**
  - `theme.svelte.js`: stav `follow` (součást `account.theme`),
    getter efektivní konfigurace, `applyFromServer` rozšířit o follow
    (follow → aplikuj DS default; override → aplikuj user mode/custom).
  - DS default zdroj: `appInfo.theme`. `theme.svelte.js` ho čte
    (pozor na import cyklus — viz Konvence).
  - DS default anti-flash cache: nový localStorage klíč `shpd_ds_theme`
    (+ odvozené tokeny do existující `shpd_theme_tokens` logiky, nebo
    samostatný `shpd_ds_theme_tokens`). Píše se při aplikaci DS defaultu,
    čte v bootstrapu pro follow-uživatele.
- **Frontend — ThemeField follow přepínač:**
  - Checkbox „Vlastní vzhled" nad segmented controlem (vázaný na
    `themeStore` follow stav).
  - Vypnuto → skrýt segmented control + presety + „Upravit barvu";
    zobrazit poznámku `t('theme.followsApp')` („Řídí se nastavením
    aplikace") a vizuální náhled DS defaultu (mini swatch / sidebar
    preview, volitelné — minimálně textová poznámka).
  - Zapnuto → odemčít (dnešní UI). První zapnutí: předvyplnit override
    zděděnou DS hodnotou (`appInfo.theme ?? Shipard default`).
  - `showFollow` prop (default `true`). DS default widget v appSettings
    používá `showFollow={false}` — tentýž `ThemeField`, jen bez
    přepínače, ukládá do `app.theme` přes savePage (scope ds).
- **Frontend — odstranění dropdownu vzhledu ze sidebaru:**
  - `Sidebar.svelte`: smazat `themeOptions`, `handleThemeChange`,
    user-menu blok vzhledu (label + `{#each themeOptions}`), import
    nepoužitých ikon (`iconThemeLight`/`iconThemeDark`/`iconPalette` —
    ověřit, zda `iconPalette` nepoužívá ThemeField; pokud ano, nechat
    import jen tam). `onOpenThemePanel` prop **ponechat** (panel teď
    otevírá ThemeField). Položky „Nastavení účtu" / „Nastavení aplikace"
    v menu zůstávají.
- **Frontend — bootstrap:**
  - `index.html`: pro follow stav (žádný user override, nebo
    `account.theme.follow !== false`) aplikovat DS default cache
    (`shpd_ds_theme` + tokeny). Aktualizovat komentář „tři místa" →
    **čtyři** (theme store, bootstrap, api/config.js, appInfo DS default
    cache klíč).
- **Dokumentace:** `docs/design-system.md` (sekce 9 — DS default,
  efektivní vzhled, follow), `docs/app-settings.md` (app.theme pole,
  DS default v appInfo), `docs/frontend.md` (sekce 11 — follow,
  efektivní výpočet, čtvrté sync místo), `CLAUDE.md`.
- **Testy:** `SettingsController` (savePage `app.theme` scope ds;
  `account.theme` follow tvary), `appInfo` endpoint vrací theme,
  `ModuleDefinition` (pokud se dotkne — theme typ už ve whitelistu,
  patrně beze změny).

### Mimo rozsah (budoucí fáze)

- **Omezení DS-wide nastavení jen na správce** (role/oprávnění) — dnes
  smí nastavit kdokoli s přístupem do Nastavení aplikace.
- Per-DS default i pro **jazyk** (analogicky `app.language`) — jen
  vzhled; jazyk zůstává čistě per-user.
- Náhled DS defaultu jako plný živý sidebar preview — stačí mini swatch
  + textová poznámka, plný preview je nice-to-have.
- Migrace stávajících uživatelů: existující `account.theme` bez
  `follow` se interpretuje jako `follow: false` (override) — kdo už má
  uloženou volbu, drží si ji; viz Rozhodnutí.

## Datový tok

```
Nastavení aplikace → Aplikace  (appSettings, scope ds)
   app.theme [type theme, showFollow=false]
   → POST /_ui/settings/page/appSettings  → SettingsStore['app.theme'] = {mode, custom}

appInfo (boot, DS-wide):
   GET /_ui/app-info (nebo stávající endpoint) → { ...branding, theme: app.theme|null }
   → appInfo.theme  (+ localStorage cache shpd_ds_theme pro anti-flash)

Nastavení účtu → Základní  (accountBasic, scope user)
   account.theme [type theme, showFollow=true]
   follow přepínač → {follow:true} | {follow:false, mode, custom}
   → POST /_ui/settings/page/accountBasic → UserSettingsStore['account.theme']

Efektivní vzhled (theme.svelte.js):
   eff = (account.theme.follow !== false)
           ? (appInfo.theme ?? ShipardDefault)
           : { mode: account.theme.mode, custom: account.theme.custom }
   applyTheme(eff)

Bootstrap (index.html, anti-flash):
   override (shpd_theme + shpd_theme_custom + tokens)  → jako dnes
   follow   (shpd_ds_theme + ds tokens)                → aplikuj DS default cache
```

Klíče:

| Úložiště | Klíč | Obsah |
|---|---|---|
| `core_system_settings` (ds) | `app.theme` | `{mode, custom}` nebo chybí |
| `core_system_user_settings` (user) | `account.theme` | `{follow:true}` nebo `{follow:false, mode, custom}` |
| localStorage | `shpd_theme` / `shpd_theme_custom` / `shpd_theme_tokens` | user override cache (F1) |
| localStorage | `shpd_ds_theme` (+ tokeny) | DS default anti-flash cache (F4) |

---

## Implementace

### Krok 1 — Backend: DS default pole + appInfo expozice

**1a.** Pole `app.theme` do `appSettings` v
`modules/core/system/module.jsonc` (`settingsPages[].fields`, do
appSettings, za `app.companyLogo`):

```jsonc
{
    "id": "app.theme",
    "type": "theme",
    "name": "Default appearance",
    "name:cs": "Výchozí vzhled",
    "name:en": "Default appearance",
    "hint": "Default sidebar appearance for all users who haven't set their own.",
    "hint:cs": "Výchozí vzhled sidebaru pro všechny uživatele, kteří nemají vlastní.",
    "hint:en": "Default sidebar appearance for all users who haven't set their own."
}
```

`appSettings` nemá `scope` → `ds` (default). Pole se uloží přes
existující savePage do `SettingsStore['app.theme']`.

**1b.** `SettingsController::savePage` — ověřit theme validační větev.
Pokud je klíčovaná `field['type'] === 'theme'`, projde `app.theme`
i `account.theme` stejně. Pro `account.theme` doplnit akceptaci follow
tvaru (Krok 2). Pro `app.theme` (scope ds, bez follow) validuj
`{mode ∈ {light,dark,custom}, custom: object}`.

**1c.** Rozšíř `appInfo` o DS default theme. Najdi appInfo endpoint
(controller, který skládá branding — viz `docs/app-settings.md`
„appInfo"). Přidej do response `theme` =
`(new SettingsStore($db))->get('app.theme')` (může být null). Žádný
nový endpoint — jen rozšíření existující payloady.

**Test** (`tests/Unit/.../SettingsControllerTest.php` + příp. appInfo
test):
- savePage appSettings s `app.theme = {mode:'custom', custom:{...}}`
  → uloží do SettingsStore (scope ds), 200
- savePage appSettings s nevalidním theme → 422
- appInfo response obsahuje `theme` klíč (null když nenastaveno)

### Krok 2 — Backend: account.theme follow validace

`SettingsController::savePage`, theme větev pro `account.theme`
(scope user). Přijmout dva tvary:

```php
// pseudokód validace theme hodnoty pro account.theme:
// 1) {follow: true}                            → OK (sleduje DS)
// 2) {follow: false, mode, custom}             → validuj mode+custom
// 3) {mode, custom} bez follow (legacy)        → ber jako override (follow:false)
$follow = $value['follow'] ?? false; // legacy bez follow = override
if ($follow === true) {
    $clean = ['follow' => true];
} else {
    // validuj mode ∈ {light,dark,custom}, custom je objekt
    $clean = ['follow' => false, 'mode' => $mode, 'custom' => $custom];
}
$store->set('account.theme', $clean);
```

Pro `app.theme` follow nepřipouštěj (DS default follow nedává smysl) —
ignoruj/odstraň follow, ulož `{mode, custom}`.

**Test:** account.theme `{follow:true}` projde; `{follow:false, mode,
custom}` projde; legacy `{mode, custom}` → uloží s `follow:false`.

### Krok 3 — Frontend: efektivní vzhled v theme.svelte.js

**3a.** DS default zdroj. `theme.svelte.js` potřebuje `appInfo.theme`.
Pozor na import cyklus (`appInfo` ↔ `theme`). Řešení: `appInfo` po
loadu zavolá `themeStore.setDsDefault(theme)` (push směr appInfo →
theme, ne import theme → appInfo). Nebo `theme.svelte.js` drží vlastní
`dsDefault` stav nastavovaný zvenčí. Doporučení: metoda
`themeStore.setDsDefault(cfg)` + lokální `let dsDefault = $state(null)`.

**3b.** `account.theme` follow. Rozšíř stav:

```js
let follow = $state(true);  // default: sleduj DS
// override hodnoty (mode/customConfig) drží dnešní stav, použijí se jen když !follow
```

**3c.** Efektivní konfigurace + aplikace:

```js
function effectiveConfig() {
  if (follow) {
    return dsDefault ?? { mode: 'light', custom: DEFAULT_CUSTOM };
  }
  return { mode, custom: customConfig };
}
function applyEffective() {
  const eff = effectiveConfig();
  applyTheme(eff.mode, eff.custom);
  // DS default cache pro anti-flash (jen když follow)
  if (follow) writeDsThemeCache(eff);
}
```

**3d.** `applyFromServer(accountTheme)` rozšířit:

```js
function applyFromServer(value) {
  if (value && value.follow === true) {
    follow = true;
  } else if (value) {
    follow = false;
    mode = value.mode ?? 'light';
    customConfig = value.custom ?? DEFAULT_CUSTOM;
  }
  applyEffective();
  // cache user override jako dnes (shpd_theme*), nebo DS cache (shpd_ds_theme)
}
```

**3e.** `setDsDefault(cfg)` — uloží `dsDefault`, a pokud je `follow`,
re-aplikuje (DS default se mohl změnit) + zapíše DS cache.

**3f.** `setFollow(bool)`:

```js
function setFollow(next) {
  follow = next;
  if (next === false && /* override ještě prázdný */) {
    // předvyplň zděděnou DS hodnotou
    const seed = dsDefault ?? { mode: 'light', custom: DEFAULT_CUSTOM };
    mode = seed.mode; customConfig = seed.custom;
  }
  applyEffective();
  pushToServer(); // {follow} nebo {follow:false, mode, custom}
}
```

**3g.** `pushToServer` upravit, ať posílá follow tvar:

```js
function pushToServer() {
  const payload = follow ? { follow: true } : { follow: false, mode, custom: customConfig };
  pushAccountPrefs({ 'account.theme': payload });
}
```

`setMode`/`setCustom` při volání implikují `follow = false` (uživatel
aktivně volí) — nastav `follow = false` na začátku obou.

**Anti-flash DS cache** (`writeDsThemeCache`): odvoď tokeny z DS
default barvy (`deriveSidebarTokens`), zapiš `shpd_ds_theme` (mode +
base) a tokeny. Klíč přes `storageKey()`.

### Krok 4 — Frontend: bootstrap pro follow

`frontend/index.html` — rozšíř bootstrap. Logika: nejdřív zkus user
override (`shpd_theme` ≠ default / `shpd_theme_custom` existuje
s follow:false ekvivalentem); jinak zkus DS default cache
(`shpd_ds_theme`). Protože bootstrap nezná follow flag (ten je
v `account.theme` na serveru), použij heuristiku:

- Pokud existuje `shpd_theme_custom` cache a uživatel naposledy
  aplikoval override → `shpd_theme` drží jeho mode (jako dnes).
- Pokud follow → store při aplikaci píše `shpd_ds_theme` a NEpíše
  `shpd_theme_custom` user tokeny. Bootstrap: když `shpd_theme` chybí
  nebo je `light` a existuje `shpd_ds_theme`, aplikuj DS cache.

Doporučení pro jednoznačnost: ať store při follow píše `shpd_theme` =
`'follow'` (nový sentinel) a DS tokeny do `shpd_ds_theme_tokens`.
Bootstrap pak:

```js
var mode = localStorage.getItem('shpd_theme' + sfx) || 'light';
if (mode === 'follow') {
  var ds = JSON.parse(localStorage.getItem('shpd_ds_theme' + sfx) || '{}');
  dark = ds.base === 'dark';
  var t = JSON.parse(localStorage.getItem('shpd_ds_theme_tokens' + sfx) || '{}');
  for (var k in t) document.documentElement.style.setProperty(k, t[k]);
} else if (mode === 'dark') { ... } else if (mode === 'custom') { ... }
```

(Sentinel `'follow'` v `shpd_theme` je čisté — bootstrap nepotřebuje
znát server. Aktualizovat `VALID_MODES`? Ne — `follow` není mód, je to
cache marker; `loadInitialMode` ho mapuj na efektivní. Promysli při
implementaci, ať `loadInitialMode` a `applyFromServer` nejsou
v rozporu. Bezpečná varianta: `shpd_theme` drží efektivní mode pro
bootstrap, follow stav je čistě serverový + `shpd_ds_theme` cache se
píše vždy když follow.)

Aktualizovat komentář „tři synchronizovaná místa" → **čtyři**
(přibyl DS default cache klíč) ve všech třech souborech
(`theme.svelte.js`, `index.html`, a zmínka v `api/config.js` komentáři).

### Krok 5 — Frontend: ThemeField follow přepínač + showFollow

`frontend/src/components/settings/ThemeField.svelte`:

**5a.** Prop `showFollow` (default `true`):

```js
let { onOpenThemePanel, showFollow = true } = $props();
```

**5b.** Když `showFollow`: nahoře checkbox/toggle „Vlastní vzhled"
vázaný na `themeStore` follow:

```svelte
{#if showFollow}
  <label class="shpd-theme-field__follow">
    <input type="checkbox" checked={!themeStore.follow}
           onchange={(e) => themeStore.setFollow(!e.target.checked)} />
    {t('theme.customAppearance')}
  </label>
{/if}
```

(Checkbox „Vlastní vzhled" zaškrtnuté = `!follow`. Pojmenuj jasně, ať
logika nezmate — zaškrtnuto znamená override.)

**5c.** Tělo (segmented control + presety + „Upravit barvu") obal
podmínkou:

```svelte
{#if !showFollow || !themeStore.follow}
  <!-- dnešní UI: segmented control, presety, Upravit barvu -->
{:else}
  <p class="shpd-theme-field__note">{t('theme.followsApp')}</p>
  <!-- volitelně mini náhled DS defaultu: swatch s appInfo.theme barvou -->
{/if}
```

**5d.** `showFollow={false}` varianta (DS default v appSettings) —
`ThemeField` bez přepínače, vždy zobrazí výběr; ukládá ale do
`app.theme`, ne `account.theme`. **Pozor:** dnešní `ThemeField` je
vázaný napevno na `themeStore` (user override). DS default widget
musí psát jinam (savePage appSettings, scope ds). Dvě cesty:

- (i) `ThemeField` zobecnit na prop `target: 'user' | 'ds'` — pro `ds`
  čte/píše DS default (přes savePage appSettings + lokální stav, ne
  themeStore). Více práce, ale jeden komponent.
- (ii) Tenká samostatná komponenta `DsThemeField.svelte` pro appSettings
  — sdílí presety/picker UI (vyextrahovat do `ThemeSwatches.svelte`),
  ale ukládá přes savePage. Čistší oddělení.

Doporučení: **(ii)** — vyextrahovat sdílené UI (presety grid + color
picker + base toggle) do `ThemeSwatches.svelte`, použít v `ThemeField`
(user, follow) i `DsThemeField` (ds, savePage). `SettingsPage` render
větev `theme`: scope `user` → `ThemeField`, scope `ds` → `DsThemeField`.
(Scope page je dostupný v definici — předat do SettingsPage.)

### Krok 6 — Frontend: odstranění dropdownu vzhledu ze sidebaru

`frontend/src/components/layout/Sidebar.svelte`:

- Smazat `themeOptions` (~ř. 170), `handleThemeChange` (~ř. 176).
- Smazat user-menu blok vzhledu: label `t('sidebar.appearance')` +
  `{#each themeOptions}` (~ř. 435–445).
- Ponechat `onOpenThemePanel` prop (panel teď otevírá ThemeField).
- Ponechat položky menu „Nastavení účtu" (`handleSettings` →
  `enterAccount`) a „Nastavení aplikace" (`handleAppSettings`).
- Ikony: ověřit, zda `iconThemeLight`/`iconThemeDark` používá ještě něco
  jiného; pokud ne, odstranit importy. `iconPalette` patrně používá
  ThemeField — nechat import tam, ze Sidebaru odstranit, pokud nezbyl.
- `t('sidebar.appearance.*')` klíče: zůstávají pro ThemeField segmented
  control labely (Shipard/Tmavý/Vlastní). Neodstraňovat.

**Pozor:** `Sidebar.svelte` 860+ řádků, diakritika — číst celé okolí
před edicí, pro edity s diakritikou Python heredoc workaround;
`write_file` pro větší zásah do bloku. Po edici `grep` ověřit, že
`themeOptions`/`handleThemeChange` zmizely a build prochází.

### Krok 7 — i18n

Přidat (cs/en):

```js
// cs.js
'theme.customAppearance': 'Vlastní vzhled',
'theme.followsApp': 'Řídí se nastavením aplikace.',
// en.js
'theme.customAppearance': 'Custom appearance',
'theme.followsApp': 'Follows the application settings.',
```

Ověřit, že odstraněním dropdownu nezůstaly osiřelé klíče bez užití
(check:i18n hlásí nepoužité? pokud ano, `sidebar.appearance` label
klíč prověřit). `npm run check:i18n`.

---

## Akceptační kritéria (Hotovo když)

- [ ] `vendor/bin/phpunit` zelené — vč. nových testů savePage
      (`app.theme` scope ds, `account.theme` follow tvary) a appInfo
      theme
- [ ] `cd frontend && npm run build 2>&1` — bez chyb a warningů
- [ ] `cd frontend && npm run check:i18n` — OK
- [ ] Nastavení aplikace → Aplikace má pole „Výchozí vzhled"; nastavení
      barvy se uloží do `core_system_settings['app.theme']`
- [ ] Nový uživatel (žádný `account.theme`) vidí DS default; když není,
      vidí Shipard
- [ ] Nastavení účtu → Základní: přepínač „Vlastní vzhled" vypnutý →
      výběr skrytý + poznámka „Řídí se nastavením aplikace", aplikuje se
      DS default
- [ ] Zapnutí přepínače → odemkne výběr, předvyplněný zděděnou DS
      hodnotou; změna se uloží jako `{follow:false, ...}`
- [ ] Vypnutí přepínače zpět → vrátí se ke sledování DS defaultu
      (`{follow:true}`), DS default se aplikuje
- [ ] Změna DS defaultu správcem se projeví u všech follow-uživatelů
      (po jejich příštím loadu); override-uživatelů se nedotkne
- [ ] Dropdown vzhledu v patce sidebaru je pryč; přepínání jede jen
      z Nastavení účtu → Základní; panel se otevírá z ThemeField
- [ ] Anti-flash: follow-uživatel po reloadu nevidí flash Shipard modré
      (DS default cache `shpd_ds_theme` aplikována v bootstrapu)
- [ ] Per-user izolace + per-DS izolace (dev) drží
- [ ] Built-in light/dark beze změny; override-uživatel z F3 funguje
      dál (legacy `account.theme` bez follow = override)
- [ ] `docs/design-system.md`, `docs/app-settings.md`, `docs/frontend.md`,
      `CLAUDE.md` aktualizované
- [ ] `tasks/README.md` — task přesunout z Aktivní do hotových
      (navazující session)

---

## Rozhodnutí k designu (potvrzená s Annou)

- ✓ **Přepínač follow/override** („Vlastní vzhled"). Vypnuto = sleduji
  DS default včetně jeho budoucích změn; zapnuto = vlastní volba
  s možností návratu. Model: `account.theme.follow`.
- ✓ **Dropdown vzhledu z patky sidebaru úplně mizí.** Light/dark/custom
  vše jen v Nastavení účtu → Základní. Vzhled = nastavení, ne rychlý
  přepínač. Panel zůstává, otevírá ho ThemeField.
- ✓ **DS default v Nastavení aplikace → Aplikace** (`appSettings`,
  scope ds, klíč `app.theme`). Tentýž field typ `theme` jako user;
  bez follow přepínače (`showFollow={false}` / `DsThemeField`).
- ✓ **DS default na klienta přes `appInfo`** (vedle brandingu), ne
  zvlášť fetch v accountPrefs. DS-wide hodnota logicky patří k brandingu.
- ✓ **Anti-flash přes accountPrefs/appInfo po bootu** + DS default
  localStorage cache (`shpd_ds_theme`). Krátký flash při úplně prvním
  loadu na čistém prohlížeči akceptován (konzistentní s F3).
- ✓ **První zapnutí override předvyplní zděděnou DS hodnotou** („začni
  od toho, co vidíš").
- ✓ **Skrýt výběr při vypnutém přepínači** (ne disablovat) + poznámka,
  co se používá.
- ✓ **Legacy `account.theme` bez follow = override** (`follow:false`).
  Kdo má z F3 uloženou volbu, drží si ji; nepřepne se na follow.
- ✓ **Omezení na správce mimo rozsah** — nastavit DS default smí
  kdokoli s přístupem do Nastavení aplikace (zatím).

## Doporučené pořadí

1. Krok 1 (DS default pole + appInfo theme) + Krok 2 (follow validace)
   + testy. `ds-upgrade` netřeba (žádná nová tabulka), jen rekompilace
   configu pokud appSettings cachuje. Curl ověření savePage appSettings
   a appInfo theme.
2. Krok 3 (theme.svelte.js: dsDefault, follow, effectiveConfig,
   applyFromServer, setFollow, pushToServer) — build, manuální test
   follow/override přepínání (UI zatím přes konzoli / dočasně).
3. Krok 4 (bootstrap follow + DS cache) — smoke test anti-flash follow.
4. Krok 5 (ThemeField follow + ThemeSwatches extrakce + DsThemeField)
   — build, vizuální test obou míst (Nastavení účtu + Nastavení aplikace).
5. Krok 6 (odstranění dropdownu ze Sidebaru) — build, ověřit panel se
   otevírá z ThemeField, nic neosiřelo.
6. Krok 7 (i18n), dokumentace.

Commity granulárně po krocích, konvence `feat(theme): ...` /
`feat(account): ...` / `feat(settings): ...` s `Co-Authored-By: Claude`
footerem. Push dělá Anna.

## Konvence a upozornění

- **Svelte 5 runes**, props přes `$props()`, callback props.
- **Žádný kruhový import** theme ↔ appInfo — DS default se do theme
  storu tlačí zvenčí (`themeStore.setDsDefault`), theme neimportuje
  appInfo.
- **PHP 8.3 strict_types**, readonly properties; `composer dump-autoload`
  po nových src souborech (pokud vznikne `DsThemeField` — to je
  frontend, takže netřeba).
- **Před úpravou `Sidebar.svelte` přečíst celé okolí** — 860+ řádků,
  diakritika; `patch_file` citlivý → Python heredoc workaround pro
  diakritiku, `write_file` pro větší blok. `grep` po edici.
- **Čtyři synchronizovaná místa** localStorage klíčů/DS regexu po této
  fázi: `theme.svelte.js`, `index.html` bootstrap, `api/config.js`
  (regex), a nový DS default cache klíč. Aktualizovat komentáře u všech.
- **i18n parita** cs/en + `npm run check:i18n`.
- **Pre-existing test noise:** `Opis\JsonSchema\Validator not found`
  v Exchange/Mail testech je baseline (1 error ve filtrovaných bězích),
  nesouvisí.

## Otevřené otázky k ověření při implementaci

- **appInfo endpoint** — najít přesný controller/akci, která skládá
  branding, a kam přidat `theme`. Ověřit, zda se appInfo cachuje
  (rekompilace po změně `app.theme`).
- **Bootstrap follow sentinel** — rozhodnout mezi `shpd_theme='follow'`
  sentinelem a heuristikou „chybí override → zkus DS cache". Sentinel
  je jednoznačnější; ověřit, že `loadInitialMode` / `VALID_MODES` s ním
  nekolidují (follow není mód, je marker).
- **ThemeField vs ThemeSwatches extrakce** — potvrdit při implementaci,
  že base toggle (světlý/tmavý) i color picker jdou čistě vyextrahovat
  bez vazby na themeStore (DsThemeField je nesmí volat).
- **Osiřelé i18n klíče** po odstranění dropdownu — `sidebar.appearance`
  (label sekce) možná zůstane nepoužitý; check:i18n prověří.
