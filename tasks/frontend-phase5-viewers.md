# Viewer systém — Tasky pro Claude Code

**Stav:** hotovo

Implementace viewer systému — rozšířeného prohlížeče záznamů s formátovanými řádky, fulltextovým hledáním, nekonečným skrolováním a detailním panelem.

---

## Task 20: Server — abstraktní TableViewer + ViewerRegistry

**Prompt pro Claude Code:**

```
In the Shipard backend, create the abstract TableViewer base class and ViewerRegistry for loading viewer definitions from modules.

Read these files for context:
- docs/viewer-system.md (full viewer system design)
- src/Core/Module/ModuleDefinition.php (module structure)
- src/Core/Module/ModuleLoader.php
- src/Core/Database/DataSourceConnection.php
- modules/base/persons/module.jsonc (will add "viewers" field)

### 1. Create `src/Core/Viewer/TableViewer.php`

Abstract base class for all viewers.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Database\DataSourceConnection;

abstract class TableViewer
{
    /** Default number of rows per page. Subclasses can override. */
    protected int $pageSize = 50;

    public function __construct(
        protected DataSourceConnection $db,
        protected string $table,
    ) {}

    /**
     * Get the page size for this viewer.
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Select rows for the viewer list.
     *
     * IMPORTANT: The implementation should fetch pageSize + 1 rows. The caller
     * (ViewerController) uses the extra row to determine if more data exists:
     * - If count > pageSize: trim to pageSize, set hasMore = true
     * - If count <= pageSize: return as is, set hasMore = false
     *
     * The ORDER BY clause must always end with a unique column (typically `id`)
     * to guarantee deterministic ordering with LIMIT/OFFSET. For example:
     *   ORDER BY last_name ASC, first_name ASC, id ASC
     *
     * @param string|null $search     Fulltext search query
     * @param array       $filters    Active filters [{id: string, value: mixed}]
     * @param int         $pageNumber Zero-based page number (0 = first page)
     * @return array Array of raw DB rows (up to pageSize + 1)
     */
    abstract public function selectRows(
        ?string $search,
        array $filters,
        int $pageNumber,
    ): array;

    /**
     * Render a single row for the list display.
     * Returns a structured object for the frontend.
     *
     * Row format:
     * - id: int (record ID)
     * - icon: string|null (icon identifier for left side, ~32px area)
     * - t1: string|array|null (first line, left — main text, displayed bold)
     * - i1: string|array|null (first line, right — e.g. amount, code)
     * - t2: string|array|null (second line, left — e.g. doc number, date)
     * - i2: string|array|null (second line, right — e.g. total with VAT)
     * - t3: string|array|null (optional third line — e.g. description)
     *
     * Each field (t1, i1, t2, i2, t3) can be:
     * - null: not displayed
     * - "string": plain text
     * - {"text": "...", "class": "...", "icon": "..."}: styled span
     * - [{"text": "...", "class": "..."}, ...]: array of styled spans
     *
     * Available classes: amount, muted, bold, primary, success, warning, danger
     *
     * @param array $rowData Raw DB row
     * @return array Structured row
     */
    abstract public function renderRow(array $rowData): array;

    /**
     * Render the detail panel for a selected row.
     * Returns tabs with content.
     *
     * Content types:
     * - "properties": {type: "properties", groups: [{title, items: [{label, value}]}]}
     * - "table": {type: "table", columns: [{id, label}], rows: [{col: val}]}
     * - "html": {type: "html", html: "<div>..."}
     *
     * @param int $recordId
     * @return array {tabs: [{id: string, label: string, content: array}]}
     */
    public function renderDetail(int $recordId): array
    {
        return ['tabs' => []];
    }

    /**
     * Toolbar actions. Changes based on whether a row is selected.
     *
     * @param array|null $selectedRow null if no row selected
     * @return array [{id: string, label: string, icon?: string, variant?: string}]
     */
    public function getToolbarActions(?array $selectedRow): array
    {
        $actions = [
            ['id' => 'create', 'label' => 'Přidat', 'variant' => 'primary'],
        ];

        if ($selectedRow !== null) {
            array_splice($actions, 1, 0, [
                ['id' => 'edit', 'label' => 'Otevřít', 'variant' => 'secondary'],
            ]);
        }

        return $actions;
    }

    /**
     * Filter definitions for the right panel.
     *
     * @return array [{id: string, label: string, type: string, options?: array}]
     */
    public function getFilters(): array
    {
        return [];
    }

    /**
     * Build a LIKE search condition for multiple columns.
     * Helper for subclasses to use in selectRows().
     *
     * @param string[] $columns Column names to search
     * @param string $search Search term
     * @return array{0: string, 1: array} [sql_fragment, params]
     */
    protected function buildSearchCondition(array $columns, string $search): array
    {
        if (empty($columns) || $search === '') {
            return ['', []];
        }

        $parts = [];
        $params = [];
        $term = '%' . $search . '%';

        foreach ($columns as $col) {
            $parts[] = "`{$col}` LIKE %s";
            $params[] = $term;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Compute LIMIT clause values for offset-based pagination.
     * Fetches pageSize + 1 to detect whether more rows exist.
     *
     * @param int $pageNumber Zero-based page number
     * @return array{0: int, 1: int} [offset, limit] — use as "LIMIT offset, limit"
     */
    protected function buildPaginationLimit(int $pageNumber): array
    {
        $offset = $pageNumber * $this->pageSize;
        $limit = $this->pageSize + 1;
        return [$offset, $limit];
    }
}
```

### 2. Create `src/Core/Viewer/ViewerDefinition.php`

Simple data class for viewer registration info from module.jsonc.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Viewer;

class ViewerDefinition
{
    public function __construct(
        public readonly string $id,        // e.g. "base.persons"
        public readonly string $name,      // Localized name
        public readonly string $table,     // Main table
        public readonly string $class,     // PHP class name
        public readonly string $moduleId,  // Parent module ID
    ) {}
}
```

### 3. Create `src/Core/Viewer/ViewerRegistry.php`

Loads viewer definitions from resolved modules and instantiates viewer objects.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;

class ViewerRegistry
{
    /** @var ViewerDefinition[] indexed by viewer ID */
    private array $viewers = [];

    /**
     * Load viewer definitions from resolved modules.
     * Each module can have a "viewers" array in its definition.
     *
     * @param ModuleDefinition[] $modules Resolved modules
     * @param string $language For localized names
     */
    public function loadFromModules(array $modules, string $language): void
    {
        // ModuleDefinition doesn't have "viewers" yet — we need to load
        // them from the raw JSONC. For now, accept pre-parsed viewer data.
    }

    /**
     * Register a viewer definition directly.
     */
    public function register(ViewerDefinition $def): void
    {
        $this->viewers[$def->id] = $def;
    }

    /**
     * Get all registered viewer definitions.
     * @return ViewerDefinition[]
     */
    public function getAll(): array
    {
        return $this->viewers;
    }

    /**
     * Get a specific viewer definition by ID.
     */
    public function get(string $id): ?ViewerDefinition
    {
        return $this->viewers[$id] ?? null;
    }

    /**
     * Instantiate a TableViewer object for the given viewer ID.
     */
    public function createViewer(string $id, DataSourceConnection $db): ?TableViewer
    {
        $def = $this->viewers[$id] ?? null;
        if ($def === null) {
            return null;
        }

        $class = $def->class;
        if (!class_exists($class)) {
            return null;
        }

        return new $class($db, $def->table);
    }
}
```

### 4. Update `src/Core/Module/ModuleDefinition.php`

Add `viewers` field to ModuleDefinition:
- Add `public readonly array $viewers` to the constructor
- In `fromArray()`: parse `$data['viewers'] ?? []`

### 5. Update ModuleDefinition::fromArray to handle viewers

The `viewers` field in module.jsonc is an array of objects with id, name, table, class. Parse them and store as raw arrays for now (ViewerRegistry will process them with localization).

Conventions: PHP 8.5+, strict_types, PSR-4. All code and comments in English.
```

---

## Task 21: Server — ViewerController + API endpointy

**Prompt pro Claude Code:**

```
In the Shipard backend, create the ViewerController and register the API routes for the viewer system.

Read these files for context:
- src/Core/Viewer/TableViewer.php (just created)
- src/Core/Viewer/ViewerRegistry.php (just created)
- src/Core/Viewer/ViewerDefinition.php (just created)
- src/Api/Router.php (how routes work)
- src/Api/Controller/MetaController.php (example controller)
- src/Api/Controller/NavigationController.php (example with modules)
- public/index.php (dispatch function)

### 1. Create `src/Api/Controller/ViewerController.php`

Controller that handles viewer API requests.

Methods:

#### `meta(string $viewerId, ViewerRegistry $registry, DataSourceConnection $db): Response`

Returns viewer metadata — name, filters, default toolbar actions.

```json
{
    "success": true,
    "data": {
        "id": "base.persons",
        "name": "Osoby",
        "filters": [],
        "toolbar": [
            {"id": "create", "label": "Přidat", "variant": "primary"}
        ]
    }
}
```

#### `rows(string $viewerId, Request $request, ViewerRegistry $registry, DataSourceConnection $db): Response`

Returns formatted rows for the viewer list.

Query params:
- `search` (string, optional) — fulltext search
- `page` (int, optional, default 0) — zero-based page number
- `filter[{id}]` — filter values

Process:
1. Create viewer instance from registry
2. Parse query params (search, page, filters)
3. Call viewer.selectRows(search, filters, page)
   — the viewer internally fetches pageSize + 1 rows
4. If result count > pageSize: trim to pageSize, hasMore = true; else hasMore = false
5. For each row (up to pageSize), call viewer.renderRow(row)
6. Return formatted rows + hasMore flag

```json
{
    "success": true,
    "data": {
        "rows": [
            {"id": 1, "t1": "Novák Jan", "i1": "P001", "t2": [{"text": "IČO: 123"}]},
            ...
        ],
        "hasMore": true
    }
}
```

#### `detail(string $viewerId, int $recordId, ViewerRegistry $registry, DataSourceConnection $db): Response`

Returns detail panel content + toolbar actions for the selected row.

Process:
1. Create viewer instance
2. Fetch the record from DB (simple SELECT by ID)
3. Call viewer.renderDetail(recordId)
4. Call viewer.getToolbarActions(record)
5. Return both

```json
{
    "success": true,
    "data": {
        "toolbar": [
            {"id": "create", "label": "Přidat", "variant": "primary"},
            {"id": "edit", "label": "Otevřít", "variant": "secondary"}
        ],
        "detail": {
            "tabs": [...]
        }
    }
}
```

### 2. Update `src/Api/Router.php`

Add routes for viewer endpoints. All under `/_ui/viewer/`:

```
GET /_ui/viewer/{viewerId}/meta         → controller: 'viewer', action: 'meta'
GET /_ui/viewer/{viewerId}/rows         → controller: 'viewer', action: 'rows'
GET /_ui/viewer/{viewerId}/detail/{id}  → controller: 'viewer', action: 'detail'
```

The viewerId uses dot notation (e.g. "base.persons"), so the router needs to handle dots in the path segment. Parse it as: everything between `/_ui/viewer/` and the next `/` (which is either `meta`, `rows`, or `detail`).

Update the Route class if needed — add a `viewerId` field or reuse the `table` field.

### 3. Update `public/index.php`

Add viewer dispatching:

1. After loading tables (step 4), also build a ViewerRegistry from resolved modules
2. Add a 'viewer' case to the dispatch match
3. Pass ViewerRegistry and DataSourceConnection to ViewerController

The ViewerRegistry needs to be populated from module definitions. Since we added `viewers` to ModuleDefinition, iterate resolved modules and register their viewers.

For loading viewers with localized names:
- Each module's `viewers` array has name:cs / name:en fields
- Use ConfigLocalizer to resolve the name for the requested language
- Or read the raw viewer data from module JSONC files, localize, and register

Simplest approach: in index.php or a new ViewerLoader class, iterate resolved modules, read their `viewers` field, localize names, and populate the registry.

Conventions: PHP 8.5+, strict_types, PSR-4. All code and comments in English.
```

---

## Task 22: Server — PersonsViewer

**Prompt pro Claude Code:**

```
In the Shipard backend, create the PersonsViewer — the first concrete viewer implementation for the persons table.

Read these files for context:
- src/Core/Viewer/TableViewer.php (abstract base)
- modules/base/persons/tables/base_persons_persons.jsonc (persons table)
- modules/base/persons/tables/base_persons_contacts.jsonc (contacts table)
- modules/base/persons/tables/base_persons_addresses.jsonc (addresses table)
- modules/base/persons/tables/base_persons_bank_accounts.jsonc (bank accounts table)
- modules/base/persons/module.jsonc (module definition)

### 1. Create `modules/base/persons/src/PersonsViewer.php`

Namespace: `Shipard\Module\Base\Persons`

```php
class PersonsViewer extends TableViewer
```

#### selectRows()

SQL query for the persons list:
- SELECT id, person_id, person_type, full_name, company_id, tax_id, email, phone, is_closed
  FROM base_persons_persons
- If search is provided: WHERE full_name LIKE %search% OR company_id LIKE %search% OR email LIKE %search% OR person_id LIKE %search%
- If is_closed filter is not active: AND is_closed = 0 (default: hide closed)
- ORDER BY last_name ASC, first_name ASC, id ASC  (composite, deterministic)
- Use buildPaginationLimit(pageNumber) for LIMIT offset, count

Use the helper methods from TableViewer (buildSearchCondition, buildPaginationLimit).

#### renderRow()

Format each row:
- `id`: row ID
- `t1`: full_name (plain string, the main bold text)
- `i1`: person_id (the person code, right-aligned)
- `t2`: array of spans:
  - If company_id is not empty: {"text": "IČO: {company_id}"}
  - If tax_id is not empty: {"text": "DIČ: {tax_id}"}
  - If is_closed: {"text": "Uzavřeno", "class": "danger"}
- `t3`: email if not empty, otherwise phone if not empty, otherwise null
- `icon`: null (no icon for persons for now)

Example output:
```json
{
    "id": 1,
    "t1": "Novák Jan",
    "i1": "P001",
    "t2": [{"text": "IČO: 12345678"}, {"text": "DIČ: CZ12345678"}],
    "t3": "jan.novak@email.cz"
}
```

#### renderDetail()

Three tabs: Přehled, Kontakty, Adresy.

Tab "Přehled" (overview):
- Type: "properties"
- Groups:
  1. "Identifikace": person_id, person_type (as text), company_id, tax_id, vat_id
  2. "Kontakt": email, phone, web
  3. "Osobní údaje": birth_date, national_id
- Load the full record from base_persons_persons WHERE id = $recordId
- Skip null/empty values

Tab "Kontakty" (contacts):
- Type: "table"
- Columns: name, role, email, phone
- Load from base_persons_contacts WHERE person = $recordId ORDER BY order_pos

Tab "Adresy" (addresses):
- Type: "table"
- Columns: name (or address_type label), display_line
- Load from base_persons_addresses WHERE person = $recordId ORDER BY order_pos

#### getFilters()

One filter for now:
```php
return [
    ['id' => 'is_closed', 'label' => 'Zobrazit uzavřené', 'type' => 'checkbox'],
];
```

#### getToolbarActions()

- Always: "Přidat" (create, primary)
- When row selected: "Otevřít" (edit, secondary)

### 2. Update `modules/base/persons/module.jsonc`

Add the viewers field:

```jsonc
"viewers": [
    {
        "id": "base.persons",
        "name": "Persons",
        "name:cs": "Osoby",
        "name:en": "Persons",
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsViewer"
    }
]
```

### 3. PSR-4 autoloading

Check that `composer.json` has the module autoloading. If not present, add:

```json
"autoload": {
    "psr-4": {
        "Shipard\\": "src/",
        "Shipard\\Module\\Base\\Persons\\": "modules/base/persons/src/",
    }
}
```

Note: A better approach might be a general pattern like `"Shipard\\Module\\": "modules/"` with directory mapping. But for now, explicit mapping for each module with PHP classes is fine. Run `composer dump-autoload` after changes.

Conventions: PHP 8.5+, strict_types, PSR-4. All code and comments in English.
```

---

## Task 23: Server — NavigationController update pro viewery

**Prompt pro Claude Code:**

```
In the Shipard backend, update the NavigationController to include viewer items in the sidebar navigation alongside table items.

Read these files:
- src/Api/Controller/NavigationController.php (current implementation)
- src/Core/Viewer/ViewerDefinition.php
- src/Core/Viewer/ViewerRegistry.php
- modules/base/persons/module.jsonc (now has "viewers" field)

### Changes

The NavigationController currently generates navigation items of type "table" for each table in a module. Now it should also generate items of type "viewer" for modules that have viewers defined.

Logic:
1. For each module, check if it has viewers defined
2. If a viewer exists for a table, use the viewer as the navigation item instead of the raw table
3. The navigation item for a viewer:
   ```json
   {
       "id": "viewer:base.persons",
       "label": "Osoby",
       "type": "viewer",
       "viewerId": "base.persons"
   }
   ```
4. Tables that have a viewer should NOT also appear as a separate "table" type item (avoid duplicates)
5. Tables without a viewer continue to appear as "table" type items

The NavigationController needs access to viewer definitions. Either:
- Accept ViewerRegistry as a parameter
- Or read viewer definitions from modules directly (same as it reads table names)

The simplest approach: the NavigationController already iterates modules. For each module, check if `$module->viewers` is non-empty. For tables that are covered by a viewer, emit a viewer item; for the rest, emit table items.

### Frontend impact

The frontend Sidebar and navigation store need to handle the new "viewer" type. But we'll do that in the frontend tasks. For now, just make sure the API returns the correct navigation structure with both "table" and "viewer" type items.

Conventions: PHP 8.5+, strict_types. All code and comments in English.
```

---

## Task 24: Frontend — Viewer.svelte a komponenty

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the Viewer component and its sub-components.

Read these files for context:
- docs/viewer-system.md (full viewer system design)
- frontend/src/components/browser/TableBrowser.svelte (existing table browser for reference)
- frontend/src/api/client.js
- frontend/src/stores/navigation.svelte.js

### 1. Create `frontend/src/components/viewer/ViewerRow.svelte`

Renders a single formatted row from the viewer API.

Props:
- `row` — the row object: {id, icon?, t1?, i1?, t2?, i2?, t3?}
- `selected` — boolean, whether this row is the selected/active one
- `onclick` — callback

Each field (t1, i1, t2, i2, t3) can be:
- null/undefined: skip
- string: render as plain text
- object {text, class?, icon?}: render as <span> with optional class and icon prefix
- array of objects: render each as a span, separated by a small gap

Layout of a row:
```
┌──────┬─────────────────────────────┬──────────────┐
│ icon │ t1 (left)                   │ i1 (right)   │
│      │ t2 (left)                   │ i2 (right)   │
│      │ t3 (left, optional)         │              │
└──────┴─────────────────────────────┴──────────────┘
```

- Icon area: 32px wide, only shown if row.icon is set
- t1/t2/t3 take remaining space on the left
- i1/i2 are right-aligned
- t1 is the primary line (font-weight 600)
- t2 is secondary (font-size --shpd-font-size-sm, color --shpd-color-text-secondary)
- t3 is tertiary (font-size --shpd-font-size-sm, color --shpd-color-text-secondary, slightly muted)
- Selected row: highlighted background (--shpd-color-primary with low opacity or --shpd-color-bg-secondary)
- Hover effect on rows
- CSS classes for styled spans: .shpd-viewer-row__span--amount, --muted, --bold, --primary, --success, --warning, --danger

### 2. Create `frontend/src/components/viewer/ViewerDetail.svelte`

Renders the detail panel with tabs.

Props:
- `detail` — the detail object: {tabs: [{id, label, content}]}
- `loading` — boolean

Content type renderers:

**"properties"**: Render groups of label-value pairs
```
Group Title
─────────────
Label:    Value
Label:    Value

Group Title
─────────────
Label:    Value
```

**"table"**: Render a simple HTML table with columns and rows
- Use existing table styling (similar to TableBrowser but simpler — no sorting/pagination)

**"html"**: Render raw HTML content (using {@html})

- Tab bar at the top of the detail panel
- Active tab content below
- Loading spinner while detail is being fetched

### 3. Create `frontend/src/components/viewer/ViewerToolbar.svelte`

Renders the toolbar with action buttons.

Props:
- `actions` — array of action objects [{id, label, icon?, variant?}]
- `onAction` — callback(actionId)

- Renders Button components for each action
- variant maps to Button variant (primary, secondary, danger)

### 4. Create `frontend/src/components/viewer/Viewer.svelte`

Main viewer component — combines all sub-components.

Props:
- `tab` — navigation tab object: {id, label, type: "viewer", viewerId}

State:
- rows: array of formatted rows
- selectedRowId: int|null
- detail: detail object|null
- detailLoading: boolean
- search: string
- hasMore: boolean
- loadingRows: boolean
- loadingMore: boolean (loading next page, vs initial load)
- pageNumber: int (zero-based, current page)
- meta: viewer metadata (filters, toolbar)
- filters: active filter values

#### Behavior:

**On mount:**
1. Fetch meta: GET /_ui/viewer/{viewerId}/meta
2. Fetch first page of rows: GET /_ui/viewer/{viewerId}/rows?page=0

**Search input:**
- Debounce 300ms
- On change: clear rows, clear selection, reset pageNumber to 0, fetch rows with search param
- Replace existing rows (not append)

**Infinite scroll:**
- Detect when user scrolls near bottom of row list
- If hasMore and not already loading: increment pageNumber, fetch next page
- Append new rows to existing list
- Show "Načítám..." indicator at bottom while loading more
- When hasMore is false: show "To je všechno" indicator at bottom

**Row click:**
- Set selectedRowId
- Fetch detail: GET /_ui/viewer/{viewerId}/detail/{id}
- Show detail in right panel

**Toolbar actions:**
- "create": open FormDialog with table from meta, recordId=null
- "edit": open FormDialog with table from meta, recordId=selectedRowId
- Other actions: future (for now just create and edit)
- After FormDialog save: refresh rows

#### Layout:

```
┌─────────────────────────────────────────────────────────┐
│  ViewerToolbar                                          │
├───────────────────────────┬─────────────────────────────┤
│ ┌───────────────────────┐ │                             │
│ │ 🔍 search input     × │ │  ViewerDetail (right panel) │
│ └───────────────────────┘ │                             │
│ ┌───────────────────────┐ │  [Tab1] [Tab2] [Tab3]       │
│ │  ViewerRow            │ │  ┌───────────────────────┐  │
│ │  ViewerRow  (selected)├─┼──│  Detail content        │  │
│ │  ViewerRow            │ │  │                       │  │
│ │  ViewerRow            │ │  │                       │  │
│ │  ...                  │ │  └───────────────────────┘  │
│ └───────────────────────┘ │                             │
└───────────────────────────┴─────────────────────────────┘
```

- Left panel: ~40% width (or fixed ~400px), contains search + scrollable row list
- Right panel: ~60% width, contains detail (shown only when a row is selected)
- If no row is selected, right panel shows "Vyberte záznam" message

CSS: Use CSS custom properties. BEM class names with shpd-viewer prefix.

### 5. Update `frontend/src/components/layout/ContentArea.svelte`

Add viewer support:
```svelte
{#if activeTab?.type === 'viewer'}
    <Viewer tab={activeTab} />
{:else if activeTab?.type === 'table'}
    <TableBrowser tab={activeTab} />
{...}
```

### 6. Update `frontend/src/stores/navigation.svelte.js`

The openTab function should handle both "table" and "viewer" tab types. The tab object for a viewer:
```javascript
{id: "viewer:base.persons", label: "Osoby", type: "viewer", viewerId: "base.persons"}
```

No major changes needed — just make sure the existing openTab/closeTab/activateTab work with viewer tabs.

### 7. Import FormDialog in Viewer.svelte

For the "create" and "edit" toolbar actions, use the existing FormDialog component:
- Import FormDialog
- Add formOpen/editRecordId state (same pattern as TableBrowser)
- The table name comes from viewer meta
- After save: refresh rows list

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Pořadí spouštění

Spouštěj v pořadí: 20 → 21 → 22 → 23 → 24.

Po dokončení:
- V sidebar "Osoby" bude typu "viewer" místo "table"
- Klik otevře Viewer s formátovanými řádky, hledáním, nekonečným skrolováním
- Klik na řádek zobrazí detail vpravo (přehled, kontakty, adresy)
- Toolbar se mění podle vybraného řádku
- Tabulky bez vieweru (settings, users) nadále používají TableBrowser
