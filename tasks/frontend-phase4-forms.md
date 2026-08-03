# Fáze 4: Editační formuláře — Tasky pro Claude Code

**Stav:** hotovo

Navazuje na Fázi 2. Po dokončení bude možné vytvářet a editovat záznamy v jakékoliv tabulce přes formuláře generované z metadat.

---

## Task 13: Základní UI komponenty pro formuláře

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create base UI input components needed for form rendering.

Read these files for context:
- frontend/src/styles/variables.css (CSS custom properties)
- modules/core/system/tables/core_system_users.jsonc (example column types: varchar, boolean)
- modules/base/persons/tables/base_persons_persons.jsonc (example with more column types)

Create these components in `frontend/src/components/ui/`:

### 1. `Input.svelte`
Text/number input field.
Props: `value` (bindable), `label`, `type` (default "text"), `placeholder`, `required` (boolean), `maxlength`, `disabled`, `error` (error message string or null)
- Renders a label above the input
- Shows red border and error message below when `error` is set
- Focus state with --shpd-color-border-focus
- Full width within its container

### 2. `TextArea.svelte`
Multi-line text input.
Props: `value` (bindable), `label`, `placeholder`, `required`, `disabled`, `error`, `rows` (default 4)
- Same styling pattern as Input

### 3. `Checkbox.svelte`
Boolean toggle.
Props: `checked` (bindable), `label`, `disabled`
- Label next to the checkbox
- Custom styled checkbox using CSS (hide native, style a box)

### 4. `Select.svelte`
Dropdown select.
Props: `value` (bindable), `label`, `options` (array of {value, label}), `required`, `disabled`, `error`, `placeholder` (text for empty option)
- Native <select> with custom styling
- Empty option at top with placeholder text when not required

### 5. `NumberInput.svelte`
Numeric input with precision control.
Props: `value` (bindable), `label`, `required`, `disabled`, `error`, `min`, `max`, `step`
- Uses type="number" internally
- Same styling as Input

### 6. `DateInput.svelte`
Date input.
Props: `value` (bindable, string in YYYY-MM-DD format), `label`, `required`, `disabled`, `error`
- Uses native date input (type="date") — no custom date picker needed for now
- Same styling as Input

### Styling guidelines for all components:
- Use CSS custom properties from variables.css
- BEM class names: .shpd-input, .shpd-input__label, .shpd-input__field, .shpd-input__error
- Label font-size: --shpd-font-size-sm, font-weight 500, color --shpd-color-text
- Field: padding --shpd-space-sm, border 1px solid --shpd-color-border, border-radius --shpd-radius-md
- Focus: border-color --shpd-color-border-focus, outline none, box-shadow 0 0 0 2px rgba(37,99,235,0.15)
- Error state: border-color --shpd-color-danger, error message in --shpd-color-danger, font-size --shpd-font-size-sm
- Required indicator: red asterisk (*) after label
- Spacing: margin-bottom --shpd-space-md between label and field
- Disabled: opacity 0.6, cursor not-allowed
- Use <style> blocks with scoped styles in each component

### 7. `Button.svelte`
Action button.
Props: `label`, `variant` ("primary", "secondary", "danger"), `disabled`, `loading` (shows spinner), `type` (default "button"), `onclick`
- Primary: --shpd-color-primary background, white text
- Secondary: white background, border, --shpd-color-text text
- Danger: --shpd-color-danger background, white text
- Loading: show spinner icon, disable click
- Padding: --shpd-space-sm --shpd-space-lg
- Border-radius: --shpd-radius-md
- Hover effects for each variant

Use Svelte 5 runes. For bindable props use $bindable(). All comments in English.
```

---

## Task 14: FormField — dynamický field renderer

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create a FormField component that dynamically renders the correct input component based on column metadata.

Read these files for context:
- frontend/src/components/ui/ (all input components from Task 13)
- modules/core/system/tables/core_system_users.jsonc (column types and properties)
- modules/base/persons/tables/base_persons_persons.jsonc (more column types)
- docs/rest-api.md section 7 (validation rules derived from column types)

Create `frontend/src/components/form/FormField.svelte`:

Props:
- `column` — column metadata object from _meta API: {id, name, type, nullable, length, precision, scale, group, cfgItem, default}
- `value` — bindable, the current field value
- `error` — error message string or null (from server validation)
- `disabled` — boolean

This component maps column type to the appropriate UI component:

| Column type | Component | Notes |
|-------------|-----------|-------|
| varchar     | Input (type="text") | maxlength from column.length |
| text, longtext | TextArea | |
| int, smallint, bigint, tinyint | NumberInput | step=1 |
| numeric     | NumberInput | step derived from scale (e.g. scale=2 → step=0.01) |
| boolean     | Checkbox | |
| date        | DateInput | |
| datetime    | Input (type="datetime-local") | |
| time        | Input (type="time") | |
| enumInt     | Select | options will be empty for now (future: load from config) |
| enumString  | Select | options will be empty for now |
| Default     | Input (type="text") | fallback |

Additional logic:
- Set `required` based on !column.nullable (except for boolean which is never required)
- Pass `label` from column.name
- Pass `error` through to the UI component
- Skip rendering entirely if column.id is "id", "created", or "modified" (auto-managed fields)

Use Svelte 5 runes. All comments in English.
```

---

## Task 15: FormRenderer — generický formulář z metadat

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the FormRenderer component that renders a complete form from table metadata.

Read these files for context:
- frontend/src/components/form/FormField.svelte
- frontend/src/components/ui/Button.svelte
- frontend/src/api/client.js
- src/Api/Controller/MetaController.php (to understand _meta response shape)
- src/Api/Controller/CrudController.php (to understand validation error response shape)
- modules/core/system/tables/core_system_users.jsonc (columnGroups example)

Create `frontend/src/components/form/FormRenderer.svelte`:

Props:
- `table` — table name (string, e.g. "core_system_users")
- `recordId` — number or null (null = create new, number = edit existing)
- `onSave` — callback function, called after successful save with the saved record data
- `onCancel` — callback function, called when user clicks Cancel

### Behavior

On mount:
1. Fetch metadata: GET /_meta/tables/{table}
2. If recordId is not null, fetch existing record: GET /{table}/{recordId}
3. Initialize form data from record (edit) or empty values (create)

Form data:
- Stored as a plain object: `{login: "admin", full_name: "Admin", ...}`
- Each FormField binds its value to the corresponding key
- Skip auto-managed columns: id, created, modified
- Skip password_hash in edit mode (don't show current hash)

### Layout

- If table metadata has `columnGroups`, render fields grouped by group
  - Each group has a heading (group name) and its fields below
  - Fields without a group go into an "Ostatní" (Other) section
- If no columnGroups, render all fields in a single section
- Two-column layout for fields where possible (CSS grid: 2 columns on wide screens, 1 on narrow)

### Save behavior

- On "Uložit" click:
  1. Set loading state
  2. If creating (recordId is null): POST /{table} with form data
  3. If editing: PUT /{table}/{recordId} with form data
  4. If API returns success: call onSave(responseData)
  5. If API returns VALIDATION_ERROR: map error details to field errors
     - API returns: {error: {details: [{field: "login", code: "REQUIRED", message: "..."}]}}
     - Map to: errors = {login: "Field 'login' is required"}
     - Display errors next to corresponding fields
  6. Clear loading state

### Buttons

- Bottom of the form: "Uložit" (primary) + "Zrušit" (secondary)
- Uložit shows loading spinner during save
- Zrušit calls onCancel

### Styling

- Form padding: --shpd-space-lg
- Group heading: font-size --shpd-font-size-lg, font-weight 600, margin-bottom --shpd-space-md, border-bottom
- Fields grid: display grid, grid-template-columns: repeat(2, 1fr), gap --shpd-space-md
- On narrow screens (<600px): single column
- Button bar: margin-top --shpd-space-lg, display flex, gap --shpd-space-sm, justify-content flex-end
- BEM class names: .shpd-form, .shpd-form__group, .shpd-form__group-title, .shpd-form__fields, .shpd-form__actions

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 16: FormDialog — modální dialog s formulářem

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create a modal dialog component and a form dialog that wraps FormRenderer in a modal.

Read these files for context:
- frontend/src/components/form/FormRenderer.svelte
- frontend/src/styles/variables.css

### 1. Create `frontend/src/components/ui/Modal.svelte`

A generic modal overlay component.

Props:
- `title` — string, shown in the modal header
- `open` — boolean, controls visibility
- `onClose` — callback when clicking overlay or pressing Escape
- `children` — Svelte snippet (modal body content)
- `width` — optional, default "640px"

Features:
- Semi-transparent dark overlay (rgba(0,0,0,0.5))
- Centered white card with rounded corners
- Header with title and close button (×)
- Scrollable body if content overflows
- Close on Escape key press
- Close on overlay click (outside the card)
- Prevent body scroll when open
- Smooth fade-in animation (CSS transition or Svelte transition)

Styling:
- z-index: 1000
- Card: background white, border-radius --shpd-radius-lg, box-shadow --shpd-shadow-lg
- Header: padding --shpd-space-md --shpd-space-lg, border-bottom, flex between title and close button
- Body: padding --shpd-space-lg, overflow-y auto, max-height calc(90vh - header)
- BEM: .shpd-modal, .shpd-modal__overlay, .shpd-modal__card, .shpd-modal__header, .shpd-modal__body

### 2. Create `frontend/src/components/form/FormDialog.svelte`

Wraps FormRenderer inside a Modal.

Props:
- `table` — table name
- `recordId` — number or null
- `open` — boolean
- `onClose` — callback
- `onSaved` — callback, called after successful save (receives saved record)

Behavior:
- Computes title: recordId ? "Upravit záznam" : "Nový záznam"
- Renders Modal with FormRenderer inside
- FormRenderer's onSave: call onSaved, then onClose
- FormRenderer's onCancel: call onClose
- Modal width: "720px"

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 17: Propojení prohlížeče s formuláři

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), connect the TableBrowser to the FormDialog so users can create and edit records.

Read these files:
- frontend/src/components/browser/TableBrowser.svelte
- frontend/src/components/form/FormDialog.svelte
- frontend/src/components/ui/Button.svelte

### Changes to TableBrowser.svelte

1. **Add "Nový záznam" button** above the table (in a toolbar area):
   - Import Button component
   - Add a toolbar div above the table with the button
   - Clicking it opens FormDialog with recordId=null

2. **Add row click to edit**:
   - Double-click on a table row opens FormDialog with recordId=row.id
   - Need to include "id" in the data (even though we don't display it in the table)
   - To get the id: modify the fetchData to always include id in the API response
     (the API already returns id; we just need to keep it in the rows data even though
     we don't show it in columns)
   - Add cursor:pointer to rows

3. **FormDialog integration**:
   - Import FormDialog
   - Add state: `formOpen` (boolean), `editRecordId` (number or null)
   - "Nový záznam" click: formOpen=true, editRecordId=null
   - Row double-click: formOpen=true, editRecordId=row.id
   - On FormDialog onSaved: refresh the table data (call fetchData again)
   - On FormDialog onClose: formOpen=false, editRecordId=null

4. **Toolbar styling**:
   - Toolbar above the table: padding --shpd-space-sm --shpd-space-md, border-bottom, flex with items on the right
   - BEM: .shpd-browser__toolbar

### Important notes:
- The _meta API response already provides enough info for FormRenderer to work
- The CRUD API already handles POST and PUT with validation
- Make sure the `id` field is available in each row object for double-click editing,
  even though we filter it out from visible columns
- When fetching data in init(), keep the full row objects (with id) — just filter
  columns for display purposes

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Pořadí spouštění

Spouštěj tasky v pořadí 13 → 14 → 15 → 16 → 17.

Po dokončení:
- V prohlížeči tabulek bude tlačítko "Nový záznam"
- Kliknutím se otevře modální dialog s formulářem generovaným z metadat tabulky
- Formulář zobrazí pole podle sloupců tabulky, seskupené podle columnGroups
- Dvojklik na řádek otevře formulář s předvyplněnými daty záznamu
- Uložení odešle data přes API, validační chyby se zobrazí u polí
- Po uložení se prohlížeč automaticky aktualizuje
