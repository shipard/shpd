# Task: Refactor callsites to ModulePathResolver — Phase 2 of third-party modules support

**Stav:** hotovo

## Context

Phase 1 introduced `Shipard\Core\Module\ModulePathResolver` (see
`src/Core/Module/ModulePathResolver.php` and `tasks/third-party-modules-phase1.md`).
It scans one or more module roots and resolves module ids to absolute paths.

**This is Phase 2: refactor every callsite to use `ModulePathResolver`
instead of the raw `string $modulesBasePath` parameter.** Behaviour must
not change — we still operate with a single root (the in-repo `modules/`
directory). Reading `extraModulesPath` from `server.json` is Phase 3a.

After Phase 2:
- Every place that currently builds paths from `$modulesBasePath . '/' . $group . '/' . $name`
  uses `$resolver->getPath($moduleId)` instead.
- `ModuleLoader::loadAllModules()` takes a `ModulePathResolver`.
- `InstallModuleRegistry` takes a `ModulePathResolver`.
- All commands and controllers carry a `ModulePathResolver` instead of a path string.
- The bootstrap (`public/index.php`) constructs the resolver once.

## Naming conventions

- Constructor / parameter names: `$modulePathResolver` (or `$resolver` when the
  surrounding context already makes the type obvious — e.g. inside a method
  body of a class that has it as a property).
- Property name: `private readonly ModulePathResolver $modulePathResolver;`
- Local variables: `$resolver` is fine.
- Type hint: always `ModulePathResolver` (no nullables; pass an empty-roots
  resolver in tests when needed).

## Strategy

The refactor is mechanical but wide. Walk it bottom-up so each step compiles
before moving on:

1. `ModuleLoader::loadAllModules` API change
2. `InstallModuleRegistry` API change
3. `SchemaLoader::loadResolvedTables` API change
4. `ConfigCompiler::compile` API change
5. API loaders (`TableLoader`, `ViewerLoader`, `FormLoader`, `DocumentLoader`)
6. API controllers (`SettingsController`, `NavigationController`,
   `FormController`, `DevDashboardController`)
7. Commands (`DsUpgradeCommand`, `DsSecretsHealthCommand`,
   `DsSecretsRotateCommand`, `DsCreateCommand`)
8. Bootstrap (`public/index.php`)
9. Tests (integration + unit)

After each step the project should still compile; running tests can wait
until the end.

## Changes in detail

### 1. `src/Core/Module/ModuleLoader.php`

Change signature of `loadAllModules`:

```php
public static function loadAllModules(ModulePathResolver $resolver): array
{
    $modules = [];
    foreach ($resolver->allModuleIds() as $id) {
        $path = $resolver->getPath($id);
        if ($path === null) continue;        // defensive; shouldn't happen
        $modules[] = self::loadModule($path);
    }
    return $modules;
}
```

Drop the old `glob()`-based implementation entirely. `loadModule(string $path)`
stays as-is — it's the single-directory loader used internally.

### 2. `src/Core/Module/InstallModuleRegistry.php`

Replace the `string $modulesDir` constructor parameter with `ModulePathResolver`.
The list/exists logic now drives off `$resolver->allModuleIds()`:

```php
final class InstallModuleRegistry
{
    public function __construct(
        private readonly ModulePathResolver $modulePathResolver,
    ) {}

    public function list(): array
    {
        $modules = [];
        foreach ($this->modulePathResolver->allModuleIds() as $id) {
            if (!str_starts_with($id, 'install.')) continue;
            $path = $this->modulePathResolver->getPath($id);
            if ($path === null) continue;
            try {
                $def = ModuleLoader::loadModule($path);
            } catch (\Throwable) {
                continue;   // best-effort, mirrors current behaviour
            }
            $modules[] = [
                'id'          => $def->id,
                'name'        => $def->name,
                'description' => $def->description,
            ];
        }
        usort($modules, fn($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
        return $modules;
    }

    public function exists(string $moduleId): bool
    {
        if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) return false;
        return $this->modulePathResolver->getPath($moduleId) !== null;
    }
}
```

### 3. `src/Core/Database/SchemaLoader.php`

`loadResolvedTables(string $modulesBasePath, array $directModuleIds)`
→ `loadResolvedTables(ModulePathResolver $resolver, array $directModuleIds)`.
Replace the two `$modulesBasePath . '/' . $group . '/' . $name` constructions
with `$resolver->getPath($module->id)`.

### 4. `src/Core/Config/ConfigCompiler.php`

`compile(array $modules, string $modulesBasePath, …)`
→ `compile(array $modules, ModulePathResolver $resolver, …)`.
Same path replacement.

### 5. API loaders

For each of:
- `src/Api/TableLoader.php`
- `src/Api/ViewerLoader.php`
- `src/Api/FormLoader.php`
- `src/Api/DocumentLoader.php`

Change the `string $modulesBasePath` parameter to `ModulePathResolver $resolver`.
Replace the path constructions with `$resolver->getPath($module->id)`. Pass the
resolver into the `ModuleLoader::loadAllModules()` call.

### 6. API controllers

For each of:
- `src/Api/Controller/SettingsController.php`
- `src/Api/Controller/NavigationController.php`
- `src/Api/Controller/FormController.php`

Change the `string $modulesBasePath` parameter on the public method(s) and
the private helpers it threads through to `ModulePathResolver`. Replace
path constructions with `$resolver->getPath($moduleId)`.

Special case — **`FormController::findJsoncFormPath()`**: this scans the
modules directory looking for a form definition by table name. It currently
does its own `scandir` traversal of the module roots. Replace the body:

```php
private function findJsoncFormPath(string $table, ModulePathResolver $resolver): ?string
{
    foreach ($resolver->allModuleIds() as $moduleId) {
        $moduleDir = $resolver->getPath($moduleId);
        if ($moduleDir === null) continue;
        $candidate = $moduleDir . '/forms/' . $table . '.jsonc';
        if (is_file($candidate)) return $candidate;
    }
    return null;
}
```

(Mirror the existing semantics: first match wins. The resolver gives modules
in alphabetical order — verify with the current behaviour; if today's behaviour
is "main root first, then within root alphabetical", note that the resolver
also gives "earlier root first, within root alphabetical" by construction.
With a single root, both behaviours collapse to the same thing.)

**`DevDashboardController`** (`src/Api/Controller/DevDashboardController.php`):
the `?string $modulesDir = null` constructor parameter becomes
`?ModulePathResolver $modulePathResolver = null`. The two `new InstallModuleRegistry($this->modulesDir)`
calls become `new InstallModuleRegistry($this->modulePathResolver)`. The
nullable stays nullable.

### 7. Commands

For each command, the `protected function getModulesBasePath(): string`
method becomes `protected function getModulePathResolver(): ModulePathResolver`,
returning `new ModulePathResolver([dirname(__DIR__, 3) . '/modules'])`.

For `DsCreateCommand`, the existing `getModulesDir(): string` is renamed
to `getModulePathResolver(): ModulePathResolver` and returns the same
single-root resolver. The two log lines that mention the directory should
be updated to use `$resolver->getRoots()[0] . '/install/'` (the first root)
to keep the human-readable output meaningful.

Commands to update:
- `src/Command/Server/DsCreateCommand.php`
- `src/Command/DataSource/DsUpgradeCommand.php`
- `src/Command/DataSource/DsSecretsHealthCommand.php`
- `src/Command/DataSource/DsSecretsRotateCommand.php`

**Do NOT** touch `src/Command/Server/NextTableIdCommand.php` — that's
Phase 4 territory (it'll get the `--range` flag too, so we'll redo its
internals there).

### 8. Bootstrap — `public/index.php`

Replace:

```php
$modulesBasePath = dirname(__DIR__) . '/modules';
$tables          = TableLoader::load($resolved->config, $modulesBasePath, $language);
$viewerRegistry  = ViewerLoader::load($resolved->config, $modulesBasePath, $language);
$formRegistry    = FormLoader::load($resolved->config, $modulesBasePath);
$documentRegistry = DocumentLoader::load($resolved->config, $modulesBasePath);
```

with:

```php
$modulePathResolver = new ModulePathResolver([dirname(__DIR__) . '/modules']);
$tables             = TableLoader::load($resolved->config, $modulePathResolver, $language);
$viewerRegistry     = ViewerLoader::load($resolved->config, $modulePathResolver, $language);
$formRegistry       = FormLoader::load($resolved->config, $modulePathResolver);
$documentRegistry   = DocumentLoader::load($resolved->config, $modulePathResolver);
```

And add `use Shipard\Core\Module\ModulePathResolver;` to the imports.

Everywhere `$modulesBasePath` is passed onwards (DevDashboard construction,
the `dispatch()` function, `dispatchUi`, `dispatchSettings`, `dispatchForm`,
the recalculate path), update the parameter names and the type hints.

### 9. Tests

#### Unit tests

- **`tests/Unit/Core/Module/ModuleLoaderTest.php`** — wrap the `$this->tmpDir`
  in a `ModulePathResolver` before passing to `loadAllModules`. Adjust
  assertions if needed.
- **`tests/Unit/Core/Module/InstallModuleRegistryTest.php`** — same; the
  constructor now takes a resolver. Verify all tests still cover the
  documented behaviour.
- **`tests/Unit/Command/Server/DsCreateCommandTest.php`** — rename the
  override `getModulesDir()` to `getModulePathResolver()` returning a resolver
  built from the test's temp dir.
- **`tests/Unit/Command/Server/NextTableIdCommandTest.php`** — leave alone
  (Phase 4 will redo it).
- **Any other** unit test that constructs `InstallModuleRegistry` or calls
  `ModuleLoader::loadAllModules` — adjust accordingly.

#### Integration tests

- **`tests/Integration/IntegrationTestCase.php`** — replace
  `$modulesBasePath = dirname(__DIR__, 2) . '/modules';` with a resolver
  built from that path; pass it to `TableLoader::load`.
- **`tests/Integration/Mail/MailEndpointTest.php`** — same treatment.
- Search the test tree for any other occurrences of `modulesBasePath` and
  update them.

## Acceptance criteria

- `grep -rn "string \$modulesBasePath\|string \$modulesDir" --include="*.php" src/`
  returns **no matches** (the only remaining acceptable hits are inside the
  body of `NextTableIdCommand` which we're keeping for Phase 4).
- `grep -rn "ModuleLoader::loadAllModules" --include="*.php"` shows every
  call passing a `ModulePathResolver`.
- `vendor/bin/phpunit` is green (full suite, not just the new test).
- `bin/shpd-server` and `bin/shpd-ds` still start without errors
  (`bin/shpd-server help` is a safe smoke test).
- `bin/shpd-ds ds-upgrade` against an existing data source produces the same
  output as before this refactor (no behavioural changes — the resolver is
  initialised with a single root, identical to the old path).
- No new dependencies in `composer.json`.
- No reads of `server.json`'s `extraModulesPath` — that's Phase 3a.

## What this phase does NOT do

- Does **not** read `extraModulesPath` from `server.json`.
- Does **not** touch `composer.json` or introduce a custom autoloader.
- Does **not** modify `NextTableIdCommand`.
- Does **not** add documentation for third-party modules.

## Gotchas worth watching for

- The path string used to be a parameter; now it's an object reference.
  When commands or tests inject mocks, the override pattern changes
  (returning a resolver, not a string).
- `FormController::findJsoncFormPath` previously had an early-return
  `if ($modulesBasePath === '') return null;` guard. The new code doesn't
  need it — an empty-roots resolver still works (every `getPath()` returns
  null, so the loop exits with `null`). Remove the guard.
- `ConfigCompiler::compile` is called from `DsUpgradeCommand` with the
  resolver — make sure the argument order doesn't shift unintentionally.
- `loadAllModules` no longer uses `glob`; verify alphabetical ordering of
  results is consistent with what consumers expect. (Resolver returns sorted
  ids, so consumers that previously iterated `glob` order may see different
  ordering — but `ModuleResolver::resolve()` does topological sort anyway,
  so the upstream order doesn't matter functionally.)
