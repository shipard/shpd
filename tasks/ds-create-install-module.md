# `ds-create --module` + UI výběr instalačního modulu

## Status / Cíl

Fix existujícího bugu: `shpd-server ds-create` aktuálně zapisuje do
`config/main.json` prázdné `"modules": []`, takže vzniklý DS po
`ds-upgrade` neobsahuje žádné tabulky a je nepoužitelný.

Řešení: přidat option `--module=<id>` (default `install.base`) do CLI
příkazu a dropdown výběru do `/_dev/ds-create/` formuláře. Seznam
instalačních modulů scanovaný z `modules/install/`, sdílený mezi CLI a
web UI přes nový `InstallModuleRegistry`.

## Návaznost

- Vychází z [`dev-dashboard-create-ds.md`](dev-dashboard-create-ds.md)
  — fáze 3 dev dashboardu, hotová. Tento task ji rozšiřuje o výběr
  modulu a fixuje paralelní bug v CLI.
- `ModuleLoader::loadModule()` a `ModuleDefinition` v
  `src/Core/Module/` už existují a parsují `module.jsonc` —
  `InstallModuleRegistry` je nad nimi tenká vrstva.
- **Migrace existujících DS** mimo scope. DS s prázdným `modules: []`
  se opravují ručně editací `main.json`.

## Komponenty

```
src/Core/Module/InstallModuleRegistry.php               ← nový
src/Command/Server/DsCreateCommand.php                  ← rozšířit
src/Command/Server/HelpCommand.php                      ← drobně rozšířit
src/Api/Controller/DevDashboardController.php           ← rozšířit
public/index.php                                        ← drobná úprava (předat modulesDir)
docs/cli.md                                             ← drobně rozšířit
tests/Unit/Core/Module/InstallModuleRegistryTest.php    ← nový
tests/Unit/Command/Server/DsCreateCommandTest.php       ← rozšířit (pokud existuje)
tests/Unit/Api/Controller/DevDashboardControllerTest.php ← rozšířit
```

## Co je potřeba udělat

### 1. Nový soubor: `src/Core/Module/InstallModuleRegistry.php`

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Module;

/**
 * Discovers install modules (top-level bundles in `modules/install/<name>/`).
 * An install module is a regular module with id matching `install.*`.
 */
final class InstallModuleRegistry
{
    public function __construct(
        private readonly string $modulesDir,
    ) {}

    /**
     * @return list<array{id: string, name: string, description: string}>
     *         Sorted by name (case-insensitive).
     */
    public function list(): array
    {
        $installDir = $this->modulesDir . '/install';
        if (!is_dir($installDir)) {
            return [];
        }

        $dirs = glob($installDir . '/*', GLOB_ONLYDIR) ?: [];
        $modules = [];

        foreach ($dirs as $dir) {
            $file = $dir . '/module.jsonc';
            if (!is_file($file)) continue;

            try {
                $def = ModuleLoader::loadModule($dir);
            } catch (\Throwable) {
                // Skip malformed modules — registry is best-effort.
                continue;
            }

            if (!str_starts_with($def->id, 'install.')) {
                continue;
            }

            $modules[] = [
                'id'          => $def->id,
                'name'        => $def->name,
                'description' => $def->description,
            ];
        }

        usort(
            $modules,
            fn(array $a, array $b): int => strcmp(
                mb_strtolower($a['name']),
                mb_strtolower($b['name']),
            ),
        );

        return $modules;
    }

    /**
     * Checks whether a given module id exists in `modules/install/`.
     */
    public function exists(string $moduleId): bool
    {
        if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) {
            return false;
        }
        $suffix = substr($moduleId, strlen('install.'));
        return is_file($this->modulesDir . '/install/' . $suffix . '/module.jsonc');
    }
}
```

### 2. Rozšíření `src/Command/Server/DsCreateCommand.php`

#### Nová option v `configure()`

```php
->addOption(
    'module',
    null,
    InputOption::VALUE_REQUIRED,
    'Install module id (e.g. install.base)',
    'install.base',  // default
)
```

#### Nová protected metoda

```php
protected function getModulesDir(): string
{
    return dirname(__DIR__, 3) . '/modules';
}
```

#### Validace v `execute()` (před vytvořením adresáře)

Vložit po `if (empty($name))` bloku, před `$config->load()`:

```php
$moduleId = (string) $input->getOption('module');

if (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $moduleId)) {
    $output->writeln('<error>Invalid install module id: ' . $moduleId . '</error>');
    $output->writeln('<comment>Must match pattern: install.<name></comment>');
    return Command::FAILURE;
}

$registry = new \Shipard\Core\Module\InstallModuleRegistry($this->getModulesDir());
if (!$registry->exists($moduleId)) {
    $output->writeln('<error>Install module not found: ' . $moduleId . '</error>');
    $available = array_map(fn($m) => $m['id'], $registry->list());
    if ($available) {
        $output->writeln('<comment>Available: ' . implode(', ', $available) . '</comment>');
    } else {
        $output->writeln('<comment>No install modules found in ' . $this->getModulesDir() . '/install/</comment>');
    }
    return Command::FAILURE;
}
```

#### Zápis do main.json

V existujícím `$mainConfig` array (cca řádek 95), přidat `modules` pole
**hned za `name`** (kvůli pořadí v JSON file):

```php
$mainConfig = [
    'id'                => $id,
    'name'              => $name,
    'modules'           => [$moduleId],   // ← new
    'database_name'     => $dbName,
    'database_user'     => $dbUser,
    'database_password' => $password,
    'created'           => date('c'),
];
```

#### Output summary rozšíření

Přidat řádek s modulem do final output (cca řádek 115):

```php
$output->writeln("  ID:            <comment>{$id}</comment>");
$output->writeln("  Name:          <comment>{$name}</comment>");
$output->writeln("  Module:        <comment>{$moduleId}</comment>");   // ← new
$output->writeln("  Database:      <comment>{$dbName}</comment>");
// ...
```

### 3. Update `src/Command/Server/HelpCommand.php`

V sekci Options pro `ds-create` upravit:

```
  ds-create --name=<n> [--module=<id>]      Create a new data source
                                            (--module defaults to install.base)
```

### 4. Rozšíření `DevDashboardController`

#### Konstruktor — přidat modulesDir

```php
public function __construct(
    private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
    private readonly ?string $logFilePath = null,
    private readonly ?string $modulesDir = null,
) {}
```

`?string` aby existující testy z fází 1-3 nebyly rozbité. Pokud
`modulesDir === null`, install-modules endpoint vrátí 503 a
ds-create validace přeskočí kontrolu existence.

#### Nová route v `dispatch()`

```php
if ($path === '/_dev/api/install-modules' && $request->getMethod() === 'GET') {
    return $this->getInstallModules();
}
```

#### Nová metoda `getInstallModules(): Response`

```php
private function getInstallModules(): Response
{
    if ($this->modulesDir === null) {
        return Response::error(
            'MODULES_NOT_CONFIGURED',
            'Modules directory not configured',
            503,
        );
    }

    $registry = new \Shipard\Core\Module\InstallModuleRegistry($this->modulesDir);
    return Response::success($registry->list());
}
```

#### Rozšíření `dsCreate()` validace

V `validateDsCreateInput()` (z fáze 3) přidat parametr a kontrolu:

```php
private function validateDsCreateInput(
    string $name,
    string $login,
    string $password,
    string $module,    // ← new
): array {
    $errors = [];
    // ... existující checks ...

    if ($module === '') {
        $errors[] = 'Install module is required.';
    } elseif (!preg_match('/^install\.[a-z][a-zA-Z0-9]*$/', $module)) {
        $errors[] = 'Invalid install module id.';
    } elseif ($this->modulesDir !== null) {
        $registry = new \Shipard\Core\Module\InstallModuleRegistry($this->modulesDir);
        if (!$registry->exists($module)) {
            $errors[] = 'Install module "' . $module . '" not found.';
        }
    }

    return $errors;
}
```

V `dsCreate()` extrahovat module z body:

```php
$module = is_string($body['module'] ?? null)
    ? trim($body['module'])
    : 'install.base';

$errors = $this->validateDsCreateInput($name, $login, $password, $module);
```

A předat do pipeline:

```php
return Response::stream(function () use ($name, $login, $password, $seed, $module) {
    $this->runDsCreatePipeline($name, $login, $password, $seed, $module);
});
```

#### Update `runDsCreatePipeline()`

Přidat `string $module` parametr, použít ho v `ds-create` volání:

```php
$cmd = sprintf(
    '%s ds-create --name=%s --module=%s --no-ansi 2>&1',
    escapeshellarg($shpdServer),
    escapeshellarg($name),
    escapeshellarg($module),
);
```

### 5. HTML form rozšíření (v `dsCreatePage()`)

Nad pole "Admin login" přidat nový form group:

```html
<div class="form-group">
    <label for="module">Install module *</label>
    <select id="module" name="module" required>
        <option value="">Loading modules...</option>
    </select>
    <p class="hint" id="module-hint"></p>
</div>
```

V CSS přidat:

```css
.hint { font-size: 0.85em; color: #6b7280; margin-top: 4px; }
select { padding: 8px; border: 1px solid #d1d5db; width: 100%; box-sizing: border-box; }
```

V JS:

```js
async function loadInstallModules() {
    const sel = document.getElementById('module');
    try {
        const r = await fetch('/_dev/api/install-modules');
        const result = await r.json();
        const modules = result.data || [];

        sel.innerHTML = '';
        if (modules.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No install modules found';
            sel.appendChild(opt);
            sel.disabled = true;
            return;
        }

        modules.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.name + ' (' + m.id + ')';
            opt.dataset.description = m.description || '';
            sel.appendChild(opt);
        });

        // Default: install.base if present, else first
        const baseIdx = modules.findIndex(m => m.id === 'install.base');
        sel.selectedIndex = baseIdx >= 0 ? baseIdx : 0;
        updateModuleHint();
    } catch (e) {
        sel.innerHTML = '<option value="">Failed to load modules</option>';
        sel.disabled = true;
        console.error(e);
    }
}

function updateModuleHint() {
    const sel = document.getElementById('module');
    const hint = document.getElementById('module-hint');
    const opt = sel.options[sel.selectedIndex];
    hint.textContent = opt?.dataset?.description || '';
}

document.getElementById('module').addEventListener('change', updateModuleHint);
```

Volat `loadInstallModules()` při startu stránky (vedle případné inicializace
formu).

V `submitForm()` přidat `module` do POST body:

```js
const body = JSON.stringify({
    name, login, password, seed,
    module: document.getElementById('module').value,
});
```

V klient-side validaci ověřit, že `module !== ''`:

```js
if (!moduleSel.value) {
    showFormError('Please select an install module.');
    return;
}
```

### 6. Update `public/index.php`

Změnit existující volání:

```php
$response = (new \Shipard\Api\Controller\DevDashboardController(
    '/opt/shipard/data-sources',
    $serverConfig->getLogFile(),
))->dispatch($request);
```

na:

```php
$response = (new \Shipard\Api\Controller\DevDashboardController(
    '/opt/shipard/data-sources',
    $serverConfig->getLogFile(),
    dirname(__DIR__) . '/modules',   // ← new
))->dispatch($request);
```

`dirname(__DIR__)` z `public/index.php` ukáže na repo root.

### 7. Update `docs/cli.md`

V sekci `### ds-create` rozšířit popis o `--module`. Sample text:

```markdown
**Options:**

- `--name=<text>` (required) — display name of the data source
- `--module=<id>` (default: `install.base`) — install module to enable.
  Must match a directory in `modules/install/<suffix>/` whose
  `module.jsonc` has id `install.<suffix>`. Run
  `ls modules/install/` to see available modules.

The created `config/main.json` will contain `"modules": ["<id>"]`. The
install module is a top-level bundle that declares its dependencies
(`core.system`, `core.attachments`, ...), which `ds-upgrade` resolves
transitively.

Example:

\`\`\`bash
sudo shpd-server ds-create --name="My Company" --module=install.base
\`\`\`
```

### 8. Testy

#### `tests/Unit/Core/Module/InstallModuleRegistryTest.php`

`setUp()` vytvoří temp dir přes `sys_get_temp_dir() . '/shpd-reg-test-' . uniqid()`.
`tearDown()` rekurzivně smaže.

Helper `createInstallModule(string $name, array $extra = [])`:
vytvoří `$tmpDir/install/$name/module.jsonc` s validním JSON
(`id: "install.$name"`, `name: "Module $name"`, atd.).

Test cases:

- **`testEmptyModulesDirReturnsEmpty`** — dir bez `install/` → `list()` = `[]`,
  `exists('install.base')` = `false`
- **`testInstallDirWithoutModules`** — vytvoř prázdný `install/` dir → `[]`
- **`testListReturnsInstallModules`** — vytvoř 3 install moduly → 3 items,
  každý s id/name/description
- **`testListSkipsMalformedModuleJsonc`** — vytvoř valid + soubor s rozbitým
  JSON → vrátí jen valid
- **`testListSkipsDirsWithoutModuleJsonc`** — vytvoř adresář bez `module.jsonc`
  → přeskočen
- **`testListSortsByNameCaseInsensitive`** — name "Zebra", "alpha", "Bravo"
  → pořadí alpha, Bravo, Zebra
- **`testExistsReturnsTrueForValidId`** — vytvoř `install.base` → `exists('install.base')`
  = `true`
- **`testExistsReturnsFalseForMissingId`** — `exists('install.nonexistent')`
  = `false`
- **`testExistsRejectsInvalidFormat`** — `exists('core.system')` = `false`,
  `exists('install.')` = `false`, `exists('install.Bad')` = `false` (start
  s velkým písmenem)

#### `tests/Unit/Command/Server/DsCreateCommandTest.php`

**Pokud existuje:** existující testy projít a rozšířit pattern.
**Pokud neexistuje:** vytvořit přes `TestableDsCreateCommand` subclassing
pattern (přepsat `getDataSourcesDir()` a `getModulesDir()`).

V `setUp()` připravit fake modules dir s `install/base/module.jsonc`:

```php
$this->modulesDir = $this->tmpDir . '/modules';
mkdir($this->modulesDir . '/install/base', 0755, true);
file_put_contents(
    $this->modulesDir . '/install/base/module.jsonc',
    json_encode(['id' => 'install.base', 'name' => 'Base'])
);
```

Test cases (nové nebo rozšířené):

- **`testCreatesMainJsonWithDefaultModule`** — execute bez `--module` →
  vytvořený `main.json` má `"modules": ["install.base"]`
- **`testCreatesMainJsonWithExplicitModule`** — vytvoř `install/foo/module.jsonc`,
  execute s `--module=install.foo` → `main.json` má `"modules": ["install.foo"]`
- **`testRejectsInvalidModuleFormat`** — execute s `--module=core.system` →
  FAILURE, output obsahuje "Invalid install module id"
- **`testRejectsNonExistentModule`** — execute s `--module=install.nope` →
  FAILURE, output obsahuje "Install module not found"
- **`testRejectsNonExistentModuleListsAvailable`** — execute s neexistujícím,
  ale `install.base` existuje → output obsahuje "Available: install.base"

#### Rozšíření `tests/Unit/Api/Controller/DevDashboardControllerTest.php`

Helper pro fake modules dir (analogický k DS dir):

```php
private function createInstallModule(string $name): void
{
    $dir = $this->modulesDir . '/install/' . $name;
    mkdir($dir, 0755, true);
    file_put_contents(
        $dir . '/module.jsonc',
        json_encode(['id' => 'install.' . $name, 'name' => 'Module ' . $name])
    );
}
```

V `setUp()` vytvořit oba temp dirs (dataSourcesDir + modulesDir),
v `tearDown()` smazat.

Test cases (nové):

- **`testInstallModulesEndpointReturnsList`** — vytvoř install.base a
  install.foo → `GET /_dev/api/install-modules` vrátí 2 items
- **`testInstallModulesEndpointEmpty`** — žádné install moduly →
  status 200, data: []
- **`testInstallModulesWithoutConfigReturns503`** — controller bez
  `modulesDir` → status 503, error code `MODULES_NOT_CONFIGURED`
- **`testDsCreateValidationMissingModule`** — POST body bez `module` field
  → default `install.base` použit (pokud existuje), jinak validation error
- **`testDsCreateValidationInvalidModuleFormat`** — POST s `module: "bad-id"`
  → 400, error "Invalid install module id"
- **`testDsCreateValidationNonExistentModule`** — POST s `module: "install.zzz"`
  → 400, error "Install module ... not found"
- **`testDsCreatePipelinePassesModuleFlag`** — TestableDevDashboardController
  pipeline, ověř že první command (ds-create) obsahuje `--module=install.base`
  v `commandsRun[0]`

## Co netřeba

- Migrace existujících DS s prázdným `modules: []` — manuální oprava
- Podpora více `--module` flagů — pole v main.json je list-friendly,
  ale CLI/UI přijímá jen jeden install modul
- "Module browser" UI s podrobnostmi instalovaných modulů — můžeme
  ukázat name + description, víc netřeba
- Validace dependencies modulu při ds-create — to dělá `ds-upgrade`
  později

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- `ModuleLoader::loadModule()` pro parsing `module.jsonc`, nepřepisovat
- Validace v dvou vrstvách: regex formátu + `exists()` na disku
- Test pattern: subclassing přes `Testable*Command` /
  `Testable*Controller` s injectovatelným `modulesDir`
- Klient-side validace duplikuje server-side (defense in depth)

## Hotovo když

- `vendor/bin/phpunit` projde, včetně všech nových a rozšíření testů
- `php bin/shpd-server ds-create --name="Test"` projde a vytvořený
  `config/main.json` obsahuje `"modules": ["install.base"]`
- `php bin/shpd-server ds-create --name="Test" --module=install.base`
  funguje stejně (explicit override)
- `php bin/shpd-server ds-create --name="Test" --module=install.nope`
  selže s FAILURE + nápovědou "Available: install.base"
- `php bin/shpd-server ds-create --name="Test" --module=core.system`
  selže s FAILURE + "Invalid install module id"
- `php bin/shpd-server help` zobrazí `--module=<id>` u `ds-create`
- `curl http://{ip}/_dev/api/install-modules` vrátí JSON se seznamem
  modulů
- V prohlížeči `/_dev/ds-create/`:
  - Form má nový dropdown "Install module" hned pod "Name"
  - Dropdown při loadu zobrazí "Loading modules...", pak se vyplní z API
  - Default selected je "Base installation (install.base)"
  - Pod dropdownem je popis vybraného modulu (helper text)
  - Change dropdown → změní helper text
  - Submit pošle `module` ve POST body, pipeline vytvoří DS s
    `"modules": ["install.base"]` v `main.json`
- Po dokončení vytváření přes UI je vzniklý DS funkční (po kliknutí
  "Open" se otevře login screen s validním schématem)
- `docs/cli.md` má v ds-create sekci popis `--module`
