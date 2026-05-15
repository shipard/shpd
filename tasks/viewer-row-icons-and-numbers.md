# Task: Ikony a pořadová čísla v řádcích vieweru

## Status / Cíl

Přidat do každého řádku vieweru:

1. **Ikonu** (vlevo, default = stejná jako v sidebaru, lze přepsat per-row podle dat).
2. **Pořadové číslo** (nahoře nad ikonou, souvisle 1, 2, 3… přes celý načtený seznam).

Inspirace: takhle to fungovalo ve staré aplikaci Shipard. Uživatel díky tomu
rychle pozná, v jaké entitě se nachází (faktury vs. osoby vs. úkoly), a vidí
„kolik si toho už načetl".

Pro Osoby specificky se ikona řídí typem osoby — `user` pro fyzickou,
`company` (building) pro právnickou.

## Návaznost

- `frontend-phase5-viewers.md` — postavený `Viewer.svelte` + `ViewerRow.svelte`,
  na který tahle změna staví.
- Sidebar už dnes čte `icon` z `module.jsonc` v každém `viewers[]` přes
  `NavigationController`. Tuhle stejnou hodnotu chceme propsat i do
  vlastních řádků vieweru — žádné duplicování konfigurace.

## Scope

### V rozsahu

- Backend: ikona z `module.jsonc` se dostane do každého řádku vieweru jako
  default; `PersonsViewer` ikonu přepisuje podle `person_type`.
- Frontend: `ViewerRow.svelte` vykreslí v levém sloupci pořadové číslo
  + SVG ikonu (přes existující `<Icon>` + `resolveIcon()`).
- Pořadové číslo počítané ve frontendu z pozice v poli `rows`.

### Mimo rozsah

- Jakákoli změna `Icon.svelte` / `icons.js` / `iconMap` — všechny potřebné
  ikony (`user`, `company`, `invoice`, `invoice-in`, `list-check`, `mail`, …)
  jsou už registrované.
- Změna `row.avatar` (kruhový badge s iniciálami) — pole v API zůstává,
  vykreslování v `ViewerRow.svelte` zůstává. Avatar dnes nikdo nepoužívá,
  ale neodstraňujeme ho.
- Změna chování ikon v sidebaru.

## Datový tok (high-level)

```
module.jsonc viewers[].icon
        │
        ▼
ViewerRegistry::loadFromModules()  →  ViewerDefinition::$icon
        │
        ▼
ViewerController::rows()
        │   pro každý řádek z renderRow():
        ▼
$row['icon'] ??= $def->icon          (default fallback)
        │
        ▼  JSON odpověď
        ▼
Viewer.svelte: {#each rows as row, i}
        │  předává index={i + 1}
        ▼
ViewerRow.svelte:
  ┌─ levý sloupec ────────┐
  │  {index}              │  ← malé, tlumené
  │  <Icon …>             │  ← lg, tlumené
  └───────────────────────┘
```

Fallback chain ikony pro jeden řádek:

1. `renderRow()` vrátí `'icon' => 'company'` (per-row override podle dat).
2. Jinak `ViewerController::rows()` doplní default z `ViewerDefinition::$icon`
   (= `module.jsonc` viewers[].icon).
3. Jinak frontend přes `resolveIcon(name, iconTable)` použije `iconTable`.

## Co je potřeba udělat

### 1. `ViewerDefinition` — pole `icon`

**Soubor:** `src/Core/Viewer/ViewerDefinition.php`

Přidat readonly property `?string $icon = null`:

```php
public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly string $table,
    public readonly ?string $class,
    public readonly string $moduleId,
    public readonly ?string $icon = null,
) {}
```

### 2. `ViewerRegistry::loadFromModules()` — načíst ikonu

**Soubor:** `src/Core/Viewer/ViewerRegistry.php`

V loopu nad `$module->viewers` načíst `$viewer['icon'] ?? null` a předat
do `new ViewerDefinition(…)`:

```php
$def = new ViewerDefinition(
    id: $viewer['id'],
    name: $name,
    table: $viewer['table'],
    class: $viewer['class'] ?? null,
    moduleId: $module->id,
    icon: $viewer['icon'] ?? null,
);
```

### 3. `ViewerController::rows()` — doplnit default ikonu

**Soubor:** `src/Api/Controller/ViewerController.php`

V metodě `rows()` máme dnes:

```php
$rows = [];
foreach ($rawRows as $row) {
    $rows[] = $viewer->renderRow($row);
}
```

Po `renderRow()` doplnit default ikonu (jen pokud řádek nemá vlastní).
Definici si vytáhneme z registru jednou, ne v loopu:

```php
$def = $registry->get($viewerId);
$defaultIcon = $def?->icon;

$rows = [];
foreach ($rawRows as $row) {
    $rendered = $viewer->renderRow($row);
    if (!isset($rendered['icon']) && $defaultIcon !== null) {
        $rendered['icon'] = $defaultIcon;
    }
    $rows[] = $rendered;
}
```

`$registry->get()` v `rows()` zatím nebyl volaný — přidat ho. Ostatní
metody (`meta`, `detail`) ho už volají, takže pattern je konzistentní.

### 4. `TableViewer::renderRow()` — aktualizovat docstring

**Soubor:** `src/Core/Viewer/TableViewer.php`

V PHPDoc nad abstract `renderRow()` upravit popis `icon`:

```
 * - icon: string|null
 *     Icon identifier matching a key in frontend `iconMap`
 *     (e.g. 'user', 'company', 'invoice'). When omitted, the controller
 *     fills in the viewer's default icon from module.jsonc (`viewers[].icon`).
 *     Override per-row when the icon depends on the record's data
 *     (e.g. PersonsViewer switches between 'user' and 'company' based
 *     on person_type).
```

### 5. `PersonsViewer` — ikona podle `person_type`

**Soubor:** `modules/base/persons/src/PersonsViewer.php`

V `renderRow()` přidat řádek, který nastaví `icon` podle `person_type`:

```php
// Ikona řádku: fyzická osoba (1) / neurčeno (0) → user,
// právnická osoba (2) → company (building).
$personType = (int) ($rowData['person_type'] ?? 0);
$row['icon'] = $personType === 2 ? 'company' : 'user';
```

Vložit kamkoli mezi naplnění `t1/i1/t2/t3` a `return $row;`. `module.jsonc`
už má `"icon": "user"` (zachováme pro sidebar), per-row override v
`renderRow()` ho pro samotné řádky vždy přepíše.

### 6. `ViewerRow.svelte` — nový layout levého sloupce

**Soubor:** `frontend/src/components/viewer/ViewerRow.svelte`

#### 6a) Imports

Přidat na začátek `<script>`:

```js
import Icon from '../ui/Icon.svelte';
import { resolveIcon } from '../../icons.js';
```

#### 6b) Props — přidat `index`

```js
let { row, index, selected = false, onclick } = $props();
```

Předpoklad: `Viewer.svelte` ho vždy předá. Když nebude, vykreslíme prázdné
místo (žádný hardfail).

#### 6c) Markup — nahradit dnešní `__icon` / `__avatar` blok

Dnes:

```svelte
{#if row.avatar}
  <span class="shpd-viewer-row__avatar">{row.avatar}</span>
{:else if row.icon}
  <span class="shpd-viewer-row__icon">{row.icon}</span>
{/if}
```

Nově: avatar zůstává jak je (kruhový badge má jiný layout a sémantiku);
icon blok přepracujeme na svislý levý sloupec s číslem a SVG ikonou:

```svelte
{#if row.avatar}
  <span class="shpd-viewer-row__avatar">{row.avatar}</span>
{:else}
  <span class="shpd-viewer-row__lead">
    {#if index != null}
      <span class="shpd-viewer-row__index">{index}</span>
    {/if}
    <span class="shpd-viewer-row__icon">
      <Icon icon={resolveIcon(row.icon)} size="lg" />
    </span>
  </span>
{/if}
```

Důvod, proč ikonu kreslíme i když `row.icon` chybí: `resolveIcon(undefined)`
vrátí `iconTable` — uživatel uvidí generickou ikonu tabulky místo prázdného
místa. Konzistentní s tím, jak fallback funguje v sidebaru.

#### 6d) Styly

V `<style>` nahradit dnešní `.shpd-viewer-row__icon` blok za:

```css
/* Levý sloupec — pořadové číslo nahoře, pod ním ikona.
 * Tlumená barva, aby ikona ani číslo nekonkurovaly stavovému
 * proužku ani hlavnímu textu v t1. */
.shpd-viewer-row__lead {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 32px;
  flex-shrink: 0;
  gap: 2px;
  line-height: 1.2;
}

.shpd-viewer-row__index {
  font-size: 0.7rem;
  color: var(--shpd-color-text-secondary);
  font-variant-numeric: tabular-nums;
}

.shpd-viewer-row__icon {
  color: var(--shpd-color-text-secondary);
  /* SVG dostává velikost přes `size="lg"` v <Icon>; výška 1.25em. */
}
```

`.shpd-viewer-row__avatar` zůstává nezměněné.

### 7. `Viewer.svelte` — předávat `index`

**Soubor:** `frontend/src/components/viewer/Viewer.svelte`

V `{#each rows …}` bloku (uvnitř `.shpd-viewer__rows`) přidat index parameter:

```svelte
{#each rows as row, i (row.id)}
  <ViewerRow
    {row}
    index={i + 1}
    selected={selectedRowId === row.id}
    onclick={() => handleRowClick(row)}
  />
{/each}
```

Číslování je souvislé přes celý načtený seznam — po `fetchRowsExplicit(…,
append=true)` se k existujícím řádkům prostě append-ují další, index
plyne dál (51, 52, …). Po změně tabu / filtru / hledání se `rows` resetují,
číslování začne znovu od 1. To je požadované chování.

### 8. Dokumentace — `docs/frontend.md`

**Soubor:** `docs/frontend.md`, sekce **7. Viewer systém → Formát řádku
(`renderRow()`)**.

Doplnit popis pole `icon`:

```
Pole `icon` (string, optional) — identifikátor ikony z `iconMap`
(`user`, `company`, `invoice`, …). Když ho `renderRow()` nevrátí,
backend doplní default z `module.jsonc` (`viewers[].icon`). Frontend
přes `resolveIcon()` přeloží na FA icon definition, fallback `iconTable`.
```

Doplnit poznámku o pořadovém čísle (klidně nový pododstavec):

```
**Pořadové číslo** v každém řádku je čistě frontend záležitost —
`Viewer.svelte` ho počítá z pozice v poli `rows` (1, 2, 3, … souvisle
přes celý načtený seznam). Při infinite scrollu pokračuje (50 → 51 → …),
při změně tabu / filtru / hledání reset na 1.
```

### 9. Dokumentace — `CLAUDE.md`

**Soubor:** `CLAUDE.md`, sekce **Frontend — ikony**.

Doplnit větu o sdílení ikony s viewer řádky:

```
- Viewery dědí default ikonu pro řádky z `module.jsonc` viewers[].icon
  (stejná jako v sidebaru). Per-row override v `renderRow()`
  (např. PersonsViewer podle person_type).
```

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů
- `vendor/bin/phpunit 2>&1` projde
- Manuální smoke test (`http://{ip}/{ds-id}/app/`):
  - Faktury přijaté → každý řádek má vlevo dole ikonu faktury a
    nad ní pořadové číslo (1, 2, 3, …)
  - Osoby → fyzické mají user ikonu, právnické building ikonu
  - Úkoly → každý řádek má list-check ikonu
  - Infinite scroll: po načtení další stránky čísla pokračují (51, 52, …)
  - Přepnutí tabu (Aktivní → Archív): čísla resetují na 1
  - Hledání: čísla resetují na 1 pro filtrované výsledky
- Sidebar ikony se nezměnily (žádný regression v navigaci)

## Rozhodnutí k designu (potvrzená)

- ✓ **Default ikona z `module.jsonc`** — žádné duplicování v PHP třídách.
  Per-row override v `renderRow()` jen tam, kde ikona závisí na datech.
- ✓ **Pořadové číslo souvislé přes celý načtený seznam** (1, 2, 3, …),
  pokračuje při infinite scrollu, reset při změně tabu / filtru / hledání.
- ✓ **Layout levého sloupce**: číslo nahoře malé tlumené, pod ním ikona
  (jako stará aplikace). Šířka sloupce 32 px, zarovnání center.
- ✓ **Osoba s `person_type=0` (Neurčeno)** → ikona `user` (= default,
  stejně jako fyzická osoba).
- ✓ **Ikona velikost `lg`** (1.25em) — proporčně sedí ke sloupci 32 px.
- ✓ **Ikona barva tlumená** (`--shpd-color-text-secondary`) — hierarchie
  v řádku zůstává: stavový proužek (upozornění) → ikona (orientace) → t1
  (hlavní obsah).
- ✓ **Fallback chain**: `renderRow()` override → `ViewerDefinition::$icon`
  (z `module.jsonc`) → frontend `iconTable` (z `resolveIcon`).
- ✓ **Avatar zachován** — pole `row.avatar` v API a vykreslování v
  `ViewerRow.svelte` zůstává. Dnes ho nikdo nepoužívá, ale neodstraňujeme.
  Když řádek dostane `avatar`, vykreslí se kruhový badge a levý sloupec
  s číslem+ikonou se neukáže (existující chování).

## Mimo rozsah / nezasahujeme

- `Icon.svelte`, `icons.js`, `iconMap` — žádná změna, všechny potřebné
  ikony jsou registrované.
- Sidebar — žádná změna.
- `row.avatar` — necháváme jak je.
- Pořadové číslo na backendu — záměrně počítáme jen frontendově,
  backend o něm neví. Když by někdy bylo potřeba globální offset
  (pageNumber × pageSize + index), přidá se to později.
