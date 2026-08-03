# Frontend i18n — Fáze 1A (kostra vícejazyčnosti)

**Stav:** hotovo

## Status / Cíl fáze

Zavedení vícejazyčnosti do frontend SPA. Cílem této fáze je **kostra
infrastruktury** — language store, překladové slovníky, helper `t()`,
`Accept-Language` v API klientu, anti-flash bootstrap a přepínač jazyka
v sidebaru. Po dokončení fáze:

- Aplikace umí přepnout mezi `cs` / `en` / `auto` přes UI (analogicky
  k přepínači vzhledu).
- Server-driven obsah (sidebar navigace, viewer titulky, meta tabulek,
  doc states) se po reloadu vrací v zvoleném jazyce — toto **už backend
  umí**, frontend potřebuje jen prostrkat hlavičku.
- UI chrome ve frontendu (tlačítka, taby, prázdné stavy) zatím **zůstává
  česky** — překlady komponent jsou samostatná Fáze 1B.

Tato fáze záměrně nešahá na komponenty (kromě `Sidebar.svelte`, kam se
přidává přepínač). Cílem je dostat infrastrukturu do stabilního stavu
a teprve pak postupně migrovat komponenty.

## Návaznost

- Backend i18n (`ConfigLocalizer`, `LocalizedFieldResolver`) je hotový —
  viz `docs/modules.md` sekce 3.
- 62 ze 74 jsonc souborů v `modules/` má překlady `name:cs` + `name:en`.
  Doplnění zbylých ~12 souborů řeší Fáze 1C, není blokerem této fáze.
- `public/index.php` čte `Accept-Language` v `resolveLanguage()` (s
  fallbackem `'en'`) a prostrkuje skrz `TableLoader`, `MetaController`,
  `NavigationController`, `SettingsController`, `ViewerLoader`.
- Vzor pro store + bootstrap je `frontend/src/stores/theme.svelte.js`
  + inline script v `frontend/index.html`. Postupuj analogicky.

## Scope

### V rozsahu

- Language store (`frontend/src/stores/language.svelte.js`) s API
  `mode` / `current` / `setMode()` / `t()` / `tn()`.
- Překladové slovníky `frontend/src/i18n/cs.js` a `frontend/src/i18n/en.js`
  s počáteční sadou klíčů (sidebar přepínač + nezbytné minimum, viz
  sekce *Slovník — počáteční klíče*).
- ICU MessageFormat formatter (`@formatjs/intl-messageformat` —
  npm install).
- Helper `t(key, params?)` pro plain interpolaci a pluralizaci přes ICU
  syntax. Helper `tn(key, count, params?)` jako tenký wrapper, když je
  count hlavní param (volitelný — `t()` zvládne všechno).
- Auto-injection `Accept-Language: {language.current}` v `api/client.js`
  do každého requestu.
- Anti-flash bootstrap v `frontend/index.html` — čte `localStorage.shpd_language`,
  nastaví `document.documentElement.lang` před prvním renderem.
- Přepínač jazyka v dropdownu user menu v `Sidebar.svelte` — sekce
  „Jazyk" hned pod sekcí „Vzhled". Tři položky: **Čeština / English /
  Automaticky**.
- `setMode()` vyvolá `location.reload()` po persistenci do localStorage
  — viz *Rozhodnutí k designu*.
- Tooling pro slovníky: jednoduchý lint script `scripts/check-i18n.mjs`,
  který detekuje chybějící klíče v `en` vs `cs` a opačně. Volá se ručně
  (`npm run check:i18n`), nemusí být v CI.

### Mimo rozsah (Fáze 1B / 1C)

- Překlad jednotlivých komponent (Viewer, FormDialog, LoginScreen, …) —
  toto je Fáze 1B, samostatný task.
- Validační hlášky ze serveru — Fáze 1C (mapping `error.code` → text).
- `defaultLanguage` v `DataSourceConfig` — Fáze 1C.
- Doplnění `name:en` ve zbylých jsonc souborech — Fáze 1C, mechanická
  práce.
- Sloupec `preferred_language` v `core_system_users` — odložené, volba
  per-zařízení (localStorage) je dle rozhodnutí dostatečná.
- Přidání třetího jazyka — řeší se až bude potřeba; architektura ho ale
  nesmí blokovat (slovníky jsou per-jazyk soubory, store podporuje
  libovolný kód).

## Rozhodnutí k designu (potvrzená)

- ✓ **Volba jazyka per-zařízení**, nikoli per-uživatel. Persistence
  v `localStorage` pod klíčem `shpd_language`. Odpadá rozšiřování
  `core_system_users` v této fázi.
- ✓ **`location.reload()` po přepnutí**, nikoli soft refetch. Důvod:
  jednoduchost, nulové riziko stale stavu, uživatelé jsou na to z jiných
  aplikací zvyklí. Soft refetch lze přidat později, pokud reload bude
  vadit.
- ✓ **Chybové hlášky ze serveru se překládají na klientovi** přes
  mapping `error.code → text` (až ve Fázi 1C). Server vrací anglickou
  `message` jako fallback pro neznámé kódy.
- ✓ **Pluralizace přes `@formatjs/intl-messageformat`** (ICU MessageFormat).
  Tenké runtime (~12 KB gzip), tree-shake-friendly, podporuje libovolný
  jazyk přes vestavěná CLDR pravidla. Ručně psaný `tn()` by se rozbil
  při přidání třetího slovanského jazyka.
- ✓ **Slovníky jsou ploché objekty** s tečkovou notací klíčů
  (`'sidebar.language'`, `'viewer.tab.active'`). Žádná hluboká struktura.
- ✓ **Klíče v anglické konvenci** (`'common.cancel'`, ne `'spolecne.zrusit'`)
  — odpovídá zbytku kódu, kde komentáře a názvy proměnných jsou anglicky.
- ✓ **Anglický fallback** — pokud klíč chybí v `cs`, použije se `en`.
  Pokud chybí i tam, vrátí se klíč samotný (`'sidebar.foo'`) jako
  vizuální signál pro vývojáře. Žádný runtime exception.
- ✓ **`'auto'` mód** čte `navigator.language` a redukuje na první 2
  znaky. Pokud to není `cs` ani `en`, fallback `en`. Default pro nové
  uživatele: `'auto'`.

## Datový model

Žádné změny v DB této fáze. Všechny změny jsou ve frontendu
(`frontend/src/`).

## Soubory

### Nové

```
frontend/src/stores/language.svelte.js
frontend/src/i18n/cs.js
frontend/src/i18n/en.js
frontend/src/i18n/index.js                    # exportuje t, tn, language store
frontend/scripts/check-i18n.mjs               # lint slovníků
```

### Měněné

```
frontend/index.html                           # přidat anti-flash bootstrap pro lang
frontend/src/api/client.js                    # přidat Accept-Language header
frontend/src/components/layout/Sidebar.svelte # přidat sekci Jazyk do user menu
frontend/package.json                         # přidat @formatjs/intl-messageformat
                                              # přidat npm script "check:i18n"
docs/frontend.md                              # přidat sekci Internacionalizace
CLAUDE.md                                     # zmínit i18n v sekci Frontend
```

## API / kontrakty

### `language.svelte.js` — public API

```js
import { language, t, tn } from '../../i18n/index.js';

// Stav
language.mode;       // 'cs' | 'en' | 'auto' — uživatelská volba
language.current;    // 'cs' | 'en' — efektivní jazyk (auto rozbalen)
language.setMode('cs');  // přepne, persistuje, reload()

// Překlad
t('common.cancel');                          // "Zrušit"
t('viewer.empty', { table: 'Faktury' });     // "Tabulka Faktury je prázdná"

// Pluralizace přes ICU MessageFormat — count je první argument
t('viewer.recordCount', { count: 3 });       // "3 záznamy"
// Klíč v slovníku: 'viewer.recordCount': '{count, plural, one {# záznam} few {# záznamy} many {# záznamů} other {# záznamů}}'

// Volitelný shortcut, kdy je count "the param"
tn('viewer.recordCount', 5);                 // ekvivalent t('viewer.recordCount', { count: 5 })
```

### Bootstrap script v `index.html`

Inline `<script>` před načtením modulů, analogicky k tématu:

```html
<script>
  (function() {
    try {
      var stored = localStorage.getItem('shpd_language');
      var lang;
      if (stored === 'cs' || stored === 'en') {
        lang = stored;
      } else {
        // 'auto' nebo nic — z navigator.language
        var nav = (navigator.language || 'en').slice(0, 2).toLowerCase();
        lang = (nav === 'cs') ? 'cs' : 'en';
      }
      document.documentElement.lang = lang;
    } catch (e) {
      document.documentElement.lang = 'en';
    }
  })();
</script>
```

### `api/client.js` — header injection

V existujícím wrapperu kolem `fetch` přidat:

```js
import { language } from '../i18n/index.js';

// V buildHeaders nebo ekvivalentu:
headers.set('Accept-Language', language.current);
```

Pozor — `language` se importuje z `i18n/index.js`, ne přímo ze store
souboru. Tím se zajistí, že `client.js` nemá přímou závislost na
implementaci storu (jen na public API).

## Slovník — počáteční klíče

Tato fáze definuje **minimální sadu klíčů**. Komponenty se zatím nepřekládají
(to je Fáze 1B), ale klíče potřebné pro samotný přepínač a několik
„společných" stringů musí existovat. Plný překlad celé aplikace
dorovná Fáze 1B.

```js
// cs.js
export default {
  // Sidebar — Language picker
  'sidebar.language': 'Jazyk',
  'sidebar.language.cs': 'Čeština',
  'sidebar.language.en': 'English',
  'sidebar.language.auto': 'Automaticky',

  // Společné (předpříprava pro Fázi 1B)
  'common.cancel': 'Zrušit',
  'common.save': 'Uložit',
  'common.close': 'Zavřít',
  'common.add': 'Přidat',
  'common.edit': 'Upravit',
  'common.delete': 'Smazat',
  'common.loading': 'Načítám…',
  'common.error': 'Nastala chyba',
};
```

```js
// en.js
export default {
  'sidebar.language': 'Language',
  'sidebar.language.cs': 'Čeština',
  'sidebar.language.en': 'English',
  'sidebar.language.auto': 'Automatic',

  'common.cancel': 'Cancel',
  'common.save': 'Save',
  'common.close': 'Close',
  'common.add': 'Add',
  'common.edit': 'Edit',
  'common.delete': 'Delete',
  'common.loading': 'Loading…',
  'common.error': 'An error occurred',
};
```

Přepínač sám sebe nepřekládá pro jména jazyků — `Čeština` a `English`
zůstávají v obou slovnících stejně (endonyma). To je úmyslné UX —
uživatel, který si omylem zapne anglické UI, musí poznat, kde je
čeština.

## Implementační detaily

### `language.svelte.js` — kostra

```js
import IntlMessageFormat from 'intl-messageformat';
import csMessages from '../i18n/cs.js';
import enMessages from '../i18n/en.js';

const STORAGE_KEY = 'shpd_language';
const VALID_MODES = ['cs', 'en', 'auto'];
const SUPPORTED_LANGS = ['cs', 'en'];
const DICTIONARIES = { cs: csMessages, en: enMessages };

function loadInitialMode() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (VALID_MODES.includes(stored)) return stored;
  } catch {}
  return 'auto';
}

function detectAuto() {
  try {
    const nav = (navigator.language || 'en').slice(0, 2).toLowerCase();
    return SUPPORTED_LANGS.includes(nav) ? nav : 'en';
  } catch {
    return 'en';
  }
}

function computeCurrent(mode) {
  if (mode === 'cs' || mode === 'en') return mode;
  return detectAuto();
}

let mode = $state(loadInitialMode());
let current = $derived(computeCurrent(mode));

// Cache pro IntlMessageFormat instance — kompilace ICU stringu není
// triviální, opakované rendery stejného klíče by jinak byly drahé.
const formatterCache = new Map();

function getFormatter(key, lang) {
  const cacheKey = `${lang}::${key}`;
  if (formatterCache.has(cacheKey)) return formatterCache.get(cacheKey);

  const dict = DICTIONARIES[lang] ?? DICTIONARIES.en;
  const message = dict[key] ?? DICTIONARIES.en[key] ?? key;
  const formatter = new IntlMessageFormat(message, lang);
  formatterCache.set(cacheKey, formatter);
  return formatter;
}

function t(key, params) {
  const formatter = getFormatter(key, current);
  try {
    return formatter.format(params ?? {});
  } catch (e) {
    console.warn(`i18n format error for key '${key}':`, e);
    return key;
  }
}

function tn(key, count, params) {
  return t(key, { count, ...(params ?? {}) });
}

function setMode(newMode) {
  if (!VALID_MODES.includes(newMode)) return;
  try {
    localStorage.setItem(STORAGE_KEY, newMode);
  } catch {}
  // Reload — viz Rozhodnutí k designu
  location.reload();
}

export const language = {
  get mode() { return mode; },
  get current() { return current; },
  setMode,
};

export { t, tn };
```

### `i18n/index.js` — barrel export

```js
export { language, t, tn } from '../stores/language.svelte.js';
```

Tenký barrel, aby ostatní soubory importovaly z `i18n/` bez znalosti, že
implementace je ve `stores/`. Konzistentní s pattern použitým u API.

### Přepínač v `Sidebar.svelte`

Pod existující sekci „Vzhled" v dropdownu user menu přidat sekci „Jazyk"
se stejným vizuálem (tři položky s checkmarkem u aktivní volby). Použít
stejné CSS třídy (`.shpd-sidebar__user-menu-label`, `…-item`,
`…-item--active`, `…-item-check`).

```svelte
<script>
  import { language, t } from '../../i18n/index.js';

  const languageOptions = [
    { value: 'cs',   labelKey: 'sidebar.language.cs' },
    { value: 'en',   labelKey: 'sidebar.language.en' },
    { value: 'auto', labelKey: 'sidebar.language.auto' },
  ];

  function handleLanguageChange(value) {
    language.setMode(value);  // → reload
  }
</script>

<!-- v dropdownu, pod sekcí Vzhled: -->
<div class="shpd-sidebar__user-menu-divider"></div>
<div class="shpd-sidebar__user-menu-label">{t('sidebar.language')}</div>
{#each languageOptions as opt}
  <button
    class="shpd-sidebar__user-menu-item"
    class:shpd-sidebar__user-menu-item--active={language.mode === opt.value}
    onclick={() => handleLanguageChange(opt.value)}
    role="menuitemradio"
    aria-checked={language.mode === opt.value}
  >
    <span class="shpd-sidebar__user-menu-item-label">{t(opt.labelKey)}</span>
    {#if language.mode === opt.value}
      <span class="shpd-sidebar__user-menu-item-check">
        <Icon icon={iconCheck} size="xs" />
      </span>
    {/if}
  </button>
{/each}
```

### `scripts/check-i18n.mjs`

```js
import csDict from '../src/i18n/cs.js';
import enDict from '../src/i18n/en.js';

const csKeys = new Set(Object.keys(csDict));
const enKeys = new Set(Object.keys(enDict));

const missingInEn = [...csKeys].filter(k => !enKeys.has(k));
const missingInCs = [...enKeys].filter(k => !csKeys.has(k));

if (missingInEn.length === 0 && missingInCs.length === 0) {
  console.log('✓ i18n dictionaries are in sync');
  process.exit(0);
}

if (missingInEn.length > 0) {
  console.error('Keys missing in en:');
  missingInEn.forEach(k => console.error('  ' + k));
}
if (missingInCs.length > 0) {
  console.error('Keys missing in cs:');
  missingInCs.forEach(k => console.error('  ' + k));
}
process.exit(1);
```

V `package.json`:

```json
{
  "scripts": {
    "check:i18n": "node scripts/check-i18n.mjs"
  }
}
```

## Task breakdown

### T1 — npm závislost a barrel

- Přidat `@formatjs/intl-messageformat` do `frontend/package.json`
  (`npm install @formatjs/intl-messageformat`).
- Vytvořit prázdné `frontend/src/i18n/cs.js`, `en.js`, `index.js`
  (zatím bez obsahu, jen struktura).

**Hotovo když:** `npm install` projde, `import IntlMessageFormat from
'intl-messageformat'` v testovacím skriptu funguje.

### T2 — language store

- Implementovat `frontend/src/stores/language.svelte.js` podle kostry
  v sekci *Implementační detaily*.
- Naplnit slovníky `cs.js` a `en.js` počáteční sadou klíčů ze sekce
  *Slovník*.
- Doplnit `i18n/index.js` jako barrel export.

**Hotovo když:** v dev konzoli (`npm run dev`) lze `import { t, language }
from './i18n/index.js'` a volat `t('common.cancel')` → vrací správný
řetězec dle `language.current`. `language.setMode('en')` přehodí
localStorage a reloadne stránku.

### T3 — Accept-Language v API klientu

- V `frontend/src/api/client.js` přidat header `Accept-Language:
  {language.current}` ke každému requestu.
- Ověřit, že DevTools Network tab ukazuje hlavičku v requestech.

**Hotovo když:** přepnutí jazyka přes localStorage (manuálně) + reload
způsobí, že `GET /_ui/navigation` vrátí navigaci v novém jazyce
(viditelné v Sidebaru — názvy modulů a tabulek se přepnou).

### T4 — anti-flash bootstrap

- Přidat inline `<script>` do `frontend/index.html` před `<script
  type="module" src="/src/main.js"></script>`.
- Nastavuje `document.documentElement.lang` z `localStorage.shpd_language`
  s fallbackem přes `navigator.language`.

**Hotovo když:** v Page Source je `<html lang="cs">` (resp. `lang="en"`)
už při prvním renderu, ne až po hydrataci JS.

### T5 — přepínač v Sidebar.svelte

- Přidat sekci „Jazyk" do dropdownu user menu, pod sekci „Vzhled".
- Reuse existujících CSS tříd (`.shpd-sidebar__user-menu-*`).
- Klik na položku volá `language.setMode(value)` → reload.

**Hotovo když:** klik na vlajku/jméno v patce sidebaru otevře dropdown
se sekcí „Jazyk" pod sekcí „Vzhled". Klik na „English" → stránka se
reloadne, sidebar (server-driven) je v angličtině, zaškrtnutí
v dropdownu je u „English". Klik na „Automaticky" → použije se
prohlížečová preference.

### T6 — lint script

- Vytvořit `frontend/scripts/check-i18n.mjs` podle kostry v sekci
  *Implementační detaily*.
- Přidat npm script `"check:i18n": "node scripts/check-i18n.mjs"` do
  `frontend/package.json`.
- Spustit `npm run check:i18n` — musí projít se zelenou (slovníky
  v této fázi jsou paralelní 1:1).

**Hotovo když:** `npm run check:i18n` v `frontend/` vrátí exit code 0.
Když do jednoho slovníku přidám klíč navíc, vrátí exit code 1
a vypíše chybějící klíč.

### T7 — dokumentace

- V `docs/frontend.md` přidat novou sekci **„Internacionalizace"** mezi
  sekce 11 (Theme) a 12 (Budoucí rozšíření). Popsat:
  - kde žije language store, slovníky, ICU formatter
  - jak přidat klíč (cs.js + en.js, používat tečkovou notaci)
  - jak používat `t()` / `tn()` v komponentách
  - bootstrap a `Accept-Language`, fallback chain
  - poznámka „komponenty se zatím nepřekládají, viz Fáze 1B"
- V `CLAUDE.md` v sekci „Frontend" přidat řádek:
  ```
  ### Frontend — Vícejazyčnost
  - Language store: frontend/src/stores/language.svelte.js
  - Slovníky: frontend/src/i18n/{cs,en}.js (ploché, tečková notace)
  - Helper t() přes ICU MessageFormat (@formatjs/intl-messageformat)
  - Volba per-zařízení (localStorage shpd_language), reload po přepnutí
  - Backend dostává volbu přes Accept-Language header v api/client.js
  ```

**Hotovo když:** `docs/frontend.md` má sekci o i18n, `CLAUDE.md` zmiňuje
i18n strukturu.

## Akceptační kritéria celé fáze

1. `cd frontend && npm run build 2>&1` — projde bez chyb a varování.
2. `cd frontend && npm run check:i18n` — projde se zelenou.
3. V dev módu (`npm run dev`):
   - Klik na avatar v sidebaru → dropdown obsahuje sekce **Vzhled**
     a **Jazyk**.
   - Klik na **English** v dropdownu → stránka se reloadne, položky
     navigace jsou v angličtině (`Users`, `Persons`, `Documents`, …),
     v dropdownu je zaškrtnuté **English**.
   - Klik na **Čeština** → reload, navigace v češtině, zaškrtnuté
     **Čeština**.
   - Klik na **Automaticky** → reload, jazyk podle prohlížeče.
4. V `localStorage` se ukládá klíč `shpd_language` s hodnotou `'cs'` /
   `'en'` / `'auto'`.
5. V Network tabu mají všechny requesty na `/api/...` hlavičku
   `Accept-Language: cs` (resp. `en`) podle aktuální volby.
6. V Page Source je `<html lang="cs">` (resp. `en`) hned při prvním
   načtení, bez flash.
7. UI chrome (české texty v komponentách) **zůstává česky** — to je
   v pořádku, překlady komponent jsou Fáze 1B. Důležité je, že
   server-driven obsah se přepíná správně.
8. `vendor/bin/phpunit 2>&1` — projde (nemělo by se nic backend-side
   změnit, ale ověřit pro jistotu).

## Otevřené otázky

Žádné — všechny body jsou potvrzené v sekci *Rozhodnutí k designu*.
Pokud při implementaci narazíš na nečekanou věc (typicky nějaká
interakce s `navigation.svelte.js` nebo způsob, jak `api/client.js`
buildí headery), zastav se a zeptej před úpravou.

## Návazné fáze

- **Fáze 1B (frontend i18n — UI chrome)**: postupný překlad ~22
  komponent. Začít Sidebarem (zbytek mimo přepínač jazyka), Viewerem
  (taby, hledání, prázdné stavy), LoginScreen, Modal/Dialog. Detaily
  formulářů (`FormEditor`, `FormSubTable`) jako poslední.
- **Fáze 1C (backend doplňky)**: doplnit `name:en` ve zbylých ~12
  jsonc souborech, přidat `getDefaultLanguage()` do `DataSourceConfig`,
  použít ho v `resolveLanguage()` jako fallback místo hard-coded
  `'en'`. Mapping `error.code → překlad` pro validační hlášky ze
  serveru.
