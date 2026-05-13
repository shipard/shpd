# Task: Nový layout system editačních formulářů — PR 1

Implementace nového layout systému editačních formulářů (sekce, sloupce, label-left, auto-šířka labelů). Tento PR zahrnuje:

- nový JSON wire format (sekce → sloupce → elementy)
- refaktoring frontend input komponent (label vně, ne uvnitř)
- vizuální „karty s pozadím" pro sekce
- mechanický port všech 4 existujících formulářů + AutoFormBuilder
- showcase port `base_persons_contacts.jsonc` na čistý nový formát
- aktualizace všech relevantních testů

**Důležité: čistý break, žádná backward compatibility.** Starý formát s `cols: 1..4` na úrovni tabu, `group`, a labely uvnitř input komponent se kompletně odstraní.

---

## 1. Nový JSON wire format

Server posílá z `/_ui/form/{table}/meta[/{id}]` toto:

```jsonc
{
    "success": true,
    "formDefinition": {
        "table": "base_persons_persons",
        "title": "Osoba",
        "title_new": "Nová osoba",
        "full_size": true,
        "tabs": [
            {
                "id": "basic",
                "label": "Základní údaje",
                "icon": null,
                "type": "fields",
                "sections": [
                    {
                        "title": null,
                        "hidden": false,
                        "columns": [
                            {
                                "elements": [
                                    {"type": "input", "column": "person_id", "label": "ID", "input_type": "text", "required": true},
                                    {"type": "select", "column": "person_type", "label": "Typ", "options": [...], "triggers": "reload"}
                                ]
                            }
                        ]
                    },
                    {
                        "title": "Identifikace firmy",
                        "hidden": false,
                        "columns": [
                            {"elements": [{"type": "input", "column": "company_id", "label": "IČO"}]},
                            {"elements": [{"type": "input", "column": "tax_id", "label": "DIČ"}]}
                        ]
                    },
                    {
                        "title": "Termíny",
                        "columns": [{"elements": [
                            {"type": "inline", "elements": [
                                {"type": "input", "column": "date_tax", "label": "DUZP", "input_type": "date"},
                                {"type": "input", "column": "date_tax_duty", "label": "DPPD", "input_type": "date"}
                            ]}
                        ]}]
                    }
                ]
            },
            {
                "id": "contacts",
                "label": "Kontakty",
                "type": "subtable",
                "subtable": {"table": "...", "foreign_key": "...", "form_id": "..."}
            },
            {
                "id": "attachments",
                "label": "Přílohy",
                "type": "attachments",
                "table_id": 110
            }
        ],
        "doc_states": null
    },
    "data": {...}
}
```

**Pravidla:**

- `tab.type` je `"fields"` (default, má `sections[]`), `"subtable"` (má `subtable: {...}`), nebo `"attachments"` (má `table_id`).
- `section.columns[]` má vždy ≥ 1 prvek. Jednosloupcová sekce = `columns: [{elements: [...]}]`.
- Element může být `input`, `select`, `separator`, `inline`, `subtable`, `component`, `html`.
- `inline.elements[]` jsou jen `input` nebo `select` (žádné nesting).
- **Pryč:** `cols: 1..4` na elementu (šířka teď určuje sloupec sekce). `group` typ. `subtable` jako element uvnitř tabu (subtable je teď tab typu `"subtable"`).
- Všechny klíče v JSONu jsou **snake_case** (`title_new`, `full_size`, `read_only`, `input_type`, `foreign_key`, `form_id`, `table_id`).

---

## 2. Phase A — Backend

### 2.1 Nové datové třídy

Nahraď `src/Core/Form/FormDefinition.php`, `FormTab.php`, `FormElement.php` a přidej `FormSection.php`, `FormColumn.php`:

**`FormDefinition`** (nezměněna struktura, jen `tabs` jsou `FormTab[]` v novém pojetí):

```php
final class FormDefinition {
    public function __construct(
        public readonly string $table,
        public readonly string $title,
        public readonly string $titleNew,
        public readonly array $tabs,           // FormTab[]
        public readonly bool $fullSize = false,
        public ?array $docStates = null,
    ) {}
    public function withDocStates(array $docStatesInfo): static { ... }
    public function toArray(): array { ... }
}
```

**`FormTab`** rozšířená o `sections`:

```php
final class FormTab {
    /**
     * @param FormSection[] $sections (jen pro type='fields')
     * @param ?array $subtable {table, foreignKey, formId} pro type='subtable'
     * @param ?int $tableId pro type='attachments'
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $sections = [],
        public readonly string $type = 'fields',
        public readonly ?array $subtable = null,
        public readonly ?int $tableId = null,
        public readonly ?string $icon = null,
    ) {
        // Validace: type=fields → sections nesmí být prázdné
        // type=subtable → subtable musí být zadané
        // type=attachments → tableId musí být zadané
    }
    public function toArray(): array { ... }
}
```

**`FormSection`** (nová):

```php
final class FormSection {
    /**
     * @param FormColumn[] $columns (≥ 1)
     */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $title = null,
        public readonly bool $hidden = false,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException('FormSection requires at least one column');
        }
    }
    public function toArray(): array { ... }
}
```

**`FormColumn`** (nová):

```php
final class FormColumn {
    /** @param FormElement[] $elements */
    public function __construct(
        public readonly array $elements,
    ) {}
    public function toArray(): array { ... }
}
```

**`FormElement`** zjednodušený — bez `cols`, bez `group` typu, ale s `inline` typem:

```php
final class FormElement {
    public const ALLOWED_TYPES = ['input', 'select', 'separator', 'inline', 'subtable', 'component', 'html'];
    public const ALLOWED_INPUT_TYPES = [null, 'text', 'email', 'tel', 'url', 'password',
                                         'number', 'checkbox', 'date', 'datetime', 'time', 'textarea'];

    public function __construct(
        public readonly string $type,
        public readonly ?string $column = null,
        public readonly ?string $label = null,
        public readonly ?string $placeholder = null,
        public readonly bool $required = false,
        public readonly bool $readOnly = false,
        public readonly bool $hidden = false,
        public readonly ?string $triggers = null,
        public readonly ?string $hint = null,
        public readonly ?array $options = null,
        public readonly ?array $elements = null,    // pro inline: FormElement[]
        public readonly ?string $content = null,    // pro html
        public readonly ?string $componentName = null, // pro component
        public readonly ?string $inputType = null,
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid element type '$type'");
        }
        if ($type === 'input' && !in_array($inputType, self::ALLOWED_INPUT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid inputType '$inputType'");
        }
        if ($type === 'inline' && ($elements === null || $elements === [])) {
            throw new \InvalidArgumentException('inline element requires non-empty elements[]');
        }
    }
    public function toArray(): array { ... }
}
```

`toArray()` všech tříd produkuje snake_case JSON přesně dle wire formátu z bodu 1.

### 2.2 Nový `TabBuilder` (kompletní přepis)

`src/Core/Form/TabBuilder.php` se přepíše. Veřejné API:

```php
final class TabBuilder
{
    public function __construct(string $id, string $label, array $colLabels = [], ?string $icon = null) { ... }
    
    // --- Section/column management ---
    public function section(?string $title = null, bool $hidden = false): static;
    public function col(): static;
    
    // --- Elements (přidávají se do aktuálního sloupce) ---
    public function input(string $column, ?string $label = null, bool $required = false,
        ?string $triggers = null, bool $readOnly = false, bool $hidden = false,
        ?string $placeholder = null, ?string $hint = null, ?string $inputType = null): static;
    public function textarea(string $column, ?string $label = null, bool $required = false,
        bool $readOnly = false, bool $hidden = false, ?string $hint = null): static;
    public function date(string $column, ?string $label = null, bool $required = false,
        bool $readOnly = false, bool $hidden = false, ?string $hint = null,
        ?string $triggers = null): static;
    public function datetime(string $column, ...): static;
    public function time(string $column, ...): static;
    public function number(string $column, ...): static;
    public function checkbox(string $column, ...): static;
    public function select(string $column, ?string $label = null, ?array $options = null,
        ?string $triggers = null, bool $required = false, bool $readOnly = false,
        bool $hidden = false): static;
    public function separator(?string $label = null, bool $hidden = false): static;
    public function html(string $content): static;
    public function component(string $name): static;
    
    // --- Inline group ---
    public function inline(): static;             // přepne do "inline mode" (následující field() / select() jdou do inline elementu)
    public function endInline(): static;          // ukončí inline mode
    // Shortcut pro typický případ:
    public function inlineFields(string ...$columns): static;   // přidá inline se sloupci (jen input typu auto-derived)
    
    public function build(): FormTab;
}
```

**Sémantika scope managementu:**

1. `section()` otevře novou sekci. Pokud byla otevřená jiná, automaticky se uzavře.
2. `col()` otevře nový sloupec v aktuální sekci. Pokud byl otevřený předchozí, uzavře se. **Volání `col()` mimo sekci vyhodí `LogicException`.**
3. Volání `input()`, `select()` atd. **musí být uvnitř `col()`**. Jinak `LogicException`.
4. `inline()` otevře inline skupinu uvnitř aktuálního sloupce; následující elementy jdou do `inline.elements`. `endInline()` ji uzavře. Vyhodí `LogicException` při nesouladu.
5. `separator()` jde do aktuálního sloupce. Není to inline.
6. `build()` automaticky uzavře otevřené scopy (inline → col → section → tab).
7. **Auto-hide separátorů:** zachovat současné chování `autoHideSeparators` na úrovni sloupce. Pokud jsou všechny elementy za separátorem v daném sloupci skryté, separátor sám se skryje.

**Auto-label:** funguje stejně jako dřív — `resolveLabel($column, $label)` čte `colLabels` map předaný v konstruktoru.

### 2.3 Aktualizace `TableForm::attachmentsTab()` a `tab()`

`TableForm::tab(string $id, string $label, ?string $icon = null): TabBuilder`

`TableForm::attachmentsTab()` vrátí `FormTab` s `type='attachments'` (zachová se).

Přidej helper `TableForm::subtableTab(string $id, string $label, string $table, string $foreignKey, ?string $formId = null): FormTab` pro taby typu subtable.

### 2.4 Subtable jako tab, ne element

V současném kódu `PersonsForm` vytváří subtable taby přes:

```php
$contacts = $this->tab('contacts', 'Kontakty')
    ->addSubtable('base_persons_contacts', 'person', formId: 'base.persons.contacts')
    ->build();
```

Po refaktoringu to bude:

```php
$contacts = $this->subtableTab('contacts', 'Kontakty', 'base_persons_contacts', 'person', 'base.persons.contacts');
```

Subtable element uvnitř sekce už neexistuje — subtable je vždy vlastní tab.

### 2.5 `JsoncFormLoader` přepis

Načítá nový JSONC formát:

```jsonc
{
    "title": "Kontakt",
    "titleNew": "Nový kontakt",
    "fullSize": false,
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "sections": [
                {
                    "title": null,
                    "columns": [
                        {"elements": [
                            {"type": "input", "column": "name", "required": true},
                            {"type": "input", "column": "email"}
                        ]}
                    ]
                }
            ]
        }
    ]
}
```

Loader:

1. Localize přes `ConfigLocalizer::localize($data, $language)`.
2. Pro každý tab kontroluje `type` (default `"fields"`):
   - `"fields"` → projde `sections[]`, vytvoří `FormSection` s `columns[]` jako `FormColumn[]` s `elements[]` jako `FormElement[]`.
   - `"subtable"` → načte `subtable: {...}` blok.
   - `"attachments"` → načte `tableId`.
3. Doplnění `label` z `TableDefinition` (existující chování).
4. Doplnění `inputType` pro `type=input` z DB typu sloupce.
5. Auto-resolve `options` pro `type=select` pokud chybí.
6. Validace: pokud JSONC obsahuje starý formát (`cols`, `elements` přímo v tabu místo `sections`), vyhodit `\RuntimeException` se srozumitelnou hláškou, kde je problém.

### 2.6 `AutoFormBuilder` přepis

Generuje výchozí formulář pro tabulky bez explicitní definice. Nová logika:

1. Pro každou skupinu sloupců (`columnGroups`) vytvoř jeden tab.
2. Každý tab má **jednu sekci s jedním sloupcem**, obsahující všechny sloupce dané skupiny.
3. `__general__` skupina → tab `general` s localized labelem.

Žádné `cols`/`group`/`separator` magic — auto formulář je vždy minimalisticky čistý vertikální list.

### 2.7 Tests — backend

**Nahradit / aktualizovat:**

- `tests/Unit/Core/Form/TabBuilderTest.php` — nahradit kompletně testy na nové API:
  - `testSectionWithSingleColumn`
  - `testSectionWithMultipleColumns`
  - `testColOutsideSectionThrows`
  - `testElementOutsideColThrows`
  - `testInlineGroup`
  - `testInlineFieldsShortcut`
  - `testUnclosedInlineAutoClosesInBuild`
  - `testAutoHideSeparators` (per-column)
  - `testHiddenSection`
  - Pro každý widget (`input`, `textarea`, `date`, atd.) jeden test
  - `testInputRejectsNonTextInputType`
- `FormDefinitionTest.php` — aktualizovat `toArray` strukturu (snake_case `title_new`, `full_size`, sekce/sloupce v JSON).
- `FormElementTest.php` — odebrat `cols`, přidat validace nových typů.
- `AutoFormBuilderTest.php` — adaptovat na novou strukturu (1 sekce, 1 sloupec per tab).

**Přidat nové:**

- `tests/Unit/Core/Form/FormSectionTest.php` — konstruktor validace.
- `tests/Unit/Core/Form/FormColumnTest.php` — konstruktor.
- `tests/Unit/Core/Form/JsoncFormLoaderTest.php` — kompletní test JSON parsování (nový + lokalizace + auto-label + auto-options + chyba u starého formátu).

Existující PersonsForm tests v `tests/Unit/Module/Base/...` updatovat tak, ať vstupní data sedí s novým builderem.

---

## 3. Phase B — Frontend

### 3.1 Pull labels out of input components

Komponenty v `frontend/src/components/ui/`:

- `Input.svelte`
- `TextArea.svelte`
- `NumberInput.svelte`
- `DateInput.svelte`
- `Select.svelte`
- `Checkbox.svelte` (zvláštní případ — viz níže)

Změny:

1. **Odstranit `<label>` z těchto komponent.** Komponenty nyní renderují **jen** input field + případnou error hlášku.
2. Odstranit prop `label` z těchto komponent.
3. Zachovat propy `required`, `disabled`, `error`, `value`, `onchange` atd.
4. Doplnit `id` prop (nahradí random `inputId` — wrapper komponenta předá konkrétní ID, aby `<label for="...">` fungoval).

**Checkbox je výjimka:** label je vizuálně vedle checkboxu (vpravo) a klikem na něj se přepíná. Pro Checkbox **ponecháme** interní `<label>`, ale jen pro UX text "Aktivní" / "Plátce DPH". Externí label (popisek toho, co checkbox dělá) je v tomto kontextu zbytečný. V `FormElement.svelte` se checkbox vykreslí **bez** wrapperu `FormFieldRow` (přímo do gridu, span obou kolon).

### 3.2 Nové komponenty

`frontend/src/components/form/`:

**`FormSection.svelte`** — vykreslí jednu sekci jako kartu:

```svelte
<script>
  let { section } = $props();
</script>

{#if !section.hidden}
  <section class="shpd-form-section">
    {#if section.title}
      <h3 class="shpd-form-section__title">{section.title}</h3>
    {/if}
    <div class="shpd-form-section__columns" 
         style="--shpd-form-section-cols: {section.columns.length}">
      {#each section.columns as column, i}
        <FormColumn {column} ... />
      {/each}
    </div>
  </section>
{/if}

<style>
  .shpd-form-section {
    background: var(--shpd-color-bg-secondary);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    border: 1px solid var(--shpd-color-border-subtle);
  }
  .shpd-form-section__title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    margin: 0 0 var(--shpd-space-sm) 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .shpd-form-section__columns {
    display: grid;
    grid-template-columns: repeat(var(--shpd-form-section-cols), 1fr);
    gap: var(--shpd-space-xl);
  }
  @media (max-width: 700px) {
    .shpd-form-section__columns {
      grid-template-columns: 1fr;
    }
  }
</style>
```

**`FormColumn.svelte`** — vykreslí sloupec se sdílenou auto-šířkou labelu:

```svelte
<script>
  let { column, formData = $bindable({}), fieldErrors = {}, disabled, onTrigger, parentId } = $props();
</script>

<div class="shpd-form-column">
  {#each column.elements as element, i (element.column ?? `${element.type}-${i}`)}
    <FormElement {element} bind:formData {fieldErrors} {disabled} {onTrigger} {parentId} />
  {/each}
</div>

<style>
  .shpd-form-column {
    display: grid;
    grid-template-columns: max-content 1fr;
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-sm);
    align-items: baseline;
  }
</style>
```

Toto je **klíč k auto-šířce labelů**: `max-content 1fr` udělá v rámci jednoho `FormColumn` všechny labely stejně široké, podle nejdelšího v daném sloupci.

**`FormFieldRow.svelte`** — vykreslí jedno pole label+input v gridu:

```svelte
<script>
  let { element, id, children } = $props();
</script>

{#if !element.hidden}
  <label class="shpd-form-field-row__label" for={id}>
    {element.label}{#if element.required}<span class="shpd-form-field-row__required">*</span>{/if}
  </label>
  <div class="shpd-form-field-row__input">
    {@render children()}
  </div>
{/if}

<style>
  /* Label a input wrapper jsou DVA samostatní potomci FormColumn gridu */
  .shpd-form-field-row__label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-align: right;
    white-space: nowrap;
  }
  .shpd-form-field-row__required {
    color: var(--shpd-color-danger);
    margin-left: 2px;
  }
  .shpd-form-field-row__input {
    min-width: 0;
  }
</style>
```

Důležité: `FormFieldRow` **není wrapper s vlastním DOM elementem kolem obou**. Vrací dva sourozence (`<label>` a `<div>`), které jsou přímými potomky `FormColumn` gridu. Tím grid měří všechny labely v daném sloupci jako jednu kolonu.

**`FormInline.svelte`** — vykreslí inline skupinu (více polí v jedné řádce):

```svelte
<script>
  let { element, formData = $bindable({}), fieldErrors = {}, disabled, onTrigger } = $props();
  
  const id = `shpd-inline-${Math.random().toString(36).slice(2)}`;
</script>

<label class="shpd-form-field-row__label" for={id}>
  {element.elements[0].label}
</label>
<div class="shpd-form-inline">
  {#each element.elements as inner, i}
    <span class="shpd-form-inline__item">
      {#if i > 0}<span class="shpd-form-inline__mini-label">{inner.label}:</span>{/if}
      <FormElementInner element={inner} bind:formData {fieldErrors} {disabled} {onTrigger} id={i === 0 ? id : undefined} />
    </span>
  {/each}
</div>

<style>
  .shpd-form-inline {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: baseline;
    flex-wrap: wrap;
  }
  .shpd-form-inline__item {
    display: flex;
    gap: var(--shpd-space-xs);
    align-items: baseline;
  }
  .shpd-form-inline__mini-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
```

První pole použije „velký" label sekce (DUZP); ostatní pole mají vlastní mini-label vedle inputu (DPPD). Když by to bylo na první pohled nepřehledné, je to jen pro inline — což je vzácný případ.

### 3.3 `FormTab.svelte` přepis

```svelte
<script>
  import FormSection from './FormSection.svelte';
  import FormSubTable from './FormSubTable.svelte';
  import AttachmentPanel from './AttachmentPanel.svelte';
  
  let { tab, formData = $bindable({}), fieldErrors = {}, disabled, onTrigger, parentId } = $props();
</script>

<div class="shpd-form-tab">
  {#if tab.type === 'subtable'}
    <FormSubTable element={tab.subtable} {parentId} {disabled} />
  {:else if tab.type === 'attachments'}
    <AttachmentPanel tableId={tab.table_id} recordId={parentId} {disabled} />
  {:else}
    {#each tab.sections as section, i (section.title ?? `section-${i}`)}
      <FormSection {section} bind:formData {fieldErrors} {disabled} {onTrigger} {parentId} />
    {/each}
  {/if}
</div>

<style>
  .shpd-form-tab {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    padding: var(--shpd-space-lg);
  }
</style>
```

### 3.4 `FormElement.svelte` přepis

Komponenta teď renderuje **jeden** element ve sloupci. Spolupracuje s `FormColumn` gridem (vrací buď FormFieldRow, separator, inline, html, component, nebo checkbox přímo).

```svelte
<script>
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  ...
  import FormFieldRow from './FormFieldRow.svelte';
  import FormInline from './FormInline.svelte';
  
  let { element, formData = $bindable({}), fieldErrors = {}, disabled, onTrigger, parentId } = $props();
  
  const error = $derived(element.column ? (fieldErrors[element.column] ?? null) : null);
  const elDisabled = $derived(disabled || element.read_only === true);
  const inputId = `shpd-${element.column}-${Math.random().toString(36).slice(2, 6)}`;
  
  function handleChange() {
    if (element.triggers === 'reload' && element.column) {
      onTrigger?.(element.column);
    }
  }
</script>

{#if element.type === 'separator'}
  <div class="shpd-form-separator">
    {#if element.label}<span>{element.label}</span>{/if}
  </div>

{:else if element.type === 'inline'}
  <FormInline {element} bind:formData {fieldErrors} disabled={elDisabled} {onTrigger} />

{:else if element.type === 'html'}
  <div class="shpd-form-html">{@html element.content}</div>

{:else if element.type === 'component'}
  <!-- Speciální komponenty: 'recapitulation' atd. — pro PR 1 jen stub -->
  <div class="shpd-form-component">[{element.component_name}]</div>

{:else if element.type === 'input' && element.input_type === 'checkbox'}
  <!-- Checkbox: žádný external label, span obě kolony -->
  <div class="shpd-form-checkbox-row">
    <Checkbox label={element.label} bind:checked={formData[element.column]} disabled={elDisabled} />
  </div>

{:else}
  <FormFieldRow {element} id={inputId}>
    {#if element.type === 'select'}
      <Select id={inputId} bind:value={formData[element.column]} options={element.options ?? []} required={element.required} disabled={elDisabled} {error} onchange={handleChange} />
    {:else if element.input_type === 'textarea'}
      <TextArea id={inputId} bind:value={formData[element.column]} required={element.required} disabled={elDisabled} {error} />
    {:else if element.input_type === 'date'}
      <DateInput id={inputId} bind:value={formData[element.column]} required={element.required} disabled={elDisabled} {error} />
    ... (ostatní input_type větve)
    {:else}
      <Input id={inputId} type={element.input_type ?? 'text'} bind:value={formData[element.column]} required={element.required} disabled={elDisabled} {error} />
    {/if}
  </FormFieldRow>
{/if}
```

**Důležité:** `separator`, `inline`, `html`, `component`, `checkbox-row` musí mít `grid-column: 1 / -1` (přes obě kolony FormColumn gridu). Přidej tyto styly do `FormElement.svelte` nebo do `FormColumn.svelte`:

```css
.shpd-form-separator,
.shpd-form-html,
.shpd-form-component,
.shpd-form-checkbox-row,
:global(.shpd-form-inline-row) {
  grid-column: 1 / -1;
}
```

Pozn.: `FormInline` taky vrací dva sourozence (label + input wrapper), takže funguje ve gridu normálně. Pokud chceš mít label „přes celou šířku" pro inline, použij span. Detaily nech na implementaci.

### 3.5 CSS proměnné

Pokud chybí, přidej do `frontend/src/styles/variables.css`:

```css
--shpd-color-bg-secondary: ... ; /* světle šedý podklad sekce */
--shpd-color-border-subtle: ...; /* velmi jemná hrana sekce */
--shpd-space-xl: ...;
```

Hodnoty zvol konzistentně s ostatními tokeny (světlé téma, dark mode pokud existuje).

---

## 4. Phase C — Showcase port: `base_persons_contacts.jsonc`

Cílový tvar:

```jsonc
{
    "title": "Kontakt",
    "title:cs": "Kontakt",
    "title:en": "Contact",
    "titleNew": "Nový kontakt",
    "titleNew:cs": "Nový kontakt",
    "titleNew:en": "New contact",
    "fullSize": false,
    "tabs": [
        {
            "id": "basic",
            "label": "Kontakt",
            "label:cs": "Kontakt",
            "label:en": "Contact",
            "sections": [
                {
                    "columns": [
                        {
                            "elements": [
                                {"type": "input", "column": "name", "required": true},
                                {"type": "input", "column": "role"},
                                {"type": "input", "column": "email", "input_type": "email"},
                                {"type": "input", "column": "phone", "input_type": "tel"},
                                {"type": "input", "column": "note", "input_type": "textarea"},
                                {"type": "separator"},
                                {"type": "input", "column": "valid_from", "input_type": "date"},
                                {"type": "input", "column": "valid_to", "input_type": "date"},
                                {"type": "input", "column": "order_pos", "input_type": "number"}
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
```

Jednodušší než starý formát — bez `cols` čísel.

---

## 5. Phase D — Mechanický port ostatních formulářů

### 5.1 `base_persons_addresses.jsonc`

Současný formulář má dva taby a uvnitř každého použité separátory pro sub-grouping. V tomto PR ho mechanicky převedeme — **separátory necháme** (nový systém je podporuje uvnitř sloupce, takže není potřeba měnit strukturu). Pravdivý port na sekce přijde v PR 2.

Cílový tvar:

```jsonc
{
    "title": "Adresa",
    "tabs": [
        {
            "id": "address",
            "label": "Adresa",
            "sections": [
                {
                    "columns": [{"elements": [
                        {"type": "select", "column": "address_type"},
                        {"type": "input", "column": "name"},
                        {"type": "separator", "label": "Adresa"},
                        {"type": "input", "column": "street"},
                        {"type": "input", "column": "house_number"},
                        ...
                        {"type": "separator", "label": "Platnost"},
                        {"type": "input", "column": "valid_from"},
                        ...
                    ]}]
                }
            ]
        },
        ...
    ]
}
```

(jeden tab → jedna sekce → jeden sloupec → původní elementy bez `cols`, separátory zachovány)

### 5.2 `base_persons_bank_accounts.jsonc`

Analogicky.

### 5.3 `PersonsForm.php`

Mechanicky převést — každý tab se stane jednou sekcí s jedním sloupcem. Separátory + `hidden` na elementech zůstávají (zachovat současné kondicionální chování). Subtable taby přes nový `subtableTab()` helper.

Před:

```php
$basic = $this->tab('basic', 'Základní údaje')
    ->addInput('person_id', cols: 1, required: true)
    ->addSelect('person_type', cols: 1, ..., triggers: 'reload', required: true)
    ->addInput('full_name', cols: 2, required: $isCompany, readOnly: $isPerson)
    ->addSeparator('Identifikace firmy', hidden: $isPerson || $isUndefined)
    ->addInput('company_id', cols: 1, hidden: $isPerson || $isUndefined)
    ...
    ->build();
```

Po:

```php
$basic = $this->tab('basic', 'Základní údaje')
    ->section()
        ->col()
            ->input('person_id', required: true)
            ->select('person_type', options: $personTypeOptions, triggers: 'reload', required: true)
            ->input('full_name', required: $isCompany, readOnly: $isPerson)
            ->separator('Identifikace firmy', hidden: $isPerson || $isUndefined)
            ->input('company_id', hidden: $isPerson || $isUndefined)
            ...
    ->build();
```

Žádné `cols` parametry. Žádné víc-sloupcové struktury — to bude obsah PR 3, který Osobu polidští se skutečnými sekcemi „Firma" / „Jméno" / „Osobní údaje" rozloženými na sloupce.

Subtable taby:

```php
// Před:
$contacts = $this->tab('contacts', 'Kontakty')
    ->addSubtable('base_persons_contacts', 'person', formId: 'base.persons.contacts')
    ->build();

// Po:
$contacts = $this->subtableTab('contacts', 'Kontakty', 'base_persons_contacts', 'person', 'base.persons.contacts');
```

### 5.4 Mechanický port `AutoFormBuilder`

Generuje pro každou skupinu sloupců jeden tab se strukturou `tab > section > col > elements`. Bez `cols` parametrů na elementech.

---

## 6. Aktualizace dokumentace

Přepiš `docs/edit-forms.md` tak, aby reflektoval:

- nový wire format (sekce, sloupce, inline)
- nový builder API
- chování auto-width labelů (CSS Grid `max-content 1fr`)
- vizuální chrome sekce (varianta B: pozadí + jemná hrana)
- migrační poznámku pro starý formát (krátká zmínka v sekci „Historie / Migrace")

Aktualizuj sekce 3, 4, 6, 9, 11, 12 dokumentu. Sekce 5 (DocStates), 7 (Recalculate), 8 (Validace) zůstávají.

---

## 7. Acceptance criteria

- [ ] Všechny existující PHPUnit testy projdou (po jejich aktualizaci).
- [ ] Nové testy pro `FormSection`, `FormColumn`, `JsoncFormLoader` projdou.
- [ ] Frontend build (`npm run build`) projde bez chyb.
- [ ] V devbox UI:
  - [ ] **Kontakt** (otevřený jako sub-dialog z Osoby) zobrazí jeden tab, jeden vizuální „card" s našedlým pozadím, labely vlevo, inputy vpravo, labely v rámci sloupce stejně široké.
  - [ ] **Adresa** zobrazí dva taby, každý jako jednu kartu se separátory uvnitř.
  - [ ] **Osoba** zobrazí 6 tabů (basic, contact, contacts, addresses, bank_accounts, attachments). První dva mají sekce/sloupce, tabovi 3–5 jsou subtable, tab 6 attachments. Conditional skrývání podle `person_type` funguje.
  - [ ] Tab přes `recalculate` (změna `person_type`) přepíše formulář bez závady.
  - [ ] Dirty detection, doc states, save flow fungují beze změny.
- [ ] Žádný kód `cols: 1..4` na elementech, žádné `addInput`/`addSeparator`/`openGroup`/`closeGroup`/`addSubtable` volání.

---

## 8. Gotchas a poznámky

- **Subtable tab vs. element:** zajistit, že frontend rozliší `tab.type==='subtable'` od starého `element.type==='subtable'`. Starý cestou už nic nepoteče.
- **Random key v `{#each}`:** ve všech nových komponentách použít stabilní klíče (column ID nebo type+index), nikdy `Math.random()`.
- **`$effect` čte `$state`:** pozor na implicit dependencies, předávat parametry explicitně.
- **`Dibi\DateTime` normalizace:** zachovat (řeší `DataSourceConnection::fetchRow/fetchAll`).
- **API envelope:** `res.data.formDefinition`, `res.data.data`. Beze změny.
- **Checkbox v inline:** povolíme jen `input` a `select`. Checkbox by v inline neměl smysl (nemá vlevo label).
- **Šířka labelů přes sloupce:** každý `FormColumn` má vlastní grid → vlastní auto-šířku. **Záměrně** — chceme, aby dva vedlejší sloupce mohly mít různé šířky labelů. Pokud chceš někdy synchronizovat napříč sloupci, použij CSS subgrid, ale to v tomhle PR neřešíme.
- **Mobil:** sekce s víc sloupci se na <700px lámou pod sebe (jedno-sloupcový grid). Labely zůstávají vlevo. Pokud to bude na velmi úzkých displejích neúnosné, řeš v dalším PR — pro PoC stačí.

---

## 9. Doporučený postup implementace

1. **A1**: nové datové třídy + `toArray()` + jednotkové testy
2. **A2**: nový `TabBuilder` + jednotkové testy
3. **A3**: `JsoncFormLoader` + test
4. **A4**: `AutoFormBuilder` + adaptace testu
5. **A5**: `TableForm::tab`/`subtableTab`/`attachmentsTab` + úpravy `PersonsForm`
6. **A6**: port JSONC souborů (Contacts → showcase, ostatní mechanicky)
7. **B1**: frontend — `FormSection`, `FormColumn`, `FormFieldRow`, `FormInline`
8. **B2**: pull labels z ui/ komponent
9. **B3**: přepis `FormTab.svelte`, `FormElement.svelte`
10. **B4**: CSS finalizace, dark mode kontrola
11. Manuální test v devboxu, oprava bugů
12. Aktualizace `docs/edit-forms.md`
13. Update memory

Před commitem ověř, že memory David obsahuje aktuální stav modulu (form-system se znatelně mění, hodí se to do rekapitulace).
