# Task: Editační formuláře — Fáze 3 (Frontend)

**Stav:** hotovo

## Kontext

Backend je hotový (Fáze 1 + 2). Nyní implementujeme frontend pro nový systém formulářů.

Přečti si před začátkem:
- `docs/edit-forms.md` — PRD, zejména sekce 4 (elementy), 5 (doc states), 6 (grid), 7 (recalculate), 8 (validace), 9 (layout FormEditor), 14 (FormSubTable), 15 (komponenty), 19 (fullSize)
- Stávající komponenty jako vzor stylu a konvencí:
  - `frontend/src/components/form/FormRenderer.svelte` — stávající jednoduchý renderer
  - `frontend/src/components/form/FormField.svelte` — mapování DB typů na UI komponenty
  - `frontend/src/components/viewer/Viewer.svelte` — Svelte 5 runes vzor, fetch pattern
  - `frontend/src/components/ui/Modal.svelte` — modal implementace
  - `frontend/src/components/ui/Button.svelte` — Button API
  - `frontend/src/styles/variables.css` — CSS proměnné

## Konvence

- **Svelte 5 runes** — `$state`, `$derived`, `$effect`, `$props()`. Žádné legacy `export let`.
- **Callback props** místo custom events — `onSave`, `onClose`, `onAction` atd.
- **CSS** — BEM-like naming, `--shpd-*` proměnné, scoped styles v každé komponentě.
- **JS (ne TS)** v nových souborech, konzistentní se stávajícím kódem.
- **API volání** vždy přes `get`, `post`, `put`, `del` z `../../api/client.js`.
- `$effect` nesmí synchronně číst `$state` proměnné, které se nemají sledovat — fetch funkce přijímají parametry explicitně.

## Nové komponenty — přehled

```
frontend/src/components/form/
├── FormEditor.svelte       ← hlavní shell formuláře
├── FormTab.svelte          ← obsah jednoho tabu (grid + elementy)
├── FormElement.svelte      ← renderer jednoho elementu
├── FormSubTable.svelte     ← editor sub-tabulky (tabulka + CRUD)
├── FormStateBar.svelte     ← spodní toolbar (Uložit + doc state tlačítka)
├── FormStateBadge.svelte   ← badge stavu v záhlaví ([KONCEPT ●])
│
│   (stávající — beze změny)
├── FormField.svelte
├── FormRenderer.svelte
└── FormDialog.svelte       ← AKTUALIZOVAT (viz sekce níže)
```

---

## 1. `FormElement.svelte`

Dynamický renderer jednoho elementu formuláře ze serveru.

### Props

```js
let {
  element,      // objekt elementu z FormDefinition (type, column, cols, label, hidden, ...)
  formData,     // $bindable — celý objekt dat formuláře { column_id: value, ... }
  fieldErrors,  // Record<string, string> — chyby z validace
  disabled,     // bool — disabled stav (ukládání, readOnly formulář)
  onTrigger,    // callback(columnId) — zavolá se při změně triggering pole
} = $props();
```

### Logika renderování podle `element.type`

**`input`** — podle typu DB sloupce (odvozený ze serveru NENÍ k dispozici, typ inputu odvoď z hodnoty a z `element.column` — viz níže). Jednodušší přístup: server vrací `type: "input"` pro všechno mimo select/separator/group/subtable/html. Klient renderuje `<Input>` jako výchozí, ale pro speciální sloupce použij správnou komponentu:
- Pokud `element.column` končí `_date` nebo obsahuje `date` a hodnota vypadá jako datum → `<DateInput>`
- Pokud hodnota je boolean/číslo se step → příslušná komponenta
- **Správnější přístup**: server by měl posílat `input_type` hint. Protože ho zatím neposílá, pro Fázi 3 použij `<Input type="text">` jako fallback pro neznámé — stávající `FormField.svelte` dělá mapování z DB typů, ale FormElement.svelte pracuje s FormDefinition elementy, ne s DB metadaty.

> **Poznámka k input_type**: Přidej do `FormElement` PHP třídy (backend) pole `inputType: ?string` — server ho vyplní z DB typu sloupce (`date`, `datetime`, `number`, `checkbox`, `textarea`). Pak ho frontend použije. Viz sekce Backend fixup níže.

**`select`** — `<Select>` s `element.options`

**`separator`** — `<div class="shpd-form-separator"><span>{element.label}</span></div>` — horizontální linka s textem, `grid-column: 1 / -1`

**`group`** — rekurzivní vnořený grid s nadpisem, renderuje `element.elements` rekurzivně přes `<FormElement>`

**`subtable`** — `<FormSubTable>` (viz níže)

**`html`** — `{@html element.content}` obalený v `<div class="shpd-form-html">`

### Skrytí

Pokud `element.hidden === true`, renderuj `display: none` (ne `{#if}` — aby form data zůstala v `formData` i pro skrytá pole).

### Trigger

Pokud `element.triggers === 'reload'`, po změně hodnoty zavolej `onTrigger(element.column)`.

### Grid span

Každý element dostane `style="grid-column: span {element.cols}"`. Výjimky: `separator` a `group` s `cols: 4` → `grid-column: 1 / -1`.

### Cols na breakpointech

Řeší CSS — na tabletu `span min(cols, 2)`, na mobilu vždy 1. Implementuj přes CSS třídy:
- `shpd-form-el--cols-1`, `shpd-form-el--cols-2`, `shpd-form-el--cols-3`, `shpd-form-el--cols-4`

---

## 2. `FormTab.svelte`

Obsah jednoho tabu — CSS Grid 4 sloupce, iteruje elementy.

### Props

```js
let {
  tab,          // { id, label, elements: [...] }
  formData,     // $bindable
  fieldErrors,
  disabled,
  onTrigger,    // callback(columnId)
  hasError,     // bool — má tento tab validační chybu? (pro indikátor v tab baru)
} = $props();
```

### Layout

```css
.shpd-form-tab {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--shpd-space-md);
  padding: var(--shpd-space-lg);
  align-items: start;
}

@media (max-width: 899px) {
  .shpd-form-tab { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 599px) {
  .shpd-form-tab { grid-template-columns: 1fr; }
}
```

Iteruje `tab.elements` a renderuje `<FormElement>` pro každý.

---

## 3. `FormStateBadge.svelte`

Badge stavu dokumentu v záhlaví formuláře.

### Props

```js
let { docStates } = $props();
// docStates: { currentState, stateName, stateStyle, readOnly, transitions } | null
```

### Render

Pokud `docStates` je null, nic nerenderi.

```html
<span class="shpd-form-state-badge docState_{docStates.stateStyle}">
  {docStates.stateName}
</span>
```

CSS třídy `docState_*` jsou definovány globálně (přidej do `base.css` nebo do komponenty jako `:global`):

```css
:global(.docState_concept)   { background: #fef3c7; color: #92400e; }
:global(.docState_done)      { background: #dcfce7; color: #166534; }
:global(.docState_edit)      { background: #ffedd5; color: #9a3412; }
:global(.docState_confirmed) { background: #dbeafe; color: #1e40af; }
:global(.docState_archive)   { background: #f1f5f9; color: #475569; }
:global(.docState_trash)     { background: #f1f5f9; color: #475569; text-decoration: line-through; }
:global(.docState_cancelled) { background: #fee2e2; color: #991b1b; }
```

---

## 4. `FormStateBar.svelte`

Spodní toolbar formuláře — tlačítko Uložit + přechodová tlačítka doc states.

### Props

```js
let {
  docStates,    // null nebo objekt se transitions[]
  saving,       // bool
  onSave,       // callback() — uložit záznam
  onTransition, // callback(targetState: number) — přejít do stavu
} = $props();
```

### Render

```
[Uložit]   [Opravit]  [Ukončit platnost]  [Smazat]
```

- **Tlačítko Uložit**: zobrazit pokud `!docStates || !docStates.readOnly`. Variant `primary`. Při `saving` — loading stav.
- **Přechodová tlačítka**: ze `docStates.transitions` — každé volá `onTransition(transition.state)`. Variant podle `stateStyle`:
  - `done` → `primary`
  - `archive`, `trash`, `cancelled` → `danger`
  - ostatní → `secondary`

Toolbar je fixní dole (`position: sticky; bottom: 0`) s `background: var(--shpd-color-bg)` a `border-top`.

---

## 5. `FormEditor.svelte`

Hlavní shell formuláře. Načítá FormDefinition z API, řídí taby, recalculate, ukládání, doc states.

### Props

```js
let {
  table,          // string — ID tabulky
  recordId,       // number | null — null = nový záznam
  onClose,        // callback() — zavřít formulář
  onSaved,        // callback(record) — záznam byl uložen
} = $props();
```

### State

```js
let formDef = $state(null);       // FormDefinition ze serveru
let formData = $state({});        // aktuální data formuláře
let fieldErrors = $state({});     // validační chyby { column_id: message }
let activeTabId = $state(null);   // ID aktivního tabu
let saving = $state(false);
let recalculating = $state(false);
let loadError = $state(null);
```

### Load

```js
async function loadForm(tbl, id) {
  const path = id != null
    ? `/_ui/form/${tbl}/meta/${id}`
    : `/_ui/form/${tbl}/meta`;
  const res = await get(path);
  if (!res?.success) { loadError = ...; return; }
  formDef = res.formDefinition;
  formData = res.data ?? buildDefaultData(formDef);
  activeTabId = formDef.tabs[0]?.id ?? null;
}
```

`buildDefaultData(formDef)` projde všechny elementy ve všech tabech a nastaví prázdné výchozí hodnoty pro sloupce (empty string nebo null).

### Recalculate

Volá se z `onTrigger(columnId)` z `FormElement`:

```js
async function handleTrigger(columnId) {
  recalculating = true;
  const res = await post(`/_ui/form/${table}/recalculate`, {
    id: recordId ?? null,
    changedColumn: columnId,
    data: formData,
  });
  if (res?.success) {
    formDef = res.formDefinition;
    formData = res.data;
    // Zachovat aktivní tab pokud existuje v nové definici, jinak první tab
    const tabIds = formDef.tabs.map(t => t.id);
    if (!tabIds.includes(activeTabId)) activeTabId = tabIds[0] ?? null;
  }
  recalculating = false;
}
```

### Uložení

```js
async function handleSave() {
  saving = true;
  fieldErrors = {};
  const isNew = recordId == null;
  const res = isNew
    ? await post(`/_ui/form/${table}/save`, formData)
    : await put(`/_ui/form/${table}/save/${recordId}`, formData);

  if (res?.success) {
    onSaved?.(res.data);
    // Po uložení reload formuláře (načte nová data + případně nový doc state)
    await loadForm(table, res.id ?? recordId);
  } else if (res?.errors) {
    // Mapuj chyby na pole
    const errs = {};
    for (const e of res.errors) {
      errs[e.column] = e.message;
    }
    fieldErrors = errs;
    // Přepni na tab s první chybou
    switchToErrorTab(errs);
  } else {
    loadError = res?.error?.message ?? 'Nepodařilo se uložit záznam.';
  }
  saving = false;
}
```

### Přepnutí na tab s chybou

```js
function switchToErrorTab(errs) {
  // Sestavit mapu column → tabId ze všech tabů
  const colToTab = {};
  for (const tab of formDef.tabs) {
    for (const el of flatElements(tab.elements)) {
      if (el.column) colToTab[el.column] = tab.id;
    }
  }
  // Najít první chybu podle pořadí tabů
  for (const tab of formDef.tabs) {
    for (const col of Object.keys(errs)) {
      if (colToTab[col] === tab.id) { activeTabId = tab.id; return; }
    }
  }
}

// Rekurzivně rozbalí elementy (group má vnořené elements)
function flatElements(elements) {
  return elements.flatMap(el =>
    el.type === 'group' ? flatElements(el.elements ?? []) : [el]
  );
}
```

### Přechod doc state

```js
async function handleTransition(targetState) {
  saving = true;
  fieldErrors = {};
  const res = await put(`/_ui/form/${table}/save/${recordId}`, { docState: targetState });
  if (res?.success) {
    await loadForm(table, recordId);
  } else {
    loadError = res?.error?.message ?? 'Nepodařilo se změnit stav.';
  }
  saving = false;
}
```

### Layout HTML

```html
<div class="shpd-form-editor" class:shpd-form-editor--loading={!formDef}>

  <!-- Záhlaví -->
  <div class="shpd-form-editor__header">
    <button class="shpd-form-editor__back" onclick={onClose}>←</button>
    <h2 class="shpd-form-editor__title">
      {recordId != null ? formDef?.title : formDef?.title_new ?? 'Nový záznam'}
    </h2>
    {#if formDef?.doc_states}
      <FormStateBadge docStates={formDef.doc_states} />
    {/if}
  </div>

  <!-- Tab bar (jen pokud > 1 tab) -->
  {#if formDef && formDef.tabs.length > 1}
    <div class="shpd-form-editor__tab-bar">
      {#each formDef.tabs as tab (tab.id)}
        <button
          class="shpd-form-editor__tab"
          class:shpd-form-editor__tab--active={activeTabId === tab.id}
          class:shpd-form-editor__tab--error={tabHasError(tab.id)}
          onclick={() => activeTabId = tab.id}
        >
          {tab.label}
          {#if tabHasError(tab.id)}
            <span class="shpd-form-editor__tab-error-dot" aria-hidden="true"></span>
          {/if}
        </button>
      {/each}
    </div>
  {/if}

  <!-- Obsah aktivního tabu -->
  <div class="shpd-form-editor__content">
    {#if loadError}
      <div class="shpd-form-editor__error-banner">{loadError}</div>
    {/if}

    {#if !formDef}
      <div class="shpd-form-editor__loading">Načítám…</div>
    {:else}
      {#each formDef.tabs as tab (tab.id)}
        <div class="shpd-form-editor__tab-content" hidden={tab.id !== activeTabId}>
          <FormTab
            {tab}
            bind:formData
            {fieldErrors}
            disabled={saving || recalculating || (formDef.doc_states?.read_only ?? false)}
            onTrigger={handleTrigger}
          />
        </div>
      {/each}
    {/if}
  </div>

  <!-- Spodní toolbar -->
  {#if formDef}
    <FormStateBar
      docStates={formDef.doc_states ?? null}
      {saving}
      onSave={handleSave}
      onTransition={handleTransition}
    />
  {/if}

</div>
```

Poznámka: používám `hidden` atribut místo `{#if}` pro tab content — všechna pole jsou v DOM i pro neaktivní taby (důležité pro validaci a recalculate sbírání dat).

### `tabHasError(tabId)`

```js
function tabHasError(tabId) {
  const errCols = new Set(Object.keys(fieldErrors));
  const tab = formDef?.tabs.find(t => t.id === tabId);
  if (!tab) return false;
  return flatElements(tab.elements).some(el => el.column && errCols.has(el.column));
}
```

### CSS

```css
.shpd-form-editor {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  background: var(--shpd-color-bg);
}

.shpd-form-editor__header {
  display: flex;
  align-items: center;
  gap: var(--shpd-space-md);
  padding: var(--shpd-space-sm) var(--shpd-space-lg);
  border-bottom: 1px solid var(--shpd-color-border);
  flex-shrink: 0;
}

.shpd-form-editor__back {
  background: none;
  border: none;
  font-size: var(--shpd-font-size-lg);
  cursor: pointer;
  color: var(--shpd-color-text-secondary);
  padding: var(--shpd-space-xs);
  border-radius: var(--shpd-radius-sm);
}
.shpd-form-editor__back:hover { background: var(--shpd-color-bg-secondary); color: var(--shpd-color-text); }

.shpd-form-editor__title {
  flex: 1;
  font-size: var(--shpd-font-size-lg);
  font-weight: 600;
  margin: 0;
}

/* Tab bar */
.shpd-form-editor__tab-bar {
  display: flex;
  border-bottom: 1px solid var(--shpd-color-border);
  flex-shrink: 0;
  overflow-x: auto;
}

.shpd-form-editor__tab {
  position: relative;
  padding: var(--shpd-space-sm) var(--shpd-space-md);
  border: none;
  border-bottom: 2px solid transparent;
  background: none;
  font-family: inherit;
  font-size: var(--shpd-font-size-sm);
  color: var(--shpd-color-text-secondary);
  cursor: pointer;
  white-space: nowrap;
  transition: color 0.12s, border-color 0.12s;
}
.shpd-form-editor__tab:hover { color: var(--shpd-color-text); }
.shpd-form-editor__tab--active {
  color: var(--shpd-color-primary);
  border-bottom-color: var(--shpd-color-primary);
  font-weight: 600;
}
.shpd-form-editor__tab--error { color: var(--shpd-color-danger); }
.shpd-form-editor__tab--error.shpd-form-editor__tab--active { border-bottom-color: var(--shpd-color-danger); }

.shpd-form-editor__tab-error-dot {
  display: inline-block;
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--shpd-color-danger);
  margin-left: 4px;
  vertical-align: middle;
}

/* Content */
.shpd-form-editor__content {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

.shpd-form-editor__loading {
  padding: var(--shpd-space-lg);
  color: var(--shpd-color-text-secondary);
  font-size: var(--shpd-font-size-sm);
}

.shpd-form-editor__error-banner {
  margin: var(--shpd-space-md);
  padding: var(--shpd-space-sm) var(--shpd-space-md);
  background: #fef2f2;
  border: 1px solid var(--shpd-color-danger);
  border-radius: var(--shpd-radius-md);
  color: var(--shpd-color-danger);
  font-size: var(--shpd-font-size-sm);
}
```

### `$effect` pro načtení

```js
$effect(() => {
  const tbl = table;
  const id = recordId;
  loadForm(tbl, id);
});
```

---

## 6. `FormSubTable.svelte`

Editor sub-tabulky — zobrazí řádky a umožní Přidat / Upravit / Smazat.

### Props

```js
let {
  element,      // FormElement (type: 'subtable', table, foreignKey, formId)
  parentId,     // number | null — ID rodičovského záznamu
  disabled,
} = $props();
```

### Chování

- Pokud `parentId === null` → zobrazit info: „Nejprve uložte záznam, poté budete moci přidávat záznamy."
- Pokud `parentId !== null` → načíst řádky z `GET /{element.table}?filter[{element.foreignKey}]=eq:{parentId}&sort=order_pos:asc`
- Tlačítko **Přidat** → otevře `FormDialog` s `table=element.table`, `recordId=null`, předá `parentId` jako defaultní hodnotu FK (viz níže)
- Ikona ✎ na řádku → otevře `FormDialog` s daným `recordId`
- Ikona ✕ na řádku → potvrzovací dialog → `DELETE /{element.table}/{id}` → reload řádků

### Jak předat parentId do dialogu

`FormDialog` dostane nový prop `defaultData: Record<string, unknown>` — při otevírání nového záznamu se nastaví `{ [element.foreignKey]: parentId }` jako výchozí data formuláře. `FormEditor` při `buildDefaultData` přednostně použije hodnoty z `defaultData`.

### Tabulka sub-záznamů

Jednoduché HTML `<table>` — sloupce se odvozují z dat prvního načteného řádku (nebo z definice — zjednodušeně zobraz max 4–5 nejdůležitějších sloupců, viz níže).

Protože nemáme definici sloupců pro zobrazení, použij heuristiku:
1. Načti řádky z API
2. Z prvního řádku vezmi klíče, vyfiltruj `id`, `person`, FK sloupec, `order_pos`, `created`, `modified`
3. Zbývající klíče zobraz jako sloupce (max 5)

### Reload po uložení sub-záznamu

Po `onSaved` callbacku z `FormDialog` zavolej znovu fetch řádků.

---

## 7. Aktualizace `FormDialog.svelte`

`FormDialog` musí umět otevřít buď starý `FormRenderer` nebo nový `FormEditor` podle `fullSize` flagu.

### Nová logika

```js
let {
  table,
  recordId = null,
  open,
  onClose,
  onSaved,
  defaultData = {},    // nový prop — výchozí data pro nový záznam
} = $props();

// Načti fullSize flag z /meta
let fullSize = $state(false);
let metaLoaded = $state(false);

async function checkFullSize(tbl, id) {
  const path = id != null ? `/_ui/form/${tbl}/meta/${id}` : `/_ui/form/${tbl}/meta`;
  const res = await get(path);
  if (res?.success) {
    fullSize = res.formDefinition?.full_size ?? false;
  }
  metaLoaded = true;
}

$effect(() => {
  if (open) {
    metaLoaded = false;
    fullSize = false;
    checkFullSize(table, recordId);
  }
});
```

Při `fullSize === true` — otevři `FormEditor` jako full-size overlay (ne Modal):

```html
{#if open}
  {#if !metaLoaded}
    <!-- čekání na meta — nic nezobrazuj nebo minimální spinner -->
  {:else if fullSize}
    <!-- Full-size overlay přes celou ContentArea -->
    <div class="shpd-form-fullsize-overlay">
      <FormEditor
        {table}
        {recordId}
        onClose={handleClose}
        onSaved={handleSaved}
      />
    </div>
  {:else}
    <!-- Standardní modální dialog -->
    <Modal title={modalTitle} {open} {onClose} width="720px">
      <FormEditor
        {table}
        {recordId}
        onClose={handleClose}
        onSaved={handleSaved}
      />
    </Modal>
  {/if}
{/if}
```

Full-size overlay CSS:
```css
.shpd-form-fullsize-overlay {
  position: fixed;
  inset: 0;
  z-index: 500;
  background: var(--shpd-color-bg);
  display: flex;
  flex-direction: column;
}
```

`handleSaved` zavolá `onSaved?.(record)` a zavře dialog (`handleClose`).  
`handleClose` zavolá `onClose()` a nastaví `metaLoaded = false`.

### Předání defaultData do FormEditor

`FormEditor` dostane nový prop `defaultData = {}`. V `buildDefaultData` funkci použij hodnoty z `defaultData` pro inicializaci — slouží pro FK hodnotu při otevírání sub-záznamu.

---

## 8. Backend fixup — `inputType` v `FormElement`

Aby `FormElement.svelte` věděl jak renderovat `input` pole (text/date/number/checkbox/textarea), přidej do PHP `FormElement` třídy pole `inputType` a naplň ho v `AutoFormBuilder` a `TabBuilder`.

### `src/Core/Form/FormElement.php`

Přidej property:
```php
public readonly ?string $inputType = null,  // 'text'|'number'|'date'|'datetime'|'time'|'checkbox'|'textarea'|null
```

A v `toArray()`:
```php
if ($this->inputType !== null) {
    $result['input_type'] = $this->inputType;
}
```

### `src/Core/Form/TabBuilder.php`

`addInput()` dostane nový volitelný parametr `?string $inputType = null`. Předej ho do `FormElement`.

### `src/Core/Form/AutoFormBuilder.php`

V metodě `buildElement()` odvoď `inputType` z `$col->type`:

```php
private function deriveInputType(ColumnDefinition $col): ?string
{
    return match($col->type) {
        'boolean'           => 'checkbox',
        'date'              => 'date',
        'datetime'          => 'datetime',
        'time'              => 'time',
        'text', 'longtext'  => 'textarea',
        'int', 'smallint', 'bigint', 'tinyint', 'numeric', 'float' => 'number',
        default             => 'text',
    };
}
```

Předej výsledek jako `inputType` do `FormElement`.

### `src/Core/Form/JsoncFormLoader.php`

Při `buildElement()`, pokud JSONC neobsahuje `input_type`, odvoď ho z `$col->type` stejnou logikou.

### `modules/base/persons/src/PersonsForm.php`

`TabBuilder::addInput()` nyní přijímá `inputType`. Pro pole jako `birth_date`, `valid_from`, `valid_to`, `closed_date` předej `inputType: 'date'`. Pro `is_closed` předej `inputType: 'checkbox'`. Pro ostatní textová pole není potřeba specifikovat (AutoFormBuilder to odvodí).

### Frontend `FormElement.svelte` — mapování `input_type`

```js
// Render podle element.input_type
switch (element.input_type) {
  case 'checkbox': render <Checkbox>
  case 'date':     render <DateInput>
  case 'datetime': render <Input type="datetime-local">
  case 'time':     render <Input type="time">
  case 'textarea': render <TextArea>
  case 'number':   render <NumberInput step={1}>
  default:         render <Input type="text">
}
```

---

## 9. Integrace do `Viewer.svelte`

`Viewer.svelte` aktuálně otevírá `FormDialog` přímo. To zůstane — `FormDialog` teď sám rozhodne zda fullSize nebo modal. Žádná změna v `Viewer.svelte` není nutná.

---

## Adresářová struktura po dokončení

```
frontend/src/components/form/
├── FormEditor.svelte       ← nový
├── FormTab.svelte          ← nový
├── FormElement.svelte      ← nový
├── FormSubTable.svelte     ← nový
├── FormStateBar.svelte     ← nový
├── FormStateBadge.svelte   ← nový
├── FormDialog.svelte       ← aktualizován (fullSize logika, defaultData prop)
├── FormField.svelte        ← beze změny
└── FormRenderer.svelte     ← beze změny

src/Core/Form/
├── FormElement.php         ← přidán inputType
├── TabBuilder.php          ← přidán inputType param do addInput()
├── AutoFormBuilder.php     ← doplní inputType z DB typu
└── JsoncFormLoader.php     ← doplní inputType z DB typu

modules/base/persons/src/
└── PersonsForm.php         ← doplnit inputType pro date/checkbox pole
```

---

## Hotovo když

- [ ] `FormDialog` otevřený z Vieweru pro Osoby se zobrazí jako full-size overlay (ne modal)
- [ ] `FormDialog` otevřený pro sub-záznam (Kontakt) se zobrazí jako modální dialog
- [ ] Formulář Osob má 5 tabů, grid 4 sloupce, scrollovatelný obsah, fixní toolbar dole
- [ ] Přepnutí `person_type` spustí recalculate — pole se skryjí/zobrazí bez reloadu stránky
- [ ] `full_name` se přepočítá automaticky po přepnutí na Fyzická osoba
- [ ] Validační chyba na neaktivním tabu → automatický přepínač tabu + červený indikátor
- [ ] Badge stavu dokumentu se zobrazí v záhlaví formuláře
- [ ] Formulář ve stavu „V pořádku" (readOnly) má všechna pole disabled, tlačítko Uložit skryto
- [ ] Tlačítko přechodu stavu funguje (např. „Opravit" přepne na V opravě a odemkne formulář)
- [ ] Sub-tabulky (Kontakty, Adresy, BÚ) zobrazí řádky a umožní Přidat/Upravit/Smazat
- [ ] Nový záznam → taby se sub-tabulkami jsou disabled s info textem
