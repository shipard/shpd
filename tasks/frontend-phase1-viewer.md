# Fáze 2: Prohlížeč tabulek — Tasky pro Claude Code

**Stav:** hotovo

Navazuje na Fázi 1. Po dokončení budou klikatelné položky v sidebar otevírat prohlížeče tabulek s reálnými daty z API.

---

## Task 9: Navigační store (taby)

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the navigation store for managing open tabs.

Read these files for context:
- frontend/src/components/layout/AppShell.svelte
- frontend/src/components/layout/Sidebar.svelte
- frontend/src/components/layout/ContentArea.svelte
- frontend/src/stores/auth.svelte.js (for store pattern reference)
- docs/frontend.md section 4 (navigation model)

Create `frontend/src/stores/navigation.svelte.js`:

A Svelte 5 runes-based store that manages open tabs in the application.

State:
- `tabs` — $state, array of tab objects: `{id, label, type, table, filter}`
  - id: unique identifier (e.g. "core_system_users" or "economy_docs_heads:INV")
  - label: display name (e.g. "Uživatelé")
  - type: "table" (future: "form", "dashboard")
  - table: table name for API calls (e.g. "core_system_users")
  - filter: optional filter object (e.g. {doc_type: "eq:INV"})
- `activeTabId` — $state, string or null

Exported functions:
- `openTab(item)` — receives a navigation item {id, label, type, table, filter}. If tab with same id already exists, just activate it. Otherwise add new tab and activate it.
- `closeTab(id)` — remove tab by id. If it was active, activate the previous tab (or next, or null if no tabs left).
- `activateTab(id)` — set activeTabId to given id.
- `getActiveTab()` — return the currently active tab object, or null.

Export as a single object `navigationStore` with all state and functions.

Use Svelte 5 runes ($state, $derived). All comments in English.
```

---

## Task 10: Propojení AppShell se sidebar a taby

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), connect the navigation store to the AppShell so that clicking sidebar items opens tabs and the content area shows the active tab.

Read these files:
- frontend/src/stores/navigation.svelte.js (just created)
- frontend/src/components/layout/AppShell.svelte
- frontend/src/components/layout/Sidebar.svelte
- frontend/src/components/layout/ContentArea.svelte
- frontend/src/components/layout/Header.svelte

### Changes to AppShell.svelte

- Import navigationStore
- When sidebar triggers onNavigate, call navigationStore.openTab(item)
- Pass activeId from navigationStore.activeTabId to Sidebar
- In the content area: show tab bar at top (if any tabs are open), show content of active tab below
- For now, the "content of active tab" is just a placeholder div showing the table name — we'll add the real TableBrowser in the next task

### Add a tab bar component

Create `frontend/src/components/layout/TabBar.svelte`:
- Horizontal bar of tabs, each showing the tab label
- Active tab is visually highlighted
- Each tab has a close button (× icon)
- Clicking a tab activates it (navigationStore.activateTab)
- Clicking close removes it (navigationStore.closeTab)
- If no tabs are open, the tab bar is hidden
- Styling: light background, tabs look like browser tabs or IDE tabs
- Use CSS custom properties from variables.css, BEM class names with shpd- prefix

### Update ContentArea.svelte

- Accept `activeTab` prop (the active tab object or null)
- If activeTab is null: show "Vyberte položku v menu" message
- If activeTab exists: show a placeholder for now: `<div>Tabulka: {activeTab.table}</div>`
- Remove the children/slot approach — we'll pass data directly

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 11: TableBrowser — generický prohlížeč tabulek

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), create the generic table browser component that displays data from any table using the existing REST API.

Read these files for context:
- docs/rest-api.md (sections 3, 4, 5 — URL structure, response format, filtering/sorting/pagination)
- docs/frontend.md (section 5 — TableBrowser)
- frontend/src/api/client.js (API client)
- frontend/src/api/config.js (API base URL)
- modules/core/system/tables/core_system_users.jsonc (example table definition)

### Create `frontend/src/components/browser/TableBrowser.svelte`

The main browser component. Receives a tab object as prop and displays a table with data from the API.

Props:
- `tab` — {id, label, type, table, filter}

Behavior on mount (and when tab changes):
1. Fetch table metadata: GET /api/v1/_meta/tables/{table}
2. Fetch data: GET /api/v1/{table}?limit=20&offset=0 (plus any filters from tab.filter)
3. Render the data in a table using the column metadata

State:
- `columns` — array of column definitions from metadata (filtered: exclude id, password_hash, and columns with type "json")
- `rows` — array of data rows from API
- `total` — total count from meta.total
- `limit` — current page size (default 20)
- `offset` — current offset (default 0)
- `sortColumn` — currently sorted column (default null)
- `sortDirection` — 'asc' or 'desc' (default 'asc')
- `loading` — boolean
- `error` — error message or null

### Features

**Data display:**
- Render an HTML <table> with <thead> and <tbody>
- Column headers from metadata (use localized name)
- Format cell values based on column type:
  - varchar, text → plain text
  - int, smallint, bigint → number, right-aligned
  - numeric → formatted with decimal places (use column's scale), right-aligned
  - boolean → "Ano"/"Ne" (or a checkmark/cross icon)
  - date → format as dd.mm.yyyy
  - datetime → format as dd.mm.yyyy hh:mm
  - Default: plain text
- Show "Žádné záznamy" message when table is empty
- Show loading spinner while data is being fetched
- Show error message if API call fails

**Sorting:**
- Click column header to sort by that column
- Click again to toggle asc/desc
- Show sort direction indicator (▲/▼) in active header
- Re-fetch data from API with sort parameter

**Pagination:**
- Show pagination below the table
- Display: "Zobrazeno X–Y z Z záznamů"
- Previous / Next buttons
- Page size selector (20, 50, 100)
- Re-fetch data when page changes

**Filtering (from tab.filter):**
- If tab.filter is provided, include it in every API request
- Format: tab.filter = {doc_type: "eq:INV"} → query param filter[doc_type]=eq:INV

### Build the API URL

Use the client.js get() function. Build the path like:
```
let path = `/${tab.table}?limit=${limit}&offset=${offset}`;
if (sortColumn) path += `&sort=${sortColumn}:${sortDirection}`;
if (tab.filter) {
  for (const [key, value] of Object.entries(tab.filter)) {
    path += `&filter[${key}]=${value}`;
  }
}
```

### Metadata fields to use for column rendering

From the _meta/tables/{table} response, use `data.columns` array. Each column has:
- id: column identifier (use as key for row data)
- name: localized display name (use as header text)
- type: data type (for formatting)
- primaryKey: skip columns where primaryKey is true (don't show id column)

Filter out columns where: id is "password_hash" or name contains "password" or "hash".

### Styling

- Clean table with borders between rows
- Header row with light gray background, bold text
- Hover effect on data rows
- Right-align numeric columns
- Pagination bar below the table
- Use CSS custom properties from variables.css
- BEM class names: .shpd-browser, .shpd-browser__table, .shpd-browser__th, etc.
- Table should scroll horizontally if content overflows

### Update ContentArea.svelte

Replace the placeholder content with the actual TableBrowser component:
- If activeTab exists and activeTab.type === "table", render <TableBrowser tab={activeTab} />
- Import TableBrowser

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Task 12: Aktualizace sidebar navigace

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), update the sidebar navigation to include the "Uživatelé" table from core.system module, which is currently the only table with actual data.

Read:
- frontend/src/components/layout/Sidebar.svelte
- modules/core/system/tables/core_system_users.jsonc
- modules/base/persons/tables/ (these tables exist but may not have data)

Update the hardcoded navTree in Sidebar.svelte:

```javascript
const navTree = [
  {
    id: 'system',
    label: 'Systém',
    children: [
      { id: 'core_system_users', label: 'Uživatelé', type: 'table', table: 'core_system_users' },
      { id: 'core_system_settings', label: 'Nastavení', type: 'table', table: 'core_system_settings' },
    ],
  },
  {
    id: 'base',
    label: 'Základní',
    children: [
      { id: 'base_persons_persons', label: 'Osoby', type: 'table', table: 'base_persons_persons' },
    ],
  },
];
```

Remove the "Ekonomika" group with "Faktury vydané/přijaté" for now — those tables don't exist in the current module set. We'll add them when the economy.docs module is implemented.

Keep all existing sidebar styling and behavior unchanged.
```

---

## Pořadí spouštění

Spouštěj tasky v pořadí 9 → 10 → 11 → 12.

Po dokončení:
- Kliknutí na "Uživatelé" v sidebar otevře tab s prohlížečem tabulky
- Prohlížeč zobrazí data z `core_system_users` (minimálně tvůj admin účet)
- Funguje řazení kliknutím na sloupce
- Funguje stránkování
- Lze otevřít více tabů současně a přepínat mezi nimi
