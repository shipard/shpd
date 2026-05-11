# Task: Read extraModulesPath from server.json — Phase 3a of third-party modules support

## Context

Phases 1 and 2 introduced `ModulePathResolver` and wired it through the system
in place of the old `string $modulesBasePath`. Today every callsite still
constructs the resolver with a single root (the in-repo `modules/` directory).

**Phase 3a unlocks the third-party module use case** by reading a list of extra
module roots from `server.json` and feeding them into the resolver. After this
phase, an admin can drop a private module tree onto a server, add its path to
`server.json`, run `ds-upgrade`, and the new module is available.

This phase does **not** touch the autoloader (Phase 3b) and does **not** add
documentation for users (Phase 5).

## Goal

1. New field `extraModulesPath` in `server.json` — optional list of absolute
   paths to additional module roots.
2. `ServerConfig::getExtraModulesPath(): array` reads and validates it.
3. New static factory `ModulePathResolver::fromServerConfig()` builds the
   resolver from the server config plus the well-known main root.
4. Bootstrap and CLI commands use the factory instead of constructing the
   resolver with a hardcoded single root.

## Changes in detail

### 1. `src/Core/Config/ServerConfig.php`

Add a new optional field reader:

```php
/**
 * Additional module root paths beyond the in-repo `modules/` directory.
 * Used to load third-party / customer modules from outside the main
 * repository. Defaults to an empty list.
 *
 * @return list<string>  Absolute paths, in the order given in server.json.
 */
public function getExtraModulesPath(): array
{
    return $this->data['extraModulesPath'] ?? [];
}
```

**Validation in `load()`.** If `extraModulesPath` is present in the JSON, it
must be a list of strings. Anything else → `RuntimeException` with a message
naming the bad shape. (We don't validate that the paths *exist* — that's the
resolver's job, and it fails fast there too.) Implementation:

```php
if (isset($data['extraModulesPath'])) {
    if (!is_array($data['extraModulesPath']) || !array_is_list($data['extraModulesPath'])) {
        throw new \RuntimeException(
            "Server config field 'extraModulesPath' must be a JSON array of strings"
        );
    }
    foreach ($data['extraModulesPath'] as $i => $entry) {
        if (!is_string($entry) || $entry === '') {
            throw new \RuntimeException(
                "Server config 'extraModulesPath[$i]' must be a non-empty string"
            );
        }
    }
}
```

Place this validation after the existing `$required` check, before assigning
`$this->data = $data;`.

### 2. `src/Core/Module/ModulePathResolver.php`

Add a static factory method that combines the main root with extras from
server config:

```php
/**
 * Builds a resolver from a loaded ServerConfig plus the well-known main
 * module root. The main root is always first; extras follow in the order
 * declared in server.json.
 *
 * @param ServerConfig $serverConfig  Must already be loaded.
 * @param string $mainModulesPath     Absolute path to the in-repo modules/ dir.
 */
public static function fromServerConfig(
    ServerConfig $serverConfig,
    string $mainModulesPath,
): self {
    return new self(array_merge(
        [$mainModulesPath],
        $serverConfig->getExtraModulesPath(),
    ));
}
```

Add `use Shipard\Core\Config\ServerConfig;` to the top of the file.

### 3. Bootstrap — `public/index.php`

Replace the current resolver construction:

```php
$modulePathResolver = new ModulePathResolver([dirname(__DIR__) . '/modules']);
```

with:

```php
$modulePathResolver = ModulePathResolver::fromServerConfig(
    $serverConfig,
    dirname(__DIR__) . '/modules',
);
```

`$serverConfig` is already loaded earlier in the bootstrap (the
`$serverConfig->load()` call), so no extra wiring is needed.

### 4. Commands

For each of the four commands that currently build a resolver with a single
hardcoded root, refactor `getModulePathResolver()` to use the factory and
add `?ServerConfig $serverConfig = null` to the constructor (where it's not
already present).

#### `src/Command/Server/DsCreateCommand.php`

`ServerConfig` is already a constructor parameter. Update `getModulePathResolver()`:

```php
protected function getModulePathResolver(): ModulePathResolver
{
    $cfg = $this->serverConfig;
    if ($cfg === null) {
        $cfg = new ServerConfig();
        $cfg->load();
    }
    // $cfg may or may not be loaded depending on caller — but inside execute()
    // the same $cfg is loaded explicitly already. To keep this method usable
    // standalone (e.g. before that load call runs), the null branch loads its
    // own copy. The injected-mock branch assumes the test loaded it.
    return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
}
```

Important: this method is called from `execute()` **before** the existing
`$config->load()` line. After this refactor, the order matters — the resolver
construction needs a loaded `ServerConfig`. Move the `$config->load()` call
up so the same `$config` instance is used for both the resolver and the
later `DatabaseManager` construction. Concretely, restructure the start of
`execute()` like this:

```php
$name = $input->getOption('name');
if (empty($name)) { /* unchanged */ }

$moduleId = (string) $input->getOption('module');
if (!preg_match(/* unchanged */)) { /* unchanged */ }

// Load server config first — needed for resolver, used later too
$config = $this->serverConfig ?? new ServerConfig();
try {
    $config->load();
} catch (\RuntimeException $e) {
    $output->writeln('<error>Failed to load server config: ' . $e->getMessage() . '</error>');
    return Command::FAILURE;
}

$resolver = ModulePathResolver::fromServerConfig($config, dirname(__DIR__, 3) . '/modules');
$registry = new InstallModuleRegistry($resolver);
// ...rest unchanged, $resolver and $config flow through
```

Drop the now-redundant `getModulePathResolver()` method if all its callers
go through this inlined path, or keep it and have `execute()` call
`$this->getModulePathResolver()` after the config load — pick whichever
reads more naturally. (Recommendation: keep the method, have it read
`$this->serverConfig` which is now guaranteed loaded by the time it's
called.) The test override in `DsCreateCommandTest` should keep working
either way.

#### `src/Command/DataSource/DsUpgradeCommand.php`

Add `?ServerConfig $serverConfig = null` to the constructor:

```php
public function __construct(
    private readonly ?DataSourceConfig $dsConfig = null,
    private readonly ?DataSourceConnection $dsConnection = null,
    private readonly ?ServerConfig $serverConfig = null,
) {
    parent::__construct();
}
```

Update `getModulePathResolver()`:

```php
protected function getModulePathResolver(): ModulePathResolver
{
    $cfg = $this->serverConfig;
    if ($cfg === null) {
        $cfg = new ServerConfig();
        $cfg->load();
    }
    return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
}
```

Add `use Shipard\Core\Config\ServerConfig;` to the imports.

#### `src/Command/DataSource/DsSecretsHealthCommand.php`

Same treatment as `DsUpgradeCommand`: add `?ServerConfig` to constructor,
update `getModulePathResolver()` to use the factory.

#### `src/Command/DataSource/DsSecretsRotateCommand.php`

Same treatment.

### 5. Tests

#### `tests/Unit/Core/Config/ServerConfigTest.php`

Add four new tests:

- **`testGetExtraModulesPathDefaultsToEmpty`** — config without the field
  → `getExtraModulesPath()` returns `[]`.
- **`testGetExtraModulesPathReturnsList`** — config with two paths
  → method returns them in order.
- **`testExtraModulesPathRejectsNonArray`** — config with
  `'extraModulesPath' => '/just/a/string'` → `RuntimeException` on `load()`.
- **`testExtraModulesPathRejectsNonStringEntry`** — config with
  `'extraModulesPath' => ['/ok', 123]` → `RuntimeException` on `load()`,
  message mentions the index.
- **`testExtraModulesPathRejectsEmptyStringEntry`** — config with
  `'extraModulesPath' => ['/ok', '']` → `RuntimeException` on `load()`.
- **`testExtraModulesPathRejectsAssociativeArray`** — config with
  `'extraModulesPath' => ['key' => '/path']` → `RuntimeException`
  (because `array_is_list` is false).

#### `tests/Unit/Core/Module/ModulePathResolverTest.php`

Add tests for the new factory:

- **`testFromServerConfigMainOnly`** — pass a ServerConfig with no
  `extraModulesPath`; resolver discovers modules only from main root.
- **`testFromServerConfigCombinesMainAndExtras`** — ServerConfig with two
  extra paths; resolver discovers modules from all three, with main coming
  first in `getRoots()`.
- **`testFromServerConfigOrderMainFirst`** — explicit order check:
  `getRoots()` returns `[$main, $extra1, $extra2]` in exactly that order.

For these tests you'll need a small helper to construct a loaded `ServerConfig`
pointing at a temp file. Match the fixture style in `ServerConfigTest.php`.

#### Existing command tests

The override pattern (`TestableDsUpgradeCommand` and friends overriding
`getModulePathResolver()` to return a custom resolver) continues to work —
the override bypasses the factory entirely. **No changes** needed in:

- `tests/Unit/Command/DataSource/DsUpgradeCommandTest.php`
- `tests/Unit/Command/DataSource/DsSecretsHealthCommandTest.php`
- `tests/Unit/Command/DataSource/DsSecretsRotateCommandTest.php`
- `tests/Unit/Command/Server/DsCreateCommandTest.php`

Verify all four still pass after the refactor — the constructors gained an
optional parameter with a `null` default, which is source-compatible.

## Acceptance criteria

- `vendor/bin/phpunit` is green (full suite).
- A `server.json` with no `extraModulesPath` field works exactly as before
  (smoke test: `bin/shpd-server help` runs without errors).
- A `server.json` with `extraModulesPath: []` works identically.
- A `server.json` with `extraModulesPath: "not a list"` makes
  `ServerConfig::load()` throw with a useful message.
- A `server.json` with a real extra path containing a stub module makes
  `ModulePathResolver::allModuleIds()` include that module's id.
- `grep -n "new ModulePathResolver(\[" public/index.php src/Command/`
  shows no direct constructor calls — all flow through `fromServerConfig()`
  or `getModulePathResolver()`.

## What this phase does NOT do

- Does **not** touch `composer.json` or the autoloader (Phase 3b).
- Does **not** modify `NextTableIdCommand` (Phase 4).
- Does **not** add user-facing docs in `docs/modules.md` (Phase 5).
- Does **not** validate that paths in `extraModulesPath` exist at config-load
  time — that's the resolver's responsibility, and it does this when the
  resolver is constructed (which happens at request/command time).

## Gotchas worth watching for

- **`DsCreateCommand` already loads ServerConfig** in `execute()`. Make sure
  the resolver construction reuses the already-loaded instance, doesn't
  double-load, and the log lines that mention the resolver root stay correct.
- **`ServerConfig::load()` is mutating, not idempotent at zero cost.** If the
  same instance gets loaded twice via different code paths, that's wasted I/O
  but harmless. Don't add an `isLoaded()` check just for this — the existing
  command tests already pre-load the injected mock.
- **`array_is_list` vs `array_values`.** We validate with `array_is_list` to
  reject associative arrays — don't silently normalise them with `array_values`,
  the user wrote something wrong and should know.
- **Bootstrap order in `public/index.php`.** `$serverConfig->load()` happens
  in step 2, well before the resolver is constructed in step 4. Order
  shouldn't change, but verify no helper functions get called with the
  resolver before that point.
