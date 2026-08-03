# Fáze 3: Dynamická navigace ze serveru — Tasky pro Claude Code

**Stav:** hotovo

Navigace v sidebar se automaticky vygeneruje z modulového systému na serveru a frontend ji načte přes API.

---

## Task 18: Server — NavigationController a endpoint `_ui/navigation`

**Prompt pro Claude Code:**

```
In the Shipard backend, create a new API endpoint that returns the sidebar navigation tree for the frontend, generated automatically from the module system.

Read these files for context:
- src/Api/Router.php (how routes are registered)
- src/Api/Route.php (route data class)
- src/Api/Controller/MetaController.php (example controller)
- src/Api/TableLoader.php (how modules and tables are loaded)
- src/Core/Module/ModuleDefinition.php (module structure: id, name, tables)
- src/Core/Module/ModuleLoader.php (loading all modules)
- src/Core/Module/ModuleResolver.php (resolving active modules for a data source)
- src/Core/I18n/ConfigLocalizer.php (localization)
- modules/core/system/module.jsonc (example module)
- modules/base/persons/module.jsonc (example module with more tables)
- public/index.php (dispatch function — how controllers are called)
- docs/frontend.md section 4 (expected navigation JSON format)

### 1. Create `src/Api/Controller/NavigationController.php`

A controller that generates the sidebar navigation tree from active modules.

Method: `navigation(array $resolvedModules, string $modulesBasePath, string $language): Response`

Parameters:
- `$resolvedModules` — array of ModuleDefinition objects (already resolved for this data source)
- `$modulesBasePath` — path to modules/ directory
- `$language` — requested language for localization

#### Navigation tree generation logic:

The tree has two levels: **groups** (top-level, derived from module group) and **items** (tables within modules).

Module ID format is `{group}.{name}` (e.g., "core.system", "base.persons"). The first segment is the group.

Algorithm:
1. Group resolved modules by their group prefix (first segment of module ID)
2. For each group, create a navigation node:
   - `id`: group name (e.g., "core", "base", "economy")
   - `label`: localized group label — for now, derive from the first module in the group. Use a hardcoded map for known groups as a start: `{"core": {"cs": "Systém", "en": "System"}, "base": {"cs": "Základní", "en": "Basic"}, "economy": {"cs": "Ekonomika", "en": "Economy"}, "world": {"cs": "Svět", "en": "World"}}`. If group is not in the map, capitalize the group name.
   - `children`: array of module sub-groups and/or table items

3. For each module within a group:
   - If the group has only one module, its tables go directly as children of the group node
   - If the group has multiple modules, create a sub-group node for each module:
     - `id`: module ID (e.g., "core.system")
     - `label`: localized module name (from module.jsonc, using the `name:{lang}` field)
     - `children`: table items

4. For each table in a module, create a leaf item:
   - `id`: table name (e.g., "core_system_users")
   - `label`: localized table name (load from table JSONC definition, use `name:{lang}` field)
   - `type`: "table"
   - `table`: table name (same as id for now)

5. To load localized table names: for each table, read its .jsonc file and extract the localized `name` field using ConfigLocalizer.

6. Skip internal/system tables that users shouldn't see in navigation:
   - Skip tables whose name contains "sessions" (e.g., core_system_sessions)
   - Skip tables whose name contains "rate_limits" (e.g., core_system_rate_limits)
   - Skip tables whose name contains "api_keys" (e.g., core_system_api_keys)

#### Example response:

```json
{
    "success": true,
    "data": [
        {
            "id": "core",
            "label": "Systém",
            "children": [
                {"id": "core_system_users", "label": "Uživatelé", "type": "table", "table": "core_system_users"},
                {"id": "core_system_settings", "label": "Nastavení", "type": "table", "table": "core_system_settings"}
            ]
        },
        {
            "id": "base",
            "label": "Základní",
            "children": [
                {"id": "base_persons_persons", "label": "Osoby", "type": "table", "table": "base_persons_persons"},
                {"id": "base_persons_contacts", "label": "Kontakty", "type": "table", "table": "base_persons_contacts"},
                {"id": "base_persons_bank_accounts", "label": "Bankovní účty", "type": "table", "table": "base_persons_bank_accounts"},
                {"id": "base_persons_addresses", "label": "Adresy", "type": "table", "table": "base_persons_addresses"}
            ]
        }
    ]
}
```

### 2. Update `src/Api/Router.php`

Add a new route for `/_ui/navigation`:
```php
if ($subpath === '/_ui/navigation') {
    if ($method !== 'GET') {
        return Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
    return new Route('ui', 'navigation');
}
```

### 3. Update `public/index.php`

Add the `ui` controller to the dispatch function. The NavigationController needs resolved modules and modules base path, not just table definitions.

In index.php:
- After resolving modules in TableLoader (step 4), also resolve the module list separately for the NavigationController. Or refactor to expose the resolved modules.

The simplest approach: create a new static method or modify TableLoader to also return the resolved modules. OR load modules directly in the dispatch function for the 'ui' controller.

Simplest: in `dispatch()`, add a case for 'ui' controller. The NavigationController needs the resolved modules, which are currently computed inside TableLoader::load().

Create a new helper or refactor: add a `NavigationLoader` class (similar to `TableLoader`) that loads resolved modules and generates navigation, OR pass the required data through.

Recommended approach — add a new function in index.php:

```php
'ui' => dispatchUi($route->action, $resolved->config, $modulesBasePath, resolveLanguage($request)),
```

```php
function dispatchUi(string $action, DataSourceConfig $config, string $modulesBasePath, string $language): Response {
    $ctrl = new NavigationController();
    return match ($action) {
        'navigation' => $ctrl->navigation($config, $modulesBasePath, $language),
        default => Response::error('INTERNAL_ERROR', "Unknown UI action: {$action}", 500),
    };
}
```

And let NavigationController internally resolve modules and load table names.

### 4. Auth requirement

The `_ui/navigation` endpoint requires authentication (same as other endpoints). The existing AuthMiddleware should handle this — no special exceptions needed.

### Conventions
- PHP 8.5+, strict_types, PSR-4
- All code and comments in English
- Follow existing patterns from MetaController, CrudController
- No tests needed for this task (integration endpoint)
```

---

## Task 19: Frontend — Sidebar načítá navigaci ze serveru

**Prompt pro Claude Code:**

```
In the Shipard frontend project (`frontend/`), update the Sidebar component to load its navigation tree from the server API instead of using hardcoded data.

Read these files:
- frontend/src/components/layout/Sidebar.svelte (current hardcoded sidebar)
- frontend/src/components/layout/AppShell.svelte (parent component)
- frontend/src/api/client.js (API client)

### Changes to Sidebar.svelte

1. Replace the hardcoded `navTree` constant with a `$state` variable initialized to an empty array
2. On mount, fetch navigation from the API: `GET /_ui/navigation`
3. Set navTree from the response data
4. Show a loading state while fetching (simple spinner or "Načítám..." text)
5. Show error state if fetch fails
6. Initialize `expanded` set with all top-level group IDs after loading (so all groups start expanded)

The API response format matches what the sidebar already expects:
```json
{
    "success": true,
    "data": [
        {
            "id": "core",
            "label": "Systém",
            "children": [
                {"id": "core_system_users", "label": "Uživatelé", "type": "table", "table": "core_system_users"}
            ]
        }
    ]
}
```

The existing rendering logic (groups, sub-groups, items, expand/collapse) should work unchanged — only the data source changes from hardcoded to API.

### Important
- The API call requires authentication (the user is already logged in at this point)
- Use the `get()` function from api/client.js
- Handle the case where the API returns an error (show a message in the sidebar)
- Keep all existing styling unchanged

Use Svelte 5 runes. All comments in English. UI text in Czech.
```

---

## Pořadí spouštění

Spouštěj tasky v pořadí 18 → 19.

Po dokončení:
- Sidebar se načítá dynamicky ze serveru
- Položky odpovídají modulům a tabulkám aktivním pro daný zdroj dat
- Přidání nového modulu nebo tabulky do systému automaticky aktualizuje navigaci
- Interní tabulky (sessions, api_keys, rate_limits) se nezobrazují
