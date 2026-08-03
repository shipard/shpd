# Task: Custom module class autoloader — Phase 3b of third-party modules support

**Stav:** hotovo

## Context

After Phases 1–3a, the system has a `ModulePathResolver` that knows about a
main module root plus optional `extraModulesPath` from `server.json`. But the
PHP class autoloader still uses static per-module PSR-4 entries in
`composer.json`:

```jsonc
"autoload": {
    "psr-4": {
        "Shipard\\": "src/",
        "Shipard\\Module\\Base\\Persons\\": "modules/base/persons/src/",
        "Shipard\\Module\\Core\\System\\": "modules/core/system/src/",
        // ... 11 more lines, one per module
    }
}
```

This means adding a third-party module would still require editing
`composer.json` and re-running `composer dump-autoload`. We want admins to
add private modules by editing a single `server.json` field — no composer
ceremony.

**Phase 3b replaces the per-module PSR-4 entries with a custom autoloader
that consults the `ModulePathResolver`.** After this phase:

- `composer.json` has exactly one `Shipard\\` mapping, and no per-module
  entries.
- `ModuleClassLoader` registers an `spl_autoload_register` handler that
  maps `Shipard\Module\{Group}\{Module}\Foo\Bar` → `{root}/{group}/{module}/src/Foo/Bar.php`,
  resolving `{root}` via `ModulePathResolver`.
- All entry points (web bootstrap, CLI bin scripts, test bootstrap) register
  the loader as early as possible.

## Namespace → directory convention

The existing convention encoded in `composer.json` is:

| Namespace component | Directory component | Conversion |
|---|---|---|
| `Base` (group) | `base` | `lcfirst($group)` |
| `Persons` (module) | `persons` | `lcfirst($module)` |
| `InvoicesOut` (module) | `invoicesOut` | `lcfirst($module)` |
| `Foo\Bar` (rest) | `Foo/Bar.php` | unchanged, `\` → `/` |

`lcfirst` (lowercase the first letter, keep the rest) matches every existing
entry, including `Docs\InvoicesOut → docs/invoicesOut` and
`Install\Base → install/base`. Use `lcfirst` for both segments.

## Files to create

### 1. `src/Core/Module/ModuleClassLoader.php`

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Autoloader for module PHP classes. Maps `Shipard\Module\{Group}\{Module}\…`
 * to `{root}/{group}/{module}/src/…` using a ModulePathResolver to find the
 * root for each module.
 *
 * Registered as a single spl_autoload_register handler. Calling register()
 * multiple times replaces the resolver but does not stack handlers.
 */
final class ModuleClassLoader
{
    private const NS_PREFIX = 'Shipard\\Module\\';

    private static ?ModulePathResolver $resolver = null;
    private static bool $registered = false;

    /**
     * Registers (or re-registers) the autoloader with the given resolver.
     * Idempotent — subsequent calls swap the resolver in place.
     */
    public static function register(ModulePathResolver $resolver): void
    {
        self::$resolver = $resolver;
        if (!self::$registered) {
            spl_autoload_register([self::class, 'loadClass']);
            self::$registered = true;
        }
    }

    /**
     * Resets the loader to its initial state. Intended for tests only.
     */
    public static function reset(): void
    {
        if (self::$registered) {
            spl_autoload_unregister([self::class, 'loadClass']);
            self::$registered = false;
        }
        self::$resolver = null;
    }

    /**
     * Autoload callback. Returns void; PHP doesn't care about return value.
     */
    public static function loadClass(string $class): void
    {
        if (self::$resolver === null) return;
        if (!str_starts_with($class, self::NS_PREFIX)) return;

        $remainder = substr($class, strlen(self::NS_PREFIX));
        $parts = explode('\\', $remainder);

        // Need at least Group, Module, and one class name segment.
        if (count($parts) < 3) return;

        $group  = lcfirst($parts[0]);
        $module = lcfirst($parts[1]);
        // Defensive: skip anything that doesn't look like a valid id segment.
        if (!preg_match('/^[a-z][a-z0-9]*$/', $group)) return;
        if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $module)) return;

        $modulePath = self::$resolver->getPath("$group.$module");
        if ($modulePath === null) return;

        $relative = implode('/', array_slice($parts, 2)) . '.php';
        $file     = $modulePath . '/src/' . $relative;

        if (is_file($file)) {
            require $file;
        }
    }
}
```

Style notes:
- `final class` matching `ModulePathResolver`.
- `declare(strict_types=1);`.
- `reset()` exists for test isolation. Production code never calls it.

### 2. `tests/Unit/Core/Module/ModuleClassLoaderTest.php`

Unit tests covering the loader behaviour. Strategy: build mini module trees
in `sys_get_temp_dir()` with stub PHP files containing real classes, then
register the loader and instantiate them.

**Important**: use **unique class names per test** (e.g. include `uniqid()`
in the class name) to avoid PHP's "Cannot declare class" error across tests
within the same process. Or — better — give each test its own namespace by
generating a unique module name. Concretely: in each test create a module
named `vendor.modXYZ` where XYZ is unique, with a class
`Shipard\Module\Vendor\ModXYZ\Demo` written to disk.

Call `ModuleClassLoader::reset()` in `tearDown()` so each test starts clean.

**Tests to write (minimum):**

- **`testLoadsClassFromMainRoot`** — single root + stub class file →
  `class_exists()` is true.
- **`testLoadsClassFromExtraRoot`** — main root with no class + extra root
  with a class → class loads from the extra root.
- **`testReturnsSilentlyForUnknownClass`** — autoloader is registered but
  the class doesn't exist on disk → no error, `class_exists()` is false.
- **`testIgnoresClassesOutsideModuleNamespace`** — class like
  `App\Foo\Bar` (no `Shipard\Module\` prefix) → loader doesn't touch it.
- **`testIgnoresClassesWithTooFewSegments`** — `Shipard\Module\Foo` or
  `Shipard\Module\Foo\Bar` (no class segment) → no fatal error, no load.
- **`testHandlesNestedClass`** — class at
  `Shipard\Module\Vendor\ModX\Sub\Nested\Deep` → loads from
  `{root}/vendor/modX/src/Sub/Nested/Deep.php`.
- **`testRegisterIsIdempotent`** — calling `register()` twice doesn't stack
  two handlers (after `reset()`, only one removal is needed). Verify by
  checking `spl_autoload_functions()` count before/after.
- **`testReRegisterSwapsResolver`** — register with resolver A, then with
  resolver B; classes from B's root start loading, classes from A's root
  (if not also in B) stop loading.
- **`testInvalidGroupOrModuleNameIgnored`** — class
  `Shipard\Module\InvalidGroup\foo\Bar` where the group fails the regex
  → loader doesn't touch it.

For the on-disk stub file contents, write a minimal PHP class like:

```php
<?php
namespace Shipard\Module\Vendor\Mod${UNIQUE};
class Demo {
    public static function ping(): string { return 'pong'; }
}
```

Then assert `\Shipard\Module\Vendor\Mod${UNIQUE}\Demo::ping() === 'pong'`.

## Files to modify

### 3. `bin/shpd-server` and `bin/shpd-ds`

Both bin scripts should register the module autoloader as early as possible,
**before** the Symfony console application is built. Best-effort load of
`ServerConfig` so `extraModulesPath` is honoured; fall back to a main-only
resolver if the config doesn't exist yet (e.g. during initial server setup,
before `server-init` has been run).

Pattern for both scripts — add this right after `require_once __DIR__ . '/../vendor/autoload.php';`:

```php
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;

try {
    $sc = new ServerConfig();
    $sc->load();
    $resolver = ModulePathResolver::fromServerConfig(
        $sc, dirname(__DIR__) . '/modules'
    );
} catch (\Throwable) {
    // server.json not yet present (initial setup) or unreadable.
    // Main-only autoload is enough for commands like server-init and help.
    $resolver = new ModulePathResolver([dirname(__DIR__) . '/modules']);
}
ModuleClassLoader::register($resolver);
```

Keep the existing `use Symfony\Component\Console\Application;` and the
existing command-registration block unchanged.

### 4. `public/index.php`

The web bootstrap loads `ServerConfig` in section 2 ("Server config") and
currently builds the resolver inside section 4. Refactor so the resolver is
built right after `$serverConfig->load()` and used to register
`ModuleClassLoader` immediately:

Move the resolver construction up to right after the
`ErrorLogger::setLogLevel(...)` line:

```php
// (after ErrorLogger setup, still inside the try block)
$modulePathResolver = ModulePathResolver::fromServerConfig(
    $serverConfig, dirname(__DIR__) . '/modules'
);
ModuleClassLoader::register($modulePathResolver);
```

Then in sections 2.5 (dev dashboard) and 4 (load tables etc.), use the
existing `$modulePathResolver` variable instead of constructing fresh
instances. Today section 2.5 constructs one via `ModulePathResolver::fromServerConfig(...)`
inline; replace with `$modulePathResolver`. Section 4 currently has
`$modulePathResolver = ModulePathResolver::fromServerConfig(...)`; delete
that line (or convert to a sanity comment), since the variable already
exists.

Add the import:
```php
use Shipard\Core\Module\ModuleClassLoader;
```

### 5. `tests/bootstrap.php`

Tests use only in-repo modules (no `extraModulesPath`), so a main-only
resolver is enough:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;

ModuleClassLoader::register(
    new ModulePathResolver([dirname(__DIR__) . '/modules'])
);
```

### 6. `composer.json` — cleanup

Remove all per-module PSR-4 entries. The final `autoload` section should be:

```json
"autoload": {
    "psr-4": {
        "Shipard\\": "src/"
    }
}
```

The `autoload-dev` section stays unchanged:

```json
"autoload-dev": {
    "psr-4": {
        "Shipard\\Tests\\": "tests/"
    }
}
```

After editing, run **`composer dump-autoload`** to regenerate
`vendor/composer/autoload_psr4.php` without the stale entries. Verify
that the regenerated file has no `Shipard\\Module\\` keys.

## Order of operations — important

Do the steps in this order to avoid a broken intermediate state:

1. **Create `ModuleClassLoader`** (src + test).
2. **Run the new test alone**: `vendor/bin/phpunit tests/Unit/Core/Module/ModuleClassLoaderTest.php` — must pass.
3. **Register the loader in bin scripts, public/index.php, and tests/bootstrap.php.**
4. **Run the full test suite** — must still pass. At this point both
   composer's PSR-4 entries AND our custom loader are active. Either could
   resolve module classes; both should agree.
5. **Edit `composer.json`** to remove the per-module entries.
6. **Run `composer dump-autoload`**.
7. **Run the full test suite again** — must still pass. Now only the custom
   loader resolves module classes.
8. **Smoke test the bin scripts**: `bin/shpd-server help` and
   `bin/shpd-ds help` should run without error.

If step 7 fails after step 6, the most likely cause is a class file at an
unexpected path or with an unusual namespace casing. Check
`vendor/composer/autoload_psr4.php` (before step 6) for the expected paths
and verify the autoloader replicates them.

## Acceptance criteria

- `vendor/bin/phpunit` is green.
- `bin/shpd-server help` and `bin/shpd-ds help` run without errors.
- `composer.json` has exactly one PSR-4 entry under `autoload`:
  `"Shipard\\": "src/"`. No `Shipard\\Module\\` keys remain.
- `vendor/composer/autoload_psr4.php` (post-`dump-autoload`) contains no
  `Shipard\\Module\\` keys.
- `grep -rn "Shipard\\\\\\\\Module\\\\\\\\" composer.json` returns no matches.
- `ModuleClassLoader::register(...)` is called at most once per bootstrap
  path (bin scripts, public/index.php, tests/bootstrap.php — once each).

## What this phase does NOT do

- Does **not** modify `NextTableIdCommand` (Phase 4).
- Does **not** add user-facing documentation (Phase 5).
- Does **not** change the namespace → directory convention. Existing modules
  keep working with no rename.

## Gotchas worth watching for

- **Test isolation.** `ModuleClassLoader` keeps static state. If a test
  registers a resolver and forgets to reset, the next test sees stale data.
  Make sure `tearDown()` calls `ModuleClassLoader::reset()` in the new
  test file. The default registration from `tests/bootstrap.php` is
  effectively a one-time global setup — restore it in `tearDown()` if a
  test had to reset. (Practical pattern: in `tearDown()`, call `reset()`
  then re-register with the main-modules resolver.)
- **Composer's `Shipard\\: src/` is unaffected.** Don't accidentally remove
  the catch-all entry — production code in `src/` depends on it.
- **`composer dump-autoload` is required.** Without it, the stale
  `autoload_psr4.php` keeps shadowing our loader. Test step 7 will catch
  this.
- **No `require_once`.** The autoloader uses `require` not `require_once`
  because PHP only calls an autoloader once per class anyway. (`require_once`
  also works but adds a tiny perf cost.)
- **`spl_autoload_register` order.** PHP calls autoloaders in registration
  order. Our loader runs after composer's, which is fine — composer will
  try the `Shipard\\` prefix and miss for `Shipard\Module\…`, then PHP
  falls through to us. After the cleanup, composer's `Shipard\\` prefix
  still won't match `Shipard\Module\…` (PSR-4 requires exact prefix +
  remaining segments to exist on disk under `src/`), so the fallthrough
  continues to work.
