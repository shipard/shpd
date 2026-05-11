# Task: next-table-id across all roots + --range flag — Phase 4 of third-party modules support

## Context

`bin/shpd-server next-table-id` allocates a globally-unique `tableId` for new
table definitions. Today it scans only the in-repo `modules/` directory via
`glob('modules/*/*/tables/*.jsonc')` and returns `max(tableId) + 1`.

With third-party modules in extra roots (Phases 1–3b), this is now wrong:
the command can return an id that's already used by a private module that
the operator doesn't see. We also want to support **reserved ranges** so
that core, customer, and vendor modules can allocate ids without colliding.

**Phase 4 reworks `NextTableIdCommand` to:**
1. Scan all roots via `ModulePathResolver` (including `extraModulesPath`).
2. Accept a `--range=N:M` option for range-constrained allocation.
3. Document the reserved-range convention in the command help.

## The reserved-range convention

This is **convention only** — not enforced at the schema level. The command's
job is to make allocation within ranges convenient.

| Range            | Purpose                              |
|------------------|--------------------------------------|
| `1 – 9 999`      | Core (official Shipard modules)      |
| `10 000 – 19 999`| Custom (in-house customer modules)   |
| `20 000 – 29 999`| Vendor (third-party paid modules)    |
| `30 000 – 65 535`| Reserve                              |

`tableId` is `SMALLINT` in the database, so the hard upper bound is `65535`.

## Files to modify

### 1. `src/Command/Server/NextTableIdCommand.php`

Rewrite to use `ModulePathResolver` and add the `--range` option:

```php
<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NextTableIdCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('next-table-id')
             ->setDescription('Print the next available table ID')
             ->setHelp(/* see below */)
             ->addOption(
                 'range',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Allocate within an inclusive range N:M (e.g. 10000:10099)',
             );
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resolver = $this->getModulePathResolver();

        $rangeOption = $input->getOption('range');
        $range = null;
        if ($rangeOption !== null) {
            $range = self::parseRange($rangeOption);
            if ($range === null) {
                $output->writeln('<error>Invalid --range format. Expected N:M with 1 <= N <= M <= 65535.</error>');
                return Command::FAILURE;
            }
        }

        $used = self::collectUsedIds($resolver);

        if ($range !== null) {
            [$low, $high] = $range;
            for ($i = $low; $i <= $high; $i++) {
                if (!isset($used[$i])) {
                    $output->writeln((string) $i);
                    return Command::SUCCESS;
                }
            }
            $output->writeln("<error>No free tableId in range $low:$high (all $high - $low + 1 slots taken)</error>");
            return Command::FAILURE;
        }

        $next = empty($used) ? 1 : (max(array_keys($used)) + 1);
        $output->writeln((string) $next);
        return Command::SUCCESS;
    }

    /**
     * Returns [low, high] or null on parse failure.
     *
     * @return array{int,int}|null
     */
    private static function parseRange(string $raw): ?array
    {
        if (!preg_match('/^(\d+):(\d+)$/', $raw, $m)) return null;
        $low  = (int) $m[1];
        $high = (int) $m[2];
        if ($low < 1 || $high > 65535 || $low > $high) return null;
        return [$low, $high];
    }

    /**
     * Walks all modules in all roots and returns a map of
     * `tableId => path of the .jsonc file declaring it`.
     *
     * Duplicates are silently overwritten by the later occurrence — this
     * command's job is allocation, not validation. ds-upgrade has its
     * own collision detection.
     *
     * @return array<int, string>
     */
    private static function collectUsedIds(ModulePathResolver $resolver): array
    {
        $used = [];
        foreach ($resolver->allModuleIds() as $moduleId) {
            $modulePath = $resolver->getPath($moduleId);
            if ($modulePath === null) continue;
            $tablesDir = $modulePath . '/tables';
            if (!is_dir($tablesDir)) continue;
            $entries = @scandir($tablesDir) ?: [];
            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '.jsonc')) continue;
                $file = $tablesDir . '/' . $entry;
                if (!is_file($file)) continue;
                try {
                    $data = JsoncParser::parseFile($file);
                } catch (\Throwable) {
                    continue;
                }
                if (isset($data['tableId']) && is_int($data['tableId']) && $data['tableId'] > 0) {
                    $used[$data['tableId']] = $file;
                }
            }
        }
        return $used;
    }
}
```

#### `setHelp()` text

Use a heredoc:

```
Prints the next available tableId across all configured module roots
(the in-repo modules/ directory plus any extra paths listed in
server.json's extraModulesPath).

Reserved tableId ranges (convention, not enforced):
      1 -  9 999  Core (official Shipard modules)
  10 000 - 19 999 Custom (in-house customer modules)
  20 000 - 29 999 Vendor (third-party paid modules)
  30 000 - 65 535 Reserve

Use --range to constrain the search to a specific range. Useful
when allocating IDs for a customer or vendor module:

  bin/shpd-server next-table-id --range=10000:10099

Without --range, the command returns max(used) + 1 across all roots,
or 1 if no tableIds exist yet.
```

Format the text so it renders well in the terminal. No Symfony console
markup (`<info>`, `<comment>`) — keep it plain so `--help` reads cleanly
in non-color terminals.

### 2. `tests/Unit/Command/Server/NextTableIdCommandTest.php`

The existing `TestableNextTableIdCommand` overrides `getModulesBasePath()`.
Replace with an override of `getModulePathResolver()`:

```php
class TestableNextTableIdCommand extends NextTableIdCommand
{
    /** @param list<string> $roots */
    public function __construct(private array $roots)
    {
        parent::__construct();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return new ModulePathResolver($this->roots);
    }
}
```

This unlocks multi-root tests.

**Adapt existing tests:**

- `testNoModulesReturnsOne` — fixture: empty roots list (or single root
  with no modules). Expected output: `1`. Mind that `ModulePathResolver`
  requires roots to exist as directories — pass `[$this->tempDir]` and
  leave it empty.
- `testReturnsNextAfterExisting` — needs `module.jsonc` in each module
  directory for the resolver to discover them. Update `createTableFile()`
  to also write a stub `module.jsonc` if not present.
- `testSkipsNonIntTableId` — same update.

**New tests:**

- `testScansMultipleRoots` — main root has table with `tableId=5`, extra
  root has table with `tableId=10`. Without `--range`, returns `11`.
- `testRangeReturnsFirstFree` — used ids `[10000, 10001, 10003]` in range
  `10000:10099` → command returns `10002`.
- `testRangeReturnsLowestWhenEmpty` — no ids in range `10000:10099`
  → command returns `10000`.
- `testRangeSkipsIdsOutsideRange` — used ids `[5, 12000]`, range
  `10000:10099` → command returns `10000` (5 and 12000 are outside).
- `testRangeFullReturnsFailure` — every id in `100:103` is used → command
  returns `FAILURE`, output mentions "No free tableId".
- `testRangeInvalidFormat` — `--range=abc`, `--range=10:5`, `--range=0:5`,
  `--range=100:70000` → each returns `FAILURE`, output mentions
  "Invalid --range format". (Parametrise with a data provider or inline
  loop — your call.)

**Test fixture stub.** The resolver discovers modules by looking for
`module.jsonc` in `{root}/{group}/{module}/`. Each test that needs the
resolver to find a module must write an (empty) `module.jsonc` to that
directory. Adjust the existing `createTableFile()` helper:

```php
private function createTableFile(string $root, string $group, string $module, string $table, mixed $tableId): void
{
    $moduleDir = $root . '/' . $group . '/' . $module;
    $tablesDir = $moduleDir . '/tables';
    if (!is_dir($tablesDir)) {
        mkdir($tablesDir, 0755, true);
    }
    if (!is_file($moduleDir . '/module.jsonc')) {
        file_put_contents($moduleDir . '/module.jsonc', '');
    }
    $content = is_int($tableId)
        ? '{"tableId": ' . $tableId . ', "name": "Test"}'
        : '{"name": "Test"}';
    file_put_contents($tablesDir . '/' . $table . '.jsonc', $content);
}
```

(Note: helper now takes the root as its first arg, so multi-root tests can
target different roots.)

## Acceptance criteria

- `vendor/bin/phpunit tests/Unit/Command/Server/NextTableIdCommandTest.php`
  is green.
- Full test suite (`vendor/bin/phpunit`) still green.
- `bin/shpd-server next-table-id` against the real repo returns the same
  number as before this change (because the resolver, without
  `extraModulesPath`, scans the same files).
- `bin/shpd-server next-table-id --range=10000:10099` returns `10000`
  (since no in-repo tables use ids in that range).
- `bin/shpd-server next-table-id --range=1:9999` returns the same value
  as the unconstrained call (since all current tables fit in that range
  and the algorithm gives `max+1`, but the range-constrained version gives
  the first hole — verify these agree by inspecting current `tableId`s in
  the repo; if there are no holes below `max`, both algorithms agree).
- `bin/shpd-server help next-table-id` shows the reserved-ranges table
  and a `--range` example.
- `bin/shpd-server next-table-id --range=garbage` exits non-zero with
  a useful error.

## What this phase does NOT do

- Does **not** enforce range conventions at the schema level — `ds-upgrade`
  doesn't reject a `tableId` for being "in the wrong range". The convention
  is purely a coordination tool.
- Does **not** add user-facing docs in `docs/modules.md` — that's Phase 5.
- Does **not** detect tableId collisions. `SchemaValidator` already does
  that during `ds-upgrade`; `next-table-id` is allocation, not validation.

## Gotchas worth watching for

- **Resolver requires existing directories.** Test fixtures must `mkdir`
  every root before constructing the resolver. Empty roots are fine
  (resolver returns an empty module list).
- **`module.jsonc` is required for module discovery.** Tests that previously
  only wrote `tables/*.jsonc` will silently fail to discover anything
  through the resolver. The updated `createTableFile()` writes both.
- **`scandir` order is filesystem-dependent.** Don't write tests that depend
  on iteration order — the only thing that matters is the final set of
  `tableId`s collected.
- **`max(array_keys($used))`** when `$used` is empty would warn. The
  `empty($used)` guard handles that — preserve it.
- **`SMALLINT` upper bound is 65535.** The `--range` validator enforces it.
  The unconstrained `max+1` path does NOT enforce it — if someone allocates
  past 65535 without a range, that's a separate problem (would need a
  `SchemaValidator` check, out of scope here).
- **Performance.** The command scans every `.jsonc` in every `tables/`
  directory across every root. With ~100 modules × ~10 tables = ~1000
  files, this is fine. Don't over-engineer caching.
