# Task: Vlastní vzhledy — Fáze 1 (custom theme sidebaru)

**Stav:** hotovo

## Motivace

Aplikace dnes podporuje light / dark / auto režim. Pro tento typ
business aplikace je primární práce v light módu; auto detekce podle OS
přidává spíš zmatek (uživatel s OS dark preferencí dostane dark, i když
v aplikaci chce pracovat ve světlém).

Hlavní motivace pro vlastní vzhledy: uživatel s **více zdroji dat**
potřebuje na první pohled poznat, ve které databázi právě pracuje.
Barevné odlišení sidebaru (vzor: workspace témata v Zen/Arc browseru)
to řeší elegantně — plus umožňuje sladit vzhled s brandingem firmy.

Tělo stránky se **nebarví** — na bílých plochách žije doc-state systém
(barevné pruhy, badge, selection) a jakékoli tónování obsahu by rozbilo
jeho čitelnost. Barví se jen sidebar.

## Cíl fáze

Po dokončení platí:

- Dropdown vzhledu v patce sidebaru nabízí **Shipard / Tmavý / Vlastní**
  (interní hodnoty `light` / `dark` / `custom`). Volba `auto` zmizela;
  uložená hodnota `'auto'` se migruje na `'light'`.
- Volba **Vlastní** otevře panel vedle sidebaru s preset paletou
  (12 barev), nativním color pickerem a přepínačem světlé/tmavé báze
  těla. Každá změna se aplikuje okamžitě (live preview).
- Vlastní barva sidebaru funguje přes **runtime přepsání sidebar tokenů**
  inline custom properties na `<html>` — žádné zásahy do komponent mimo
  body popsané níže.
- Odvozování barev (elevated plocha, barva textu podle luminance) běží
  v JS přes OKLCH; světlé barvy sidebaru dostanou tmavý text automaticky.
- Aktivní položka sidebaru používá nový token
  `--shpd-color-sidebar-active-bg` — v built-in tématech ukazuje na
  primary modrou (beze změny vzhledu), v custom tématu na neutrální
  white/black-alpha. Obsah stránky (tlačítka, odkazy, doc-states,
  selection) zůstává **brand beze změny**.
- Volba se persistuje do localStorage s per-DS klíčem v dev módu
  (v produkci izoluje origin/subdoména). Anti-flash bootstrap aplikuje
  custom tokeny z cache před prvním renderem.

---

## Před implementací přečti

- `docs/design-system.md` — sekce 3 (Sidebar tokeny), 8 (Layout
  konvence — sidebar), 9 (Dark mode)
- `docs/frontend.md` — sekce 11 (Theme management), sekce 9 (Konvence —
  pod-sekce *Dropdown / popover komponenty*, past se zavíráním menu!)
- `frontend/src/stores/theme.svelte.js` — celý (bude přepsán)
- `frontend/index.html` — theme bootstrap inline script
- `frontend/src/styles/variables.css` — sidebar tokeny v `:root`
  i `[data-theme="dark"]`
- `frontend/src/components/layout/Sidebar.svelte` — `themeOptions`
  (řádek ~163), `handleThemeChange`, dropdown markup (~řádek 421),
  CSS `.shpd-sidebar__item--active` (~řádek 828)
- `frontend/src/api/config.js` — `DATA_SOURCE_ID` export
- `frontend/src/stores/layout.svelte.js` — `layoutStore.isMobile`
- `frontend/src/components/ui/Modal.svelte` — pro mobilní variantu panelu

---

## Scope

### V rozsahu

- Rework `theme.svelte.js` (módy `light`/`dark`/`custom`, migrace `auto`)
- Rework bootstrap v `index.html` (per-DS klíč, token cache)
- Nové tokeny `--shpd-color-sidebar-active-bg` / `-active-bg-hover`
  ve `variables.css` + použití v `Sidebar.svelte`
- Utilita `frontend/src/utils/themeColor.js` (OKLCH odvozování)
- Preset paleta `frontend/src/components/layout/themePresets.js`
- Komponenta `frontend/src/components/layout/ThemePanel.svelte`
- Úprava dropdownu v `Sidebar.svelte` (položka Vlastní, otevírání panelu)
- i18n klíče, dokumentace

### Mimo rozsah (budoucí fáze)

- **Gradienty** (token `--shpd-sidebar-bg-image` + úprava pozadí
  Sidebar.svelte) — Fáze 2
- **Opacity slider** (color-mix vybrané barvy s bází) — Fáze 2
- **Vlastní color wheel** místo nativního `<input type="color">` — Fáze 2
- **Server-side persistence per uživatel** (preferences na
  `core_system_users`, localStorage jen jako anti-flash cache) — Fáze 2
- **DS-wide default od správce** (přes `SettingsStore`, efektivní téma
  = user pref ?? DS default ?? Shipard) — Fáze 3
- Barvení těla stránky — záměrně nikdy

---

## Specifikace

### Režimy a dropdown

| Interní hodnota | Label cs | Label en | Chování |
|---|---|---|---|
| `light` | Shipard | Shipard | Default. Žádný `data-theme` atribut, žádné inline tokeny. |
| `dark` | Tmavý | Dark | `data-theme="dark"`, žádné inline tokeny. |
| `custom` | Vlastní | Custom | `data-theme` podle `custom.base`, inline sidebar tokeny na `<html>`. |

Hodnota `auto` zaniká. Migrace: pokud `loadInitialMode()` přečte
`'auto'`, vrátí `'light'` a rovnou persistuje (write-back). Bootstrap
v `index.html` zachází s `'auto'` (a jakoukoli neznámou hodnotou) jako
s `'light'`.

V dropdownu nahradit položku Auto položkou Vlastní:

```js
const themeOptions = [
  { value: 'light',  labelKey: 'sidebar.appearance.light',  icon: iconThemeLight },
  { value: 'dark',   labelKey: 'sidebar.appearance.dark',   icon: iconThemeDark },
  { value: 'custom', labelKey: 'sidebar.appearance.custom', icon: iconPalette },
];
```

Klik na **Vlastní**: `themeStore.setMode('custom')` (aplikuje uložený
nebo default custom config) **a** otevře panel. Pozor na dokumentovanou
past se zavíráním dropdownu (frontend.md sekce 9): menu zavřít a panel
otevřít až po ticku:

```js
function handleThemeChange(value) {
  themeStore.setMode(value);
  if (value === 'custom') {
    closeUserMenu();
    setTimeout(() => { themePanelOpen = true; }, 0);
  }
}
```

Klik na Vlastní, když už je `custom` aktivní, panel znovu otevře
(uživatel chce upravit barvu). Light/Dark se chovají jako dnes (menu se
nezavírá, ať jde rychle zkoušet).

Nová ikona `iconPalette` (`faPalette`) — import + export v `icons.js`.

### Formát theme konfigurace + persistence

localStorage klíče. V dev módu (DS ID v URL) se klíč prefixuje, aby se
volby pro různé DS na stejném originu nemíchaly; v produkci (subdoména
per DS) je izolace přes origin automatická:

```js
import { DATA_SOURCE_ID } from '../api/config.js';
const storageKey = (name) => DATA_SOURCE_ID ? `${name}:${DATA_SOURCE_ID}` : name;
```

| Klíč (base) | Obsah |
|---|---|
| `shpd_theme` | Mode string: `'light'` / `'dark'` / `'custom'` |
| `shpd_theme_custom` | JSON custom konfigurace (viz níže) |
| `shpd_theme_tokens` | JSON cache vypočítaných tokenů pro anti-flash bootstrap: `{"--shpd-color-bg-sidebar": "#6D1F2C", ...}` |

Custom konfigurace — formát navržený tak, aby ho beze změny převzaly
budoucí úrovně (server per-user, DS default) i Fáze 2 (gradient, opacity):

```json
{
  "version": 1,
  "base": "light",
  "sidebar": { "type": "solid", "color": "#6D1F2C" }
}
```

Default (první otevření panelu): `base: 'light'`,
`sidebar: {type: 'solid', color: '#00345C'}` (= Shipard modrá, takže
panel startuje vizuálně z defaultu).

`shpd_theme_tokens` zapisuje store při každé aplikaci custom tématu
a maže při přepnutí na built-in. Bootstrap ho jen čte a aplikuje —
**žádná OKLCH matematika v inline scriptu**.

### Odvozování tokenů — `frontend/src/utils/themeColor.js`

Nový soubor, žádné závislosti. Exporty:

```js
/** #RRGGBB → {l, c, h} v OKLCH (l 0–1). sRGB → linear → OKLab → OKLCH. */
export function hexToOklch(hex) { ... }

/** Z vybrané barvy odvodí kompletní mapu sidebar tokenů. */
export function deriveSidebarTokens(hex) { ... }
```

`deriveSidebarTokens(hex)` vrací objekt `{tokenName: cssValue}`:

| Token | Hodnota |
|---|---|
| `--shpd-color-bg-sidebar` | `hex` beze změny |
| `--shpd-color-bg-sidebar-elevated` | `oklch(min(L+0.06, 0.98) C H)` — CSS oklch() string (target prohlížeče poslední 2 roky ho umí) |
| ostatní | podle větve světlý/tmavý sidebar níže |

Větvení podle luminance: `isLightSidebar = L >= 0.65`.

**Tmavý sidebar (světlý text)** — stejná logika jako dnešní default:

```
--shpd-color-text-sidebar:        rgb(255 255 255 / 0.92)
--shpd-color-text-sidebar-muted:  rgb(255 255 255 / 0.58)
--shpd-color-bg-sidebar-hover:    rgb(255 255 255 / 0.08)
--shpd-color-bg-sidebar-border:   rgb(255 255 255 / 0.10)
--shpd-color-sidebar-active-bg:        rgb(255 255 255 / 0.16)
--shpd-color-sidebar-active-bg-hover:  rgb(255 255 255 / 0.22)
```

**Světlý sidebar (tmavý text)**:

```
--shpd-color-text-sidebar:        rgb(15 23 42 / 0.88)
--shpd-color-text-sidebar-muted:  rgb(15 23 42 / 0.56)
--shpd-color-bg-sidebar-hover:    rgb(0 0 0 / 0.06)
--shpd-color-bg-sidebar-border:   rgb(0 0 0 / 0.10)
--shpd-color-sidebar-active-bg:        rgb(0 0 0 / 0.10)
--shpd-color-sidebar-active-bg-hover:  rgb(0 0 0 / 0.15)
```

OKLCH převod — postačí standardní řetězec sRGB→linear (gamma expand)
→ LMS přes OKLab matice → OKLab → LCH. Stačí přesnost na 3 desetinná
místa; unit test ověří známé hodnoty (bílá L≈1, černá L≈0,
#00345C L≈0.30±0.03).

### Nové tokeny — `variables.css`

Do `:root` (sekce Sidebar) přidat:

```css
/* Pozadí aktivní položky sidebaru. V built-in tématech ukazuje na
   primary; custom téma ho přepisuje na neutrální white/black-alpha,
   aby fungovalo na libovolné barvě sidebaru. */
--shpd-color-sidebar-active-bg:       var(--shpd-color-primary);
--shpd-color-sidebar-active-bg-hover: var(--shpd-color-primary-hover);
```

Do `[data-theme="dark"]` bloku **nepřidávat nic** — nepřímá reference
přes `var(--shpd-color-primary)` se v dark vyhodnotí na dark primary
sama.

V `Sidebar.svelte` přepnout CSS aktivní položky (~řádek 828):

```css
.shpd-sidebar__item--active {
  background-color: var(--shpd-color-sidebar-active-bg);
  ...
}
.shpd-sidebar__item--active:hover {
  background-color: var(--shpd-color-sidebar-active-bg-hover);
}
```

Oranžový proužek (`::before` s `--shpd-color-accent`) zůstává beze
změny ve všech tématech.

### Preset paleta — `frontend/src/components/layout/themePresets.js`

```js
// Kurátorovaná paleta pro custom téma sidebaru. Tmavé tóny drží
// lightness pásmo brand modré (~L 0.30 v OKLCH), aby bílý text
// a white-alpha zvýraznění měly všude srovnatelný kontrast.
// Dvě světlé barvy na konci ověřují odvozování tmavého textu.
export const THEME_PRESETS = [
  { id: 'shipard-blue', color: '#00345C', nameKey: 'theme.preset.shipardBlue' },
  { id: 'petrol',       color: '#0E4F5C', nameKey: 'theme.preset.petrol' },
  { id: 'emerald',      color: '#115E4B', nameKey: 'theme.preset.emerald' },
  { id: 'bottle-green', color: '#2F5D3A', nameKey: 'theme.preset.bottleGreen' },
  { id: 'terracotta',   color: '#9A3B26', nameKey: 'theme.preset.terracotta' },
  { id: 'wine',         color: '#6D1F2C', nameKey: 'theme.preset.wine' },
  { id: 'magenta',      color: '#8C2F5D', nameKey: 'theme.preset.magenta' },
  { id: 'plum',         color: '#4A2C66', nameKey: 'theme.preset.plum' },
  { id: 'indigo',       color: '#34307D', nameKey: 'theme.preset.indigo' },
  { id: 'graphite',     color: '#2F343D', nameKey: 'theme.preset.graphite' },
  { id: 'sand',         color: '#E3D5B8', nameKey: 'theme.preset.sand' },
  { id: 'mist',         color: '#DBE4EE', nameKey: 'theme.preset.mist' },
];
```

### Rework `theme.svelte.js`

```js
// Stav
let mode = $state(initialMode);            // 'light' | 'dark' | 'custom'
let customConfig = $state(initialCustom);  // viz formát výše

// API
export const themeStore = {
  get mode() { return mode; },
  get custom() { return customConfig; },
  setMode,          // ('light'|'dark'|'custom') — persistuje + aplikuje
  setCustom,        // (config) — merge, persistuje, aplikuje; implikuje mode 'custom'
};
```

`applyTheme()` (privátní, volá se ze `setMode` i `setCustom`):

- `light` → odebrat `data-theme`, vyčistit inline tokeny, smazat
  `shpd_theme_tokens` cache
- `dark` → nastavit `data-theme="dark"`, vyčistit inline tokeny,
  smazat cache
- `custom` → `data-theme` podle `customConfig.base`
  (`'dark'` → atribut, `'light'` → odebrat);
  `tokens = deriveSidebarTokens(customConfig.sidebar.color)`;
  pro každý token `document.documentElement.style.setProperty(...)`;
  zapsat tokens JSON do `shpd_theme_tokens` cache

Čištění inline tokenů: konstantní seznam názvů tokenů (stejný, jaký
vrací `deriveSidebarTokens`), `removeProperty` v cyklu. Seznam drž
v `themeColor.js` jako export `SIDEBAR_TOKEN_NAMES`, ať se store
a derivace nerozjedou.

`prefers-color-scheme` listener z dnešního storu **smazat** (auto
režim zaniká). `loadInitialMode()` migruje `'auto'` → `'light'`
s write-backem. Načítání `customConfig`: parse `shpd_theme_custom`,
při chybě / chybějícím klíči default config.

Všechny localStorage operace přes `storageKey()` helper (per-DS prefix).

### Rework bootstrap — `frontend/index.html`

Nahradit theme bootstrap script:

```js
(function () {
  try {
    // Per-DS klíč — stejná detekce jako api/config.js (sync ručně!)
    var m = location.pathname.match(/^\/([a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})\//);
    var sfx = m ? ':' + m[1] : '';
    var mode = localStorage.getItem('shpd_theme' + sfx) || 'light';
    var dark = false;
    if (mode === 'dark') {
      dark = true;
    } else if (mode === 'custom') {
      var cfg = JSON.parse(localStorage.getItem('shpd_theme_custom' + sfx) || '{}');
      dark = cfg.base === 'dark';
      var tokens = JSON.parse(localStorage.getItem('shpd_theme_tokens' + sfx) || '{}');
      for (var k in tokens) document.documentElement.style.setProperty(k, tokens[k]);
    }
    // 'auto' (legacy) i neznámé hodnoty propadnou na light
    if (dark) document.documentElement.setAttribute('data-theme', 'dark');
  } catch (e) { /* fallback light */ }
})();
```

Komentář u scriptu aktualizovat: synchronizace s `theme.svelte.js`
(klíče, formáty) **a** s `api/config.js` (DS regex) — tři místa.

### Panel — `frontend/src/components/layout/ThemePanel.svelte`

Props: `open`, `onClose`, `collapsed` (stav sidebaru, kvůli pozici).

**Desktop** (`!layoutStore.isMobile`): fixed panel vedle sidebaru:

```css
.shpd-theme-panel {
  position: fixed;
  top: 64px;
  left: calc(var(--shpd-sidebar-width) + var(--shpd-space-sm));
  width: 300px;
  background: var(--shpd-color-bg);
  border: 1px solid var(--shpd-color-border);
  border-radius: var(--shpd-radius-lg);
  box-shadow: var(--shpd-shadow-lg);
  z-index: 50; /* sladit s existujícími overlay vrstvami v projektu */
}
```

Při `collapsed` sidebaru `left: calc(var(--shpd-sidebar-width-collapsed) + var(--shpd-space-sm))`
(přes `class:` modifikátor). Zavírání: ✕ tlačítko v hlavičce, Esc,
klik mimo panel (document listener v `$effect` — stejný vzor jako user
menu, pozor na bubbling past z frontend.md).

**Mobil** (`layoutStore.isMobile`): strukturní přepnutí — obsah panelu
se renderuje uvnitř `<Modal>` (fullscreen na mobilu automaticky).
Stejný vzor jako jiné isMobile přepínače (FormInline, FormStateBar).

Obsah panelu (shora dolů):

1. **Hlavička**: titulek `t('theme.panel.title')` + ✕
2. **Báze těla**: label `t('theme.panel.base')` + dvě toggle tlačítka
   `t('theme.base.light')` / `t('theme.base.dark')` (aktivní zvýrazněné,
   vzor segmented control; stačí dvě `<button>` s `--active` třídou)
3. **Presety**: grid 4 sloupce, kruhové swatche 36px
   (`background: preset.color`), `title={t(preset.nameKey)}`,
   `aria-label` dtto. Vybraný preset (shoda s `customConfig.sidebar.color`
   case-insensitive) má ring: `box-shadow: 0 0 0 2px var(--shpd-color-bg), 0 0 0 4px var(--shpd-color-accent)`
4. **Vlastní barva**: label `t('theme.panel.customColor')` + nativní
   `<input type="color" value={customConfig.sidebar.color}>` —
   `oninput` (ne jen `onchange`), aby preview běželo už při tažení
   v pickeru

Každá interakce volá `themeStore.setCustom({...})` — tokeny se aplikují
okamžitě, persistence je vedlejší efekt. Žádné tlačítko Uložit/Použít.

### i18n klíče

Přidat do `cs.js` i `en.js` (a smazat `sidebar.appearance.auto` z obou):

```js
// cs.js
'sidebar.appearance.custom': 'Vlastní',
'theme.panel.title': 'Vlastní vzhled',
'theme.panel.base': 'Základ aplikace',
'theme.base.light': 'Světlý',
'theme.base.dark': 'Tmavý',
'theme.panel.customColor': 'Vlastní barva',
'theme.preset.shipardBlue': 'Shipard modrá',
'theme.preset.petrol': 'Petrolejová',
'theme.preset.emerald': 'Smaragdová',
'theme.preset.bottleGreen': 'Lahvová zelená',
'theme.preset.terracotta': 'Terakota',
'theme.preset.wine': 'Vínová',
'theme.preset.magenta': 'Tlumená magenta',
'theme.preset.plum': 'Švestková',
'theme.preset.indigo': 'Indigo',
'theme.preset.graphite': 'Grafit',
'theme.preset.sand': 'Písková',
'theme.preset.mist': 'Mlhavá modř',

// en.js
'sidebar.appearance.custom': 'Custom',
'theme.panel.title': 'Custom appearance',
'theme.panel.base': 'App base',
'theme.base.light': 'Light',
'theme.base.dark': 'Dark',
'theme.panel.customColor': 'Custom color',
'theme.preset.shipardBlue': 'Shipard blue',
'theme.preset.petrol': 'Petrol',
'theme.preset.emerald': 'Emerald',
'theme.preset.bottleGreen': 'Bottle green',
'theme.preset.terracotta': 'Terracotta',
'theme.preset.wine': 'Wine',
'theme.preset.magenta': 'Muted magenta',
'theme.preset.plum': 'Plum',
'theme.preset.indigo': 'Indigo',
'theme.preset.graphite': 'Graphite',
'theme.preset.sand': 'Sand',
'theme.preset.mist': 'Misty blue',
```

Ověřit `npm run check:i18n`.

---

## Testy

### Unit — `themeColor.js`

Pokud má frontend Vitest setup, přidat `themeColor.test.js`; pokud ne,
postačí manuální ověření v konzoli + smoke testy níže (poznamenat do
commitu, že unit testy čekají na test setup).

- `hexToOklch('#ffffff').l` ≈ 1.0 (±0.01)
- `hexToOklch('#000000').l` ≈ 0.0 (±0.01)
- `hexToOklch('#00345C').l` ≈ 0.30 (±0.03)
- `deriveSidebarTokens('#6D1F2C')` → světlý text
  (`--shpd-color-text-sidebar` obsahuje `255 255 255`)
- `deriveSidebarTokens('#E3D5B8')` → tmavý text
  (obsahuje `15 23 42`)
- `deriveSidebarTokens` vrací přesně klíče ze `SIDEBAR_TOKEN_NAMES`

### Manuální smoke testy

1. **Default + migrace**: smaž localStorage, reload → Shipard téma
   (modrý sidebar, světlé tělo). Nastav ručně `shpd_theme*` klíč na
   `'auto'`, reload → light, klíč přepsán na `'light'`.
2. **Dark beze změny**: přepni na Tmavý → identické chování jako před
   taskem (dark tokeny, aktivní položka primary modrá, oranžový proužek).
3. **Vlastní — preset**: dropdown → Vlastní → panel se otevře vedle
   sidebaru. Klik na Vínovou → sidebar okamžitě vínový, text bílý,
   aktivní položka white-alpha + oranžový proužek, dropdowny nad
   sidebarem (user menu) ve světlejší vínové (elevated). Tělo stránky
   beze změny (modrá tlačítka, doc-state pruhy).
4. **Vlastní — světlý preset**: klik na Pískovou → text v sidebaru
   tmavý, hover/active black-alpha, čitelné.
5. **Vlastní — color picker**: tažení v nativním pickeru → live preview
   při `oninput`.
6. **Dark báze**: v panelu přepni Základ aplikace na Tmavý → tělo dark,
   sidebar drží vlastní barvu.
7. **Persistence + anti-flash**: reload s custom tématem → žádný flash
   modrého sidebaru (bootstrap aplikuje tokeny z cache před renderem).
8. **Per-DS izolace (dev)**: nastav custom téma v DS A, otevři DS B →
   B má default; volby se nemíchají.
9. **Přepnutí zpět**: Vlastní → Shipard → inline tokeny pryč
   (zkontrolovat `document.documentElement.style` prázdný), cache klíč
   smazán.
10. **Mobil**: ≤768px → Vlastní otevře fullscreen Modal s týmž obsahem,
    výběr funguje.
11. **Sbalený sidebar**: collapse sidebar, otevři panel → panel přilehlý
    ke sbalenému proužku (48px), nepřekrývá ho.

---

## Dokumentace

### `docs/design-system.md`

- Sekci **9. Dark mode** přejmenovat na **9. Vzhledy (themes)** a
  rozšířit: tři režimy (Shipard / Tmavý / Vlastní), princip „barví se
  jen sidebar, obsah drží brand", tabulka odvozovaných tokenů, nový
  token `--shpd-color-sidebar-active-bg`, preset paleta (s hex
  hodnotami), rozhodnutí o oranžovém proužku (viz Rozhodnutí níže).
- V sekci 3 (Sidebar tokeny) přidat řádky pro `-active-bg` /
  `-active-bg-hover`.

### `docs/frontend.md`

- Sekci **11. Theme management** přepsat: nové módy, formát
  `shpd_theme_custom` / `shpd_theme_tokens`, per-DS klíče
  (`storageKey()` + duplikace DS regexu v bootstrapu — tři místa
  k synchronizaci), `themeStore` API (`custom`, `setCustom`),
  `ThemePanel.svelte`, `themeColor.js`.

### `CLAUDE.md`

Krátká poznámka v sekci Frontend: vzhledy light/dark/custom, custom
barví jen sidebar přes runtime tokeny, detaily v `docs/design-system.md`
a `docs/frontend.md`.

---

## Hotovo když

- [ ] `cd frontend && npm run build 2>&1` — bez chyb a warningů
- [ ] `cd frontend && npm run check:i18n` — OK
- [ ] Smoke testy 1–11 prochází
- [ ] Žádný hardcoded hex v nových komponentách mimo `themePresets.js`
      (presety jsou data, ne styly) a `themeColor.js` (alpha konstanty
      derivace)
- [ ] `vendor/bin/phpunit 2>&1` — prochází (žádná PHP změna, jen sanity)
- [ ] Dokumentace aktualizovaná (design-system, frontend, CLAUDE.md)
- [ ] `tasks/README.md` — task přesunout z Aktivní práce do hotových
      po dokončení (dělá se v navazující session, ne v této)

---

## Rozhodnutí k designu (potvrzená)

- ✓ **Auto režim zaniká** — migrace `'auto'` → `'light'`. Default pro
  nové uživatele je `light` (label „Shipard").
- ✓ **Barví se jen sidebar**, tělo stránky nikdy — chrání doc-state
  systém a čitelnost obsahu.
- ✓ **Akcenty — střední cesta**: obsah stránky drží brand (primary
  modrá, accent oranžová) beze změny; jen aktivní položka sidebaru se
  v custom tématu přepne na neutrální white/black-alpha přes nový token.
- ✓ **Oranžový proužek aktivní položky zůstává ve všech tématech.**
  Pokud by v praxi kolidoval s červenými presety (terakota, vínová),
  zruší se **plošně ve všech tématech** kvůli konzistenci — ne per-téma.
- ✓ **Custom téma podporuje light i dark bázi těla** od Fáze 1.
- ✓ **Persistence Fáze 1 = localStorage** (per-DS klíč v dev, origin
  v produkci). Server-side per-user je Fáze 2, DS-wide default Fáze 3;
  formát `shpd_theme_custom` JSON je navržený jako sdílený pro všechny
  tři úrovně.
- ✓ **Preset paleta 12 barev** (10 tmavých + 2 světlé) — viz
  `themePresets.js`. Olivová a kávová vyřazeny při review.
- ✓ **Fáze 1 = solid barvy**; gradienty, opacity a vlastní color wheel
  jsou Fáze 2.

---

## Doporučené pořadí

1. `themeColor.js` + (volitelně) unit testy — izolovaná matematika.
2. `variables.css` — nové `-active-bg` tokeny; `Sidebar.svelte` CSS
   aktivní položky na nové tokeny. Build + vizuální kontrola: light
   i dark vypadají identicky jako před taskem.
3. Rework `theme.svelte.js` (módy, migrace, per-DS klíče, applyTheme
   s inline tokeny) + bootstrap v `index.html`. Test: light/dark
   fungují, migrace auto.
4. `themePresets.js` + `ThemePanel.svelte` + úprava dropdownu
   v `Sidebar.svelte` + `iconPalette` + i18n klíče.
5. Smoke testy, dokumentace.

Commity granulárně po krocích (každý krok = funkční celek), konvence
`feat(theme): ...` s `Co-Authored-By: Claude` footerem. Push dělá Anna.

---

## Konvence

- **Svelte 5 runes**, props přes `$props()`, callback props.
- **CSS**: BEM `shpd-` prefix, žádné hardcoded hex v komponentách —
  vše přes tokeny nebo props z preset dat.
- **Strukturní mobil přepínání** přes `layoutStore.isMobile` (vzor
  FormInline / FormStateBar), CSS `@media` jen pro čisté vzhledové změny.
- **Před úpravou `Sidebar.svelte` přečíst celý soubor** — 860+ řádků,
  komentáře s diakritikou; `patch_file` je citlivý na přesné znění.
  Pro edity s diakritikou použít Python heredoc workaround.
- **i18n parita** cs/en, ověřit `npm run check:i18n`.
- **Tři synchronizovaná místa** pro localStorage klíče a DS detekci:
  `theme.svelte.js`, `index.html` bootstrap, `api/config.js` (regex).
  Při změně kteréhokoli aktualizovat komentáře u všech.
