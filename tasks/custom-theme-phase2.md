# Task: Vlastní vzhledy — Fáze 2 (gradienty + opacity + stránkování presetů)

## Motivace

Fáze 1 (`custom-theme-phase1.md`) přinesla custom téma sidebaru se
solid barvami. Fáze 2 přidává to, co dělá Zen/Arc témata atraktivní:
**gradientové presety** a **opacity slider** (míchání vybrané barvy
směrem k bázi aplikace). Panel se nezvětšuje do výšky — preset grid se
**stránkuje šipkami** po stranách (stránka 1 = solid barvy, stránka 2
= gradienty), panel se jen mírně rozšíří.

Formát `shpd_theme_custom` byl ve Fázi 1 navržený dopředu — gradient
je nová hodnota `sidebar.type`, opacity nové top-level pole. Stávající
uložené konfigurace zůstávají validní (chybějící pole = defaulty).

## Cíl fáze

Po dokončení platí:

- Preset grid v panelu má **dvě stránky**: solid barvy (současných 12)
  a gradienty (12 nových). Stránkuje se šipkami `‹` `›` po stranách
  gridu, pod gridem jsou tečky indikující stránku. Při otevření panelu
  se zobrazí stránka obsahující aktuální výběr.
- Gradient se v sidebaru vykresluje **vertikálně** (shora dolů,
  `linear-gradient(180deg, ...)`) přes nový token
  `--shpd-sidebar-bg-image` s fallbackem na solid barvu.
- **Opacity slider** (0–100 %, default 100) míchá vybranou barvu /
  oba stopy gradientu směrem k pozadí báze aplikace (bílá pro light,
  tmavá pro dark). Funguje pro solid i gradient.
- Veškeré odvozování (text, hover, elevated, active) běží
  z **efektivní barvy**: u solid = barva po opacity mixu, u gradientu
  = OKLab střed obou stopů po opacity mixu. Světlé gradienty dostanou
  tmavý text stejně jako světlé solid barvy.
- Anti-flash bootstrap funguje pro gradienty **beze změny** — token
  cache nese i `--shpd-sidebar-bg-image`.
- Přepnutí gradient → solid (nebo na built-in téma) po sobě uklidí
  inline `--shpd-sidebar-bg-image`.

---

## Před implementací přečti

- `tasks/custom-theme-phase1.md` — kontext a potvrzená rozhodnutí Fáze 1
- `frontend/src/utils/themeColor.js` — celý (bude rozšířen; klíčové:
  `SIDEBAR_TOKEN_NAMES`, `hexToOklch`, `deriveSidebarTokens`)
- `frontend/src/stores/theme.svelte.js` — celý (`applyTheme`,
  `setCustom`, formát configu, token cache)
- `frontend/src/components/layout/ThemePanel.svelte` — celý
- `frontend/src/components/layout/themePresets.js`
- `frontend/src/components/layout/Sidebar.svelte` — řádek ~484
  (`background-color: var(--shpd-color-bg-sidebar)`)
- `frontend/src/styles/variables.css` — sidebar tokeny, hodnoty
  `--shpd-color-bg` (řádky ~103 a ~246 — mix targety pro opacity)
- `docs/design-system.md` sekce 9, `docs/frontend.md` sekce 11

---

## Scope

### V rozsahu

- Rozšíření `themeColor.js`: OKLab mix, efektivní barva, gradient
  stopy, opacity, nový token v derivaci
- Token `--shpd-sidebar-bg-image` + úprava pozadí v `Sidebar.svelte`
- `applyTheme` v theme storu: clear-then-set inline tokenů
- Gradient presety v `themePresets.js` + i18n
- Panel: stránkovaný grid se šipkami a tečkami, gradient swatche,
  opacity slider
- Dokumentace

### Mimo rozsah (budoucí fáze)

- **Vlastní gradient** (dva color inputy / color wheel) — odloženo,
  custom color input zůstává jen pro solid
- **Server-side persistence per uživatel** — Fáze 3
- **DS-wide default od správce** — Fáze 4
- Úhel gradientu jako uživatelské nastavení — presety mají fixní
  vertikální směr; formát je na `angle` připravitelný později

---

## Specifikace

### Formát konfigurace (rozšíření, zpětně kompatibilní)

```json
{
  "version": 1,
  "base": "light",
  "opacity": 100,
  "sidebar": { "type": "solid", "color": "#6D1F2C" }
}
```

```json
{
  "version": 1,
  "base": "dark",
  "opacity": 85,
  "sidebar": { "type": "gradient", "stops": ["#00345C", "#0E4F5C"] }
}
```

- `opacity` — **top-level** (vedle `base`), integer 0–100. Top-level
  záměrně: přepnutí solid ↔ gradient přes panel posílá kompletní
  `sidebar` objekt a opacity nesmí být přepsána.
- `sidebar.type: 'gradient'` má `stops` (pole dvou #RRGGBB), nemá
  `color`. Směr je fixně vertikální (180deg) — `angle` ve formátu
  zatím není, doplní se až s vlastními gradienty.
- `version` zůstává 1 — změna je čistě aditivní. Normalizace při
  loadu: chybějící `opacity` → 100 (viz theme store níže).

### `themeColor.js` — rozšíření

Nové konstanty a exporty:

```js
export const SIDEBAR_TOKEN_NAMES = [
  ...stávajících 8 tokenů,
  '--shpd-sidebar-bg-image',
];

/* Pozadí aplikace per báze — mix targety pro opacity. Hodnoty musí
   odpovídat --shpd-color-bg ve variables.css (:root a [data-theme=dark]).
   Při změně variables.css aktualizovat i zde (sync komentář na obou
   místech). */
const BASE_BG = { light: '#ffffff', dark: '#232730' };
```

Nové funkce:

```js
/* #RRGGBB → {L, a, b} v OKLab — mezivýsledek hexToOklch před
   převodem na LCH; refactorovat hexToOklch, ať sdílí kód. */
export function hexToOklab(hex) { ... }

/* Lineární interpolace dvou barev v OKLab (t 0–1; t=0 → a, t=1 → b).
   Vrací {L, a, b}. OKLab místo OKLCH — žádný hue wraparound problém. */
export function mixOklab(hexA, hexB, t) { ... }

/* {L, a, b} → "oklch(l c h)" CSS string (round3 jako dosud). */
export function oklabToCss(lab) { ... }
```

**Refactor `deriveSidebarTokens`** — nová signatura:

```js
/**
 * Z custom konfigurace odvodí kompletní mapu sidebar tokenů.
 * @param {object} sidebar — {type:'solid', color} | {type:'gradient', stops:[a,b]}
 * @param {'light'|'dark'} base
 * @param {number} opacity — 0–100
 * @returns {object} — mapa {tokenName: cssValue}; klíč
 *   --shpd-sidebar-bg-image je přítomen JEN pro type 'gradient'
 */
export function deriveSidebarTokens(sidebar, base, opacity) { ... }
```

Pipeline uvnitř:

1. `t = clamp(opacity, 0, 100) / 100`
2. Stopy po opacity mixu (v OKLab, směrem k `BASE_BG[base]`):
   - solid: `s1 = mixOklab(BASE_BG[base], sidebar.color, t)` — pozor
     na pořadí: `t=1` musí dát plnou barvu, `t=0` čisté pozadí báze
   - gradient: `s1`, `s2` = mix obou stopů stejným `t`
3. **Efektivní barva** pro rozhodování a odvozené tokeny:
   - solid: `s1`
   - gradient: `mixOklab` středů — průměr `s1` a `s2` v OKLab
     (aritmetický průměr L, a, b složek)
4. Tokeny:
   - `--shpd-color-bg-sidebar`: `oklabToCss(s1)` u solid;
     u gradientu `oklabToCss(efektivní)` (slouží jako fallback
     a podklad pro plochy, které gradient nepoužívají)
   - `--shpd-sidebar-bg-image`: jen pro gradient —
     `linear-gradient(180deg, ${oklabToCss(s1)}, ${oklabToCss(s2)})`
   - `--shpd-color-bg-sidebar-elevated`: z efektivní barvy
     (L + 0.06, cap 0.98) — stejné pravidlo jako dosud
   - text/hover/border/active sady: podle luminance **efektivní**
     barvy, stejný threshold 0.65 a stejné alpha hodnoty jako dosud

Stávající chování pro solid s opacity 100 musí zůstat vizuálně
identické (mix s t=1 je identita; drobné zaokrouhlení oklch vs.
původní hex je OK — `--shpd-color-bg-sidebar` může nově být oklch()
string i pro solid, na ničem to nevadí).

### Token `--shpd-sidebar-bg-image` + `Sidebar.svelte`

**`variables.css`**: token se **NEDEKLARUJE** v `:root` ani v dark
bloku. Fallback pattern `var(--shpd-sidebar-bg-image, ...)` funguje
jen pro neexistující property — deklarovaná prázdná hodnota by
fallback rozbila a vyrobila nevalidní `background`. Přidat do sekce
Sidebar tokenů **komentář**, že token existuje pouze jako inline
property z theme storu (custom gradient) a proč tu deklarovaný není.

**`Sidebar.svelte`** (~řádek 484):

```css
background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
```

(`background-color` → `background` — shorthand je nutný, gradient je
image. Ověřit, že na tomtéž selektoru není jiná background-* vlastnost,
kterou by shorthand resetoval.)

### Theme store — `theme.svelte.js`

1. **Normalizace opacity**: v `loadInitialCustom()` po parsu doplnit
   `opacity: typeof parsed.opacity === 'number' ? parsed.opacity : 100`.
   `DEFAULT_CUSTOM` dostane `opacity: 100`.
2. **`applyTheme` pro custom: clear-then-set.** Dnes se inline tokeny
   jen přepisují — gradient → solid by nechal viset
   `--shpd-sidebar-bg-image`. Nově:

```js
if (currentMode === 'custom') {
  setDarkAttribute(currentCustom.base === 'dark');
  clearInlineTokens();
  const tokens = deriveSidebarTokens(
    currentCustom.sidebar, currentCustom.base, currentCustom.opacity ?? 100
  );
  for (const [name, value] of Object.entries(tokens)) { ...setProperty... }
  ...cache...
}
```

   Clear-then-set je bezpečný i proti budoucím změnám množiny tokenů
   (SIDEBAR_TOKEN_NAMES obsahuje i bg-image, takže `clearInlineTokens`
   ho smaže).
3. Volání `deriveSidebarTokens` — nová signatura (jediné call site).
4. Bootstrap v `index.html` — **žádná změna** (cache nese i bg-image,
   loop ho aplikuje). Jen ověřit smoke testem #7.

### Gradient presety — `themePresets.js`

```js
// Gradientové presety — vertikální přechody (180deg, shora dolů).
// Dvojice drží podobné OKLCH lightness pásmo (ΔL ≤ ~0.08): přechody
// jsou posuny odstínu, ne světlosti, aby text a white-alpha zvýraznění
// fungovaly po celé výšce sidebaru. Stopy z velké části recyklují
// solid paletu. Dva světlé na konci (tmavý text z efektivní barvy).
export const THEME_GRADIENT_PRESETS = [
  { id: 'ocean',      stops: ['#00345C', '#0E4F5C'], nameKey: 'theme.gradient.ocean' },
  { id: 'meadow',     stops: ['#6E6320', '#2F5D3A'], nameKey: 'theme.gradient.meadow' },
  { id: 'moss',       stops: ['#115E4B', '#46702F'], nameKey: 'theme.gradient.moss' },
  { id: 'aurora',     stops: ['#2E6494', '#115E4B'], nameKey: 'theme.gradient.aurora' },
  { id: 'midnight',   stops: ['#34307D', '#00345C'], nameKey: 'theme.gradient.midnight' },
  { id: 'heather',    stops: ['#4A2C66', '#8C2F5D'], nameKey: 'theme.gradient.heather' },
  { id: 'blackberry', stops: ['#6D1F2C', '#4A2C66'], nameKey: 'theme.gradient.blackberry' },
  { id: 'sunset',     stops: ['#9A3B26', '#6D1F2C'], nameKey: 'theme.gradient.sunset' },
  { id: 'ember',      stops: ['#9A3B26', '#8C2F5D'], nameKey: 'theme.gradient.ember' },
  { id: 'storm',      stops: ['#2F343D', '#34307D'], nameKey: 'theme.gradient.storm' },
  { id: 'dawn',       stops: ['#DBE4EE', '#E3D5B8'], nameKey: 'theme.gradient.dawn' },
  { id: 'peony',      stops: ['#EAD9E2', '#DBE4EE'], nameKey: 'theme.gradient.peony' },
];
```

### Panel — `ThemePanel.svelte`

**Stránkování.** Lokální state `let presetPage = $state(0)`
(0 = solid, 1 = gradienty). Layout sekce presetů:

```
[‹]  [ grid 4×3 ]  [›]
        • •
```

- Šipky: úzká tlačítka (24px) po stranách gridu, ikony
  `iconChevronLeft` / `iconChevronRight` (ověřit v `icons.js`, případně
  přidat z fa). Krajní šipka `disabled` (žádný wrap-around),
  `aria-label` `t('theme.panel.prevPage')` / `t('theme.panel.nextPage')`.
- Tečky pod gridem: dvě, aktivní zvýrazněná (`--shpd-color-text`),
  neaktivní (`--shpd-color-border-strong`); klikatelné (přepnou
  stránku), `aria-label` s názvem stránky.
- Šířka panelu: 300px → **340px** (potvrzeno — mírné rozšíření kvůli
  šipkám je OK). Zkontrolovat, že se vejde vedle sbaleného i plného
  sidebaru.
- **Při otevření panelu** nastavit `presetPage` podle aktuálního
  výběru: `themeStore.custom.sidebar.type === 'gradient' ? 1 : 0`
  (`$effect` na `open`, jen při přechodu false→true).

**Gradient swatche.** Stejný kruh 36px, jen
`background: linear-gradient(180deg, {stops[0]}, {stops[1]})`.
Výběr (`isSelected`): pro gradient porovnat typ + oba stopy
case-insensitive ve stejném pořadí. Handler:

```js
function selectGradient(stops) {
  themeStore.setCustom({ sidebar: { type: 'gradient', stops } });
}
```

(`opacity` je top-level, takže výměna `sidebar` objektu ji zachová.)

**Custom color input** (sekce Vlastní barva) zůstává — vybírá vždy
solid (`selectColor` beze změny). Když je aktivní gradient, input
ukazuje `--shpd-color-bg-sidebar`... nelze číst snadno — jednodušší:
když `sidebar.type === 'gradient'`, input zobrazuje první stop
(`sidebar.stops[0]`); interakce s ním přepne na solid. Chování
zdokumentovat komentářem.

**Opacity slider.** Nová sekce mezi presety a vlastní barvou:

```svelte
<div class="shpd-theme-panel__section">
  <label class="shpd-theme-panel__label" for="shpd-theme-opacity">
    {t('theme.panel.opacity')}
    <span class="shpd-theme-panel__opacity-value">{themeStore.custom.opacity ?? 100} %</span>
  </label>
  <input
    id="shpd-theme-opacity"
    class="shpd-theme-panel__opacity-slider"
    type="range" min="0" max="100" step="5"
    value={themeStore.custom.opacity ?? 100}
    oninput={(e) => themeStore.setCustom({ opacity: Number(e.target.value) })}
  />
</div>
```

`step="5"` — jemnost stačí a hodnota je vždy „kulatá". Styling slideru
přes tokeny (track `--shpd-color-border`, thumb `--shpd-color-primary`),
konzistentně s existujícími form prvky; pokud projekt nemá styled
range, stačí decentní native + accent-color:
`accent-color: var(--shpd-color-primary)`.

### i18n klíče

```js
// cs.js
'theme.panel.opacity': 'Intenzita barvy',
'theme.panel.prevPage': 'Předchozí stránka presetů',
'theme.panel.nextPage': 'Další stránka presetů',
'theme.panel.pageSolid': 'Plné barvy',
'theme.panel.pageGradient': 'Přechody',
'theme.gradient.ocean': 'Oceán',
'theme.gradient.meadow': 'Louka',
'theme.gradient.moss': 'Mech',
'theme.gradient.aurora': 'Polární záře',
'theme.gradient.midnight': 'Půlnoc',
'theme.gradient.heather': 'Vřes',
'theme.gradient.blackberry': 'Ostružina',
'theme.gradient.sunset': 'Západ slunce',
'theme.gradient.ember': 'Žár',
'theme.gradient.storm': 'Bouřka',
'theme.gradient.dawn': 'Svítání',
'theme.gradient.peony': 'Pivoňka',

// en.js
'theme.panel.opacity': 'Color intensity',
'theme.panel.prevPage': 'Previous preset page',
'theme.panel.nextPage': 'Next preset page',
'theme.panel.pageSolid': 'Solid colors',
'theme.panel.pageGradient': 'Gradients',
'theme.gradient.ocean': 'Ocean',
'theme.gradient.meadow': 'Meadow',
'theme.gradient.moss': 'Moss',
'theme.gradient.aurora': 'Aurora',
'theme.gradient.midnight': 'Midnight',
'theme.gradient.heather': 'Heather',
'theme.gradient.blackberry': 'Blackberry',
'theme.gradient.sunset': 'Sunset',
'theme.gradient.ember': 'Ember',
'theme.gradient.storm': 'Storm',
'theme.gradient.dawn': 'Dawn',
'theme.gradient.peony': 'Peony',
```

Ověřit `npm run check:i18n`.

---

## Testy

### Unit — `themeColor.js` (rozšířit stávající / stejný režim jako Fáze 1)

- `mixOklab('#000000', '#ffffff', 0.5).L` ≈ 0.5 (±0.05)
- `mixOklab(a, b, 1)` ≈ `hexToOklab(b)`; `t=0` ≈ `hexToOklab(a)`
- `deriveSidebarTokens({type:'solid', color:'#6D1F2C'}, 'light', 100)`
  — vizuálně identické s Fází 1: bg-sidebar odpovídá vínové
  (oklch ekvivalent), světlý text, klíč `--shpd-sidebar-bg-image`
  **chybí**
- `deriveSidebarTokens({type:'gradient', stops:['#00345C','#0E4F5C']}, 'light', 100)`
  — `--shpd-sidebar-bg-image` je `linear-gradient(180deg, ...)`,
  text světlý
- gradient `['#DBE4EE','#E3D5B8']` → tmavý text (efektivní barva
  světlá)
- `opacity 0, base 'light'` → bg-sidebar ≈ bílá (L > 0.95), tmavý text
- vrácené klíče jsou podmnožinou `SIDEBAR_TOKEN_NAMES`

### Manuální smoke testy

1. **Regrese solid**: stávající solid výběr (opacity slider na 100)
   vypadá identicky jako před taskem; starý uložený config bez
   `opacity` se načte a funguje (normalizace na 100).
2. **Stránkování**: šipky přepínají solid ↔ gradienty, krajní šipka
   disabled, tečky klikatelné. Otevření panelu s vybraným gradientem
   → rovnou stránka 2.
3. **Gradient výběr**: klik na Oceán → sidebar s vertikálním
   přechodem, text bílý, aktivní položka white-alpha + oranžový
   proužek, user menu dropdown (elevated) v barvě středu přechodu.
4. **Světlý gradient**: Svítání → tmavý text, black-alpha plochy.
5. **Opacity solid**: slider na vínové — barva plynule bledne k bílé
   (light báze); kolem ~40 % a níž se přepne na tmavý text a vše
   zůstává čitelné.
6. **Opacity gradient + dark báze**: gradient + Tmavý základ + slider
   → stopy blednou k tmavému pozadí, ne k bílé.
7. **Anti-flash s gradientem**: reload s gradient tématem → žádný
   flash (bootstrap aplikuje bg-image z cache).
8. **Úklid bg-image**: gradient → klik na solid preset → přechod
   zmizí (zkontrolovat, že inline `--shpd-sidebar-bg-image` na
   `<html>` není). Gradient → vzhled Shipard → totéž.
9. **Custom color input při gradientu**: ukazuje první stop;
   interakce přepne na solid.
10. **Mobil**: Modal varianta — stránkování i slider fungují.
11. **Per-DS izolace** (dev): gradient v DS A neovlivní DS B.

---

## Dokumentace

- **`docs/design-system.md`** sekce 9 (Vzhledy): doplnit gradientové
  presety (tabulka id / název / stopy), pravidlo ΔL ≤ ~0.08, token
  `--shpd-sidebar-bg-image` (a proč není deklarovaný ve
  variables.css), opacity model (mix k `--shpd-color-bg` báze
  v OKLab).
- **`docs/frontend.md`** sekce 11: rozšířený formát configu
  (`opacity`, `sidebar.type 'gradient'`), nová signatura
  `deriveSidebarTokens(sidebar, base, opacity)`, clear-then-set
  v `applyTheme`, BASE_BG sync poznámka (variables.css ↔
  themeColor.js).
- **`variables.css`**: sync komentář u `--shpd-color-bg` (light
  i dark), že hodnoty zrcadlí `BASE_BG` v `themeColor.js`.
- `CLAUDE.md` — jen pokud zmínka o vzhledech neodpovídá (gradienty
  doplnit jedním slovem).

---

## Hotovo když

- [ ] `cd frontend && npm run build 2>&1` — bez chyb a warningů
- [ ] `cd frontend && npm run check:i18n` — OK
- [ ] Unit testy `themeColor` prochází (pokud test setup existuje)
- [ ] Smoke testy 1–11 prochází
- [ ] `--shpd-sidebar-bg-image` není deklarovaný ve `variables.css`
      (jen komentář) a po přepnutí na solid/built-in není inline
- [ ] Žádný hardcoded hex mimo `themePresets.js` a `BASE_BG`
      v `themeColor.js`
- [ ] Dokumentace aktualizovaná

---

## Rozhodnutí k designu (potvrzená)

- ✓ **Stránkovaný grid se šipkami po stranách** místo zvětšování
  panelu do výšky; panel se rozšíří na 340px. Stránka 1 solid,
  stránka 2 gradienty; tečky jako indikátor.
- ✓ **Gradient vertikálně** (180deg, shora dolů) — na úzkém vysokém
  sidebaru má přechod prostor; horizontální zamítnut.
- ✓ **12 gradientových presetů** dle finální sestavy: Oceán, Louka,
  Mech, Polární záře, Půlnoc, Vřes, Ostružina, Západ slunce, Žár,
  Bouřka + světlé Svítání a Pivoňka. Mech rozšířen do listové zeleně
  a Louka (zlatá → zelená) nahradila Lagunu při review (moc podobná
  Polární záři, chyběla žlutá).
- ✓ **Opacity slider přibalen do této fáze** (původně samostatně);
  míchá k pozadí báze v OKLab, top-level pole configu, default 100.
- ✓ **Vlastní gradient odložen** — custom input zůstává solid-only.
- ✓ **Odvozování z efektivní barvy** (OKLab střed stopů po opacity
  mixu) — jeden pipeline pro solid i gradient, žádná zvláštní
  pravidla.

---

## Doporučené pořadí

1. `themeColor.js` — `hexToOklab` refactor, `mixOklab`, `oklabToCss`,
   nová signatura `deriveSidebarTokens` + unit testy. Izolovaná
   matematika bez UI.
2. `theme.svelte.js` — normalizace opacity, clear-then-set, nové
   volání derivace. `variables.css` komentáře + `Sidebar.svelte`
   background shorthand. Smoke: solid regrese (test #1).
3. `themePresets.js` gradienty + i18n klíče.
4. `ThemePanel.svelte` — stránkování (šipky, tečky, auto-stránka při
   otevření), gradient swatche, opacity slider, šířka 340px.
5. Smoke testy, dokumentace.

Commity granulárně po krocích, konvence `feat(theme): ...`
s `Co-Authored-By: Claude` footerem. Push dělá Anna.

---

## Konvence

- **Svelte 5 runes**; helpery uvnitř `<script>` komponenty.
- **CSS**: BEM `shpd-` prefix, tokeny; gradient/preset hex pouze
  v `themePresets.js`, mix targety pouze v `BASE_BG`.
- **Před úpravou `ThemePanel.svelte` a `Sidebar.svelte` přečíst celé
  soubory**; pro edity s diakritikou Python heredoc workaround
  (komentáře v obou souborech diakritiku obsahují).
- **i18n parita** cs/en + `npm run check:i18n`.
- **Synchronizovaná místa**: `BASE_BG` ↔ `--shpd-color-bg` ve
  variables.css (nové, komentáře na obou stranách); klíče/formáty
  localStorage ↔ bootstrap ↔ config.js (z Fáze 1, beze změny).
