# Task: ModulePathResolver — Phase 1 of third-party modules support

## Context

Currently the Shipard module system assumes a single base path for all modules,
hardcoded as `dirname(__DIR__) . '/modules'` (or similar) in many places. We're
adding support for third-party / customer modules that live outside the main
repository, in separately-configured paths.

This is **Phase 1** — introduce a `ModulePathResolver` class that knows about
multiple module roots and can map module IDs to absolute filesystem paths. This
phase is purely additive: no existing callsites are touched yet.

See `docs/modules.md` for the existing module system. The module ID format is
`{group}.{module}` (e.g. `economy.docs`, `core.system`) and the filesystem
layout under any root is `{root}/{group}/{module}/module.jsonc`.

## Goal

Create a new isolated, fully unit-tested `ModulePathResolver` class. No
integration with the rest of the system yet — that comes in Phase 2.

## Files to create

### 1. `src/Core/Module/ModulePathResolver.php`

A class that takes a list of module root paths and provides lookup from module
ID to absolute module directory.

**API:**

```php
namespace Shipard\Core\Module;

final class ModulePathResolver
{
    /**
     * @param list<string> $roots  Absolute paths to module root directories.
     *                             First entry is the "main" root (the in-repo
     *                             modules/ directory); subsequent entries are
     *                             extra roots (third-party / customer modules).
     *
     * @throws \RuntimeException on module-id collision across roots, or when
     *         a configured root does not exist / is not a directory.
     */
    public function __construct(array $roots);

    /**
     * Returns the absolute path to the directory containing `module.jsonc`
     * for the given module ID, or null if no such module exists in any root.
     */
    public function getPath(string $moduleId): ?string;

    /**
     * Returns all discovered module IDs across all roots, sorted alphabetically.
     *
     * @return list<string>
     */
    public function allModuleIds(): array;

    /**
     * Returns the configured roots in their original order.
     *
     * @return list<string>
     */
    public function getRoots(): array;
}
```

**Behaviour requirements:**

- **Construction-time scan.** The constructor scans all roots and builds the
  `moduleId => absolutePath` map. No lazy loading — fail-fast is preferred.
- **Module discovery.** A directory `{root}/{group}/{module}/` is considered
  a module iff it contains a `module.jsonc` file. The module ID is `{group}.{module}`
  derived from the directory names. **Do not** parse `module.jsonc` to read the
  ID — trust the directory layout. (This keeps the resolver fast and decoupled
  from JSONC parsing; the existing `ModuleLoader` validates the ID inside
  `module.jsonc` separately.)
- **Group/module name validation.** Skip directories whose names don't match
  `[a-z][a-z0-9]*` (group) and `[a-z][a-zA-Z0-9]*` (module). This mirrors the
  regex in `ModuleDefinition::fromArray`. Skipped entries are silently ignored
  (no error, no warning) — they may be unrelated directories.
- **Collision handling.** If the same `{group}.{module}` exists in two
  different roots, throw `\RuntimeException` with a message naming the module
  ID and both absolute paths. Example:
  `"Module 'economy.docs' found in multiple roots: '/path/a/economy/docs' and '/path/b/economy/docs'"`
- **Root validation.** Each root must be an existing directory. If a root is
  missing or is a file, throw `\RuntimeException` naming the bad path. (We
  prefer fail-fast over silent skipping for misconfiguration.)
- **Empty roots list.** Allowed — `allModuleIds()` returns `[]`, `getPath()`
  always returns `null`.
- **Path normalisation.** Store roots as given (don't `realpath()` them — keep
  symbolic paths intact for log readability). Internally use forward slashes
  for join.
- **No `glob()`.** Use `scandir()` + `is_dir()` + `is_file()`. We've had issues
  with `glob()` and edge cases before; `scandir` is more predictable.

**Implementation notes:**

- `final class` — no inheritance expected.
- `readonly` private properties for `$roots` and the internal map.
- Use `declare(strict_types=1);`.
- Match the code style of existing module classes (`ModuleLoader`,
  `ModuleDefinition`, `InstallModuleRegistry` in `src/Core/Module/`).

### 2. `tests/Unit/Core/Module/ModulePathResolverTest.php`

Unit tests covering every behaviour spec above. Use `phpunit` (PHPUnit 11,
matching the rest of the project). Mirror the structure of existing tests in
`tests/Unit/Core/Module/` if any exist; otherwise follow the pattern from
`tests/Unit/Command/Server/NextTableIdCommandTest.php`.

**Test fixture strategy.** Create temporary directories using
`sys_get_temp_dir()` + a unique subdirectory per test. Clean up in `tearDown()`.
Build mini module trees by writing empty `module.jsonc` files — the resolver
doesn't parse them, so empty stubs are enough.

**Tests to write (at minimum):**

- `testEmptyRoots` — `new ModulePathResolver([])` → no errors, empty
  `allModuleIds()`, `getPath('anything')` returns `null`.
- `testSingleRootSingleModule` — one root with `economy/docs/module.jsonc`
  → `getPath('economy.docs')` returns the right path, `allModuleIds()` returns
  `['economy.docs']`.
- `testSingleRootMultipleModules` — verify alphabetical sorting of
  `allModuleIds()`.
- `testMultipleRoots` — main root + extra root, each with different modules
  → both are discovered, `getPath()` returns the correct root for each.
- `testCollisionAcrossRootsThrows` — same `group.module` in two roots
  → `RuntimeException`, message contains both paths.
- `testNonexistentRootThrows` — root path that doesn't exist → `RuntimeException`.
- `testRootIsFileThrows` — root path that is a regular file → `RuntimeException`.
- `testRootWithoutModuleJsoncIsIgnored` — `{root}/{group}/{module}/` without
  `module.jsonc` is silently skipped.
- `testInvalidGroupNameIsIgnored` — e.g. directory named `Economy` (uppercase)
  or `123foo` is skipped, no error.
- `testInvalidModuleNameIsIgnored` — same for module-level dir.
- `testNestedNonModuleDirectoriesIgnored` — only `{root}/{group}/{module}/`
  is considered; deeper or shallower paths are ignored.
- `testGetRootsPreservesOrder` — roots are returned in the order they were
  passed in.

## What this phase does NOT do

- Does **not** modify `ModuleLoader`, `ModuleResolver`, `InstallModuleRegistry`,
  or any command/controller.
- Does **not** read `server.json` — that wiring is Phase 2.
- Does **not** touch `composer.json` or the autoloader — that's Phase 3.
- Does **not** add documentation in `docs/modules.md` — that's Phase 5.

## Acceptance criteria

- `vendor/bin/phpunit tests/Unit/Core/Module/ModulePathResolverTest.php` passes.
- Full test suite (`vendor/bin/phpunit`) still passes — no regressions.
- `ModulePathResolver` is only referenced from its own file and its test;
  no other production code imports it yet.
- No new dependencies in `composer.json`.
