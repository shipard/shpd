# Task: Ikony položek menu ve sbaleném sidebaru

## Status / Cíl

Ve sbaleném sidebaru (48 px) zobrazit ikony všech klikatelných položek
menu (leaves) v plochém seznamu, aby uživatel viděl, kam může kliknout.
Aktivní položka se zvýrazní stejně jako v rozbaleném stavu (oranžový
accent proužek vlevo + modré primary pozadí). Tooltip (HTML `title`) na
hoveru ukáže název položky.

Současně se ruší **hover-expand** chování — sbalený sidebar zůstává
trvale sbalený a k rozbalení slouží **jen** toggle tlačítko v hlavičce.
Klávesová zkratka pro toggle je out of scope, řeší se jindy.

Inspirace: VSCode, Slack, Discord — klasický „rail of icons" pattern,
kdy sbalený sidebar poskytuje plnou navigaci jen ve zhuštěné podobě.

## Návaznost

- `frontend-phase3-app-sidebar.md` — dynamická navigace ze serveru
  (`/_ui/navigation`), na které tato změna staví.
- `viewer-row-icons-and-numbers.md` — stejný pattern (`icon` v
  `module.jsonc` → `resolveIcon(name, iconTable)` na frontendu).
- Dokumentace: `docs/frontend.md` sekce **4. Aplikační shell →
  Sidebar — kolapsibilní** popisuje aktuální stav (hover-expand,
  nav skrytý). Po implementaci tuto sekci aktualizovat.

## Scope

### V rozsahu

- Ve sbaleném stavu zobrazit ikony všech leaves — root-level leaves
  (Dashboard), leaves uvnitř groups (Osoby, Upozornění, …) i leaves
  v nested sub-groups. Všechno v jednom plochém svislém seznamu
  v původním pořadí stromu.
- Skupiny (groups) ani sub-groups ve sbaleném stavu nezobrazovat —
  žádné labely sekcí, žádné chevrony.
- Aktivní leaf zvýraznit stejně jako v rozbaleném stavu (varianta **a**
  z designu — accent proužek vlevo + primary pozadí; sekce sama nijak
  zvýrazněna).
- Tooltip (HTML `title`) s `item.label` na každé ikoně.
- Klik na ikonu → běžná navigace (`handleItemClick`), **nikoli** rozbalení
  sidebaru.
- Zrušit hover-expand chování (state `hovered`, mouseenter/mouseleave
  handlery, modifikátor `--hover-expanded` a všechny související selektory).
- Settings mód: tlačítko „Zpět do aplikace" musí být ve sbaleném stavu
  dostupné jako kompaktní ikona (nelze ho schovat — uživatel by neměl
  jak se vrátit, kdyby si nepamatoval, že to dělá toggle).
- Patka (avatar uživatele + dropdown menu) zůstává jak je —
  `.shpd-sidebar__user-button--collapsed` varianta i `.shpd-sidebar__user-menu--side`
  varianta dropdown menu jsou připravené a fungují.

### Mimo rozsah

- Klávesová zkratka pro toggle sidebaru (řešíme někdy jindy).
- Speciální vizuální zvýraznění „sekce, kde se uživatel nachází"
  (uvažovaly se varianty b/c/d, vybrali jsme **a** = jen aktivní leaf).
- Mobilní / responsivní layout sidebaru.
- Změna chování ikon v rozbaleném stavu sidebaru — zůstává jak je.
- Žádné nové ikony do `icons.js` / `iconMap` — všechny použité ikony
  jsou už registrované (`iconAdd`, `iconUser`, `iconAlert`, …).

## Datový tok

```
navTree (ze serveru přes /_ui/navigation)
   │
   ▼
$derived flatLeaves   ← rekurzivní průchod stromem, vrací jen
   │                    nodes s `type` (root-level leaves,
   │                    children groups, children sub-groups)
   │                    v původním pořadí (depth-first)
   ▼
{#if collapsed}
   {#each flatLeaves as leaf}
     <button title={leaf.label} class:--active={activeId === leaf.id}>
       <Icon icon={resolveIcon(leaf.icon)} size="sm" />
     </button>
   {/each}
{:else}
   ... stávající strom skupin (groups + sub-groups + leaves) ...
{/if}
```

Fallback chain ikony pro jeden leaf zůstává stejný jako dnes:

1. Server vrátí `"icon": "user"` v node z `module.jsonc`
   (`viewers[].icon` u vieweru, `tables[].icon` u tabulky, případně
   `dashboard.icon` u Dashboardu).
2. Frontend přes `resolveIcon(name, iconTable)` přeloží na FA icon
   definition.
3. Když pole `icon` chybí, `resolveIcon(undefined)` vrátí `iconTable` —
   tj. leaf bez ikony dostane generickou ikonu tabulky. To je shodné
   s tím, jak fallback funguje v rozbaleném sidebaru.

## Co je potřeba udělat

Všechny změny jsou v jednom souboru: `frontend/src/components/layout/Sidebar.svelte`.
Dokumentační úpravy ve dvou souborech (`docs/frontend.md`, případně
`CLAUDE.md` neměnit — sidebar tam není explicitně popsaný).

### 1. Odstranit hover-expand

#### 1a) Script

Smazat tyto věci:

```js
let hovered = $state(false);
let expanded_sidebar = $derived(!collapsed || hovered);

function handleMouseEnter() { if (collapsed) hovered = true; }
function handleMouseLeave() { if (collapsed) hovered = false; }
```

A v `toggleCollapse()` smazat řádek `if (!collapsed) hovered = false;`
(už není potřeba).

V `$effect`, který zavírá user menu při sbalení, zjednodušit:

```js
// Při sbalení sidebaru zavři otevřené menu (jinak by zůstalo viset).
$effect(() => {
  if (collapsed) closeUserMenu();
});
```

#### 1b) Markup

Z `<nav>` elementu odstranit:

```svelte
class:shpd-sidebar--hover-expanded={collapsed && hovered}
onmouseenter={handleMouseEnter}
onmouseleave={handleMouseLeave}
```

Všechny zbývající podmínky `{#if expanded_sidebar}` nahradit za
`{#if !collapsed}` (logika je teď ekvivalentní: rozbaleno ↔ není sbaleno).

`class:shpd-sidebar__user-button--collapsed={!expanded_sidebar}` →
`class:shpd-sidebar__user-button--collapsed={collapsed}`.

`class:shpd-sidebar__user-menu--side={!expanded_sidebar}` →
`class:shpd-sidebar__user-menu--side={collapsed}`.

#### 1c) CSS

Smazat blok:

```css
.shpd-sidebar--hover-expanded {
  width: var(--shpd-sidebar-width);
  position: absolute;
  top: 0;
  left: 0;
  z-index: 100;
  box-shadow: var(--shpd-shadow-lg);
}
```

Ve všech ostatních selektorech, kde figuruje `:not(.shpd-sidebar--hover-expanded)`,
ho odstranit. Příklady:

- `.shpd-sidebar--collapsed:not(.shpd-sidebar--hover-expanded) .shpd-sidebar__header`
  → `.shpd-sidebar--collapsed .shpd-sidebar__header`
- `.shpd-sidebar--collapsed:not(.shpd-sidebar--hover-expanded) .shpd-sidebar__nav`
  → tento bude celý nahrazen (viz krok 3c)

### 2. Helper — `flattenLeaves(tree)`

V `<script>` přidat čistou funkci (mimo komponentu, nahoře pod importy):

```js
// Rekurzivně sebere všechny klikatelné leaves ze stromu navigace
// v depth-first pořadí. Skupiny (bez `type`, jen s `children`) se
// vynechají; jejich children se ploše zařadí do výsledku.
function flattenLeaves(tree) {
  const leaves = [];
  for (const node of tree) {
    if (node.type) {
      leaves.push(node);
    } else if (node.children) {
      leaves.push(...flattenLeaves(node.children));
    }
  }
  return leaves;
}
```

A v komponentě odvodit:

```js
let flatLeaves = $derived(flattenLeaves(navTree));
```

### 3. Markup — sbalený stav

#### 3a) Header

V hlavičce sbaleného sidebaru se schovává logo (dnes přes
`{#if expanded_sidebar}`, nově `{#if !collapsed}`) — zůstává jen toggle
tlačítko. Beze změny logiky, jen úprava podmínky.

#### 3b) Settings back-bar

Dnešní podmínka:

```svelte
{#if navigationStore.mode === 'settings' && expanded_sidebar}
  <div class="shpd-sidebar__back-bar"> ... text + ikona ... </div>
{/if}
```

Přepracovat na dvě varianty:

```svelte
{#if navigationStore.mode === 'settings'}
  {#if collapsed}
    <div class="shpd-sidebar__back-bar shpd-sidebar__back-bar--collapsed">
      <button
        class="shpd-sidebar__back-button shpd-sidebar__back-button--icon-only"
        onclick={() => navigationStore.exitSettings()}
        title={t('sidebar.backToApp')}
        aria-label={t('sidebar.backToApp')}
      >
        <Icon icon={iconChevronLeft} size="sm" />
      </button>
    </div>
  {:else}
    <div class="shpd-sidebar__back-bar">
      <button class="shpd-sidebar__back-button" onclick={() => navigationStore.exitSettings()}>
        <Icon icon={iconChevronLeft} size="sm" />
        <span>{t('sidebar.backToApp')}</span>
      </button>
    </div>
  {/if}
{/if}
```

#### 3c) Nav

Místo dnešního `display: none` v sbaleném stavu vyrenderovat plochý
seznam ikon. Strukturu `<div class="shpd-sidebar__nav">` zachovat,
uvnitř větvit podle `collapsed`:

```svelte
<div class="shpd-sidebar__nav">
  {#if loading}
    <div class="shpd-sidebar__status">
      <Icon icon={iconSpinner} spin size="sm" />
      {#if !collapsed}<span>{t('common.loading')}</span>{/if}
    </div>
  {:else if error}
    <div class="shpd-sidebar__status shpd-sidebar__status--error">
      {#if collapsed}
        <Icon icon={iconWarning} size="sm" />
      {:else}
        {error}
      {/if}
    </div>
  {:else if collapsed}
    <!-- Sbalený stav: ploché ikony všech leaves, bez sekcí. -->
    <ul class="shpd-sidebar__list shpd-sidebar__list--collapsed">
      {#each flatLeaves as leaf}
        <li>
          <button
            class="shpd-sidebar__item shpd-sidebar__item--icon-only"
            class:shpd-sidebar__item--active={activeId === leaf.id}
            onclick={() => handleItemClick(leaf)}
            title={leaf.label}
            aria-label={leaf.label}
          >
            <Icon icon={resolveIcon(leaf.icon)} size="sm" />
          </button>
        </li>
      {/each}
    </ul>
  {:else}
    <!-- Rozbalený stav: stávající strom skupin (beze změny). -->
    {#each navTree as group}
      {#if group.type}
        ... stávající root-level leaf blok ...
      {:else}
        ... stávající group blok s children ...
      {/if}
    {/each}
  {/if}
</div>
```

Důležité: `{#each flatLeaves as leaf}` v sbaleném stavu vykresluje
i položku, která je v rozbaleném stavu uvnitř collapsed groupy
(neexpandované sekce). To je záměr — uživatel ve sbaleném sidebaru
musí vidět všechny ikony, expand/collapse skupin je věc rozbaleného
stavu.

#### 3d) Imports

Přidat na `<script>` import `iconWarning` (pro chybový stav ve sbaleném
sidebaru, pokud ještě není):

```js
import { ..., iconWarning, ... } from '../../icons.js';
```

(`iconWarning` v `icons.js` už existuje, viz registr.)

### 4. CSS — úpravy pro sbalený stav

Přidat nové třídy a styly:

```css
/* Plochý seznam ikon ve sbaleném sidebaru.
 * Bez sekcí, bez chevronů — jen ikony pod sebou, vystředěné. */
.shpd-sidebar__list--collapsed {
  padding: var(--shpd-space-sm) 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  list-style: none;
}

.shpd-sidebar__list--collapsed > li {
  width: 100%;
  display: flex;
  justify-content: center;
}

/* Sbalená varianta klikatelné položky — čtvercové tlačítko, jen ikona.
 * Stejné barvy a aktivní stav (accent proužek vlevo + primary pozadí)
 * jako rozbalená varianta — uživatel pozná aktivní položku stejně. */
.shpd-sidebar__item--icon-only {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  padding: 0;
  gap: 0;
}

/* Kompaktní back button ve sbaleném settings sidebaru. */
.shpd-sidebar__back-bar--collapsed {
  display: flex;
  justify-content: center;
  padding: var(--shpd-space-xs);
}

.shpd-sidebar__back-button--icon-only {
  width: 32px;
  height: 32px;
  padding: 0;
  gap: 0;
  justify-content: center;
}
```

Zachovat stávající `.shpd-sidebar__item--active` (a jeho `::before` pro
oranžový proužek) — uplatní se i na `.shpd-sidebar__item--icon-only`,
protože je to stejný element s oběma třídami.

Stávající selektor `.shpd-sidebar--collapsed .shpd-sidebar__header`
(`justify-content: center; padding: 0;`) zachovat — toggle button bude
v sbaleném stavu vystředěný.

### 5. Dokumentace — `docs/frontend.md`

V sekci **4. Aplikační shell → Sidebar — kolapsibilní** aktualizovat
popis. Nahradit stávající text:

```
Sidebar je kolapsibilní na úzký proužek (48px). Ve sbaleném stavu:

- Navigační strom a logo jsou skryté
- V patce zůstává jen kruhový avatar uzivatele; klik otevře dropdown menu
  jako overlay vpravo od sidebaru
- Při hoveru myší se sidebar rozbalí jako overlay (`position: absolute`, `z-index: 100`) na plnou šířku, aniž by posouval hlavní obsah
- Po odjetí myší se sidebar zase sbalí

Stav řídí Svelte runes: `collapsed` (toggle tlačítkem), `hovered` (mouseenter/mouseleave).
```

za:

```
Sidebar je kolapsibilní na úzký proužek (48px). Ve sbaleném stavu:

- Logo a sekce navigace (groups, sub-groups) jsou skryté
- Klikatelné položky menu (leaves) zůstávají vidět jako plochý seznam
  ikon — `flattenLeaves(navTree)` rekurzivně vybere všechny nody s `type`
  v depth-first pořadí. Každá ikona má `title` atribut s názvem položky.
- Aktivní položka se zvýrazní stejně jako v rozbaleném stavu
  (oranžový accent proužek vlevo + modré primary pozadí)
- V settings módu zůstává v hlavičce sidebaru kompaktní tlačítko zpět
  (jen ikona `iconChevronLeft`)
- V patce zůstává jen kruhový avatar uživatele; klik otevře dropdown menu
  jako overlay vpravo od sidebaru (`.shpd-sidebar__user-menu--side`)
- Rozbalení/sbalení jen přes toggle tlačítko v hlavičce. Hover myší
  sidebar nerozbaluje (klávesová zkratka pro toggle je plánovaná do
  budoucna).

Stav řídí Svelte runes: `collapsed` (toggle tlačítkem). Pomocný
`$derived flatLeaves` je plochý seznam klikatelných položek pro sbalený
stav.
```

### 6. Smoke test

Po implementaci ručně ověřit (`http://{ip}/{ds-id}/app/`):

- Rozbalený sidebar — strom skupin, chevrony, expand/collapse skupin
  funguje (žádná regrese).
- Sbalený sidebar — vidět plochý seznam ikon: Dashboard, Upozornění,
  Přílohy, Osoby (= active, oranžový proužek + modré pozadí), Kontakty,
  Bankovní účty, Adresy, Období DPH, Položky, Doklady, Faktury vydané,
  Faktury přijaté, Úkoly. Žádné labely sekcí, žádné chevrony.
- Hover myší nad sbaleným sidebarem — nic se neděje (žádné rozbalení
  do overlay). Sidebar zůstává úzký.
- Najetí myší na ikonu — tooltip ukáže název položky (po ~500 ms,
  výchozí browser delay).
- Klik na ikonu — naviguje na položku, sidebar zůstává sbalený, aktivní
  položka se přesune na nově klinutou.
- Klik na ikonu Dashboard — Dashboard se aktivuje, předtím aktivní
  (Osoby) ztratí zvýraznění.
- Patka — avatar uživatele, klik otevře dropdown vedle sidebaru
  (`.shpd-sidebar__user-menu--side`), všechny položky fungují
  (Nastavení účtu / Nastavení aplikace / Vzhled / Jazyk / Odhlásit).
- Vstup do Nastavení aplikace (z dropdownu) → ve sbaleném sidebaru
  je vidět kompaktní „zpět" ikona nahoře a pod ní ploché ikony nastavení.
  Klik na zpět vrátí do app módu.
- Rozbalit sidebar (toggle) v settings módu → vidět celý back-bar
  s textem „Zpět do aplikace" a strom skupin nastavení.
- Loading state — ikona spinneru je vystředěná i ve sbaleném stavu
  (bez textu).
- Error state — `iconWarning` se zobrazí ve sbaleném stavu místo
  chybového textu (text by se nevešel).

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů.
- `vendor/bin/phpunit 2>&1` projde (žádné PHP změny, ale ověření, že
  jsme nic nerozbili náhodou).
- Smoke test (viz sekce 6) projde všemi body.
- `docs/frontend.md` sekce **Sidebar — kolapsibilní** je aktualizována.

## Rozhodnutí k designu (potvrzená)

- ✓ **Hover-expand zrušen** — sbalený sidebar zůstává trvale sbalený,
  rozbalení jen přes toggle. Pattern Slack/VSCode. Klávesová zkratka
  pro toggle je plánovaná, ale není součástí této úlohy.
- ✓ **Ploché ikony všech leaves** — `flattenLeaves(navTree)` rekurzivně
  vybere všechny nody s `type` (root-level leaves, leaves v groups
  i leaves v nested sub-groups). Pořadí depth-first odpovídá pořadí
  v rozbaleném stavu.
- ✓ **Žádné labely sekcí, žádné chevrony** ve sbaleném stavu — uživatel
  vidí jen klikatelné položky.
- ✓ **Aktivní leaf — varianta a** — stejné zvýraznění jako v rozbaleném
  stavu (`--shpd-color-primary` pozadí + `--shpd-color-accent` proužek
  vlevo přes `::before`). Sekce sama nijak vizuálně neoznačena.
- ✓ **Tooltip přes HTML `title`** — `title={leaf.label}` + `aria-label`
  pro screen readery. Žádná vlastní tooltip komponenta.
- ✓ **Klik na ikonu = navigace**, nikoli rozbalení sidebaru.
- ✓ **Položky bez `icon` v `module.jsonc`** — fallback `iconTable`
  z `resolveIcon()` (stejné chování jako rozbalený sidebar).
- ✓ **Settings „zpět" ve sbaleném stavu** — kompaktní ikona
  `iconChevronLeft`, vystředěná, s tooltipem `t('sidebar.backToApp')`.
- ✓ **Patka beze změny** — `.shpd-sidebar__user-button--collapsed`
  i `.shpd-sidebar__user-menu--side` varianty zůstávají, jen se podmínka
  v markupu přepíše z `!expanded_sidebar` na `collapsed`.
- ✓ **Loading/error state ve sbaleném stavu** — spinner zůstává,
  chybový text se nahradí ikonou `iconWarning` (text by se nevešel
  do 48 px sloupce).

## Mimo rozsah / nezasahujeme

- `icons.js`, `iconMap`, `Icon.svelte` — žádné změny, všechny potřebné
  ikony jsou registrované.
- `navigation.svelte.js` store — žádné změny, stačí čtení `activeId`
  a volání `navigate()` / `enterSettings()` / `exitSettings()` jako dnes.
- Backend `NavigationController` — `icon` pole v JSON odpovědi už
  doplňuje pro tabulky i viewery, není co měnit.
- Rozbalený sidebar — zachovat dnešní chování beze změny (strom skupin,
  expand/collapse, root-level leaf, settings back-bar s textem).
- Klávesové zkratky pro toggle / navigaci — řešíme někdy jindy.
