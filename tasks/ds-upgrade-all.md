# `ds-upgrade-all` příkaz a kompletní CLI dokumentace

## Status / Cíl

Nový příkaz `shpd-server ds-upgrade-all`, který v jednom volání zaktualizuje
schéma a konfiguraci všech datových zdrojů na serveru. Současně synchronizace
zastaralého `shpd-ds help` se skutečným seznamem registrovaných příkazů
a vytvoření centrální CLI reference (`docs/cli.md`).

## Návaznost

- Spárovaný task: [`dev-update-script.md`](dev-update-script.md) — řeší
  body 1–3 z původní diskuze (composer + npm sync). Tento task řeší
  bod 4 (upgrade všech DS) plus dokumentaci.
- Vychází z existujícího `DsUpgradeCommand` v
  `src/Command/DataSource/DsUpgradeCommand.php` — ten zůstává nedotčený,
  nový příkaz ho jen pouští v subprocesu pro každý DS.
- Help command pattern: viz `src/Command/Server/HelpCommand.php`,
  `src/Command/DataSource/HelpCommand.php`.
- Test pattern přes subclassing: viz `TestableDsCreateCommand` zmíněný
  v `CLAUDE.md`.

## Co je potřeba udělat

### 1. Nový soubor: `src/Command/Server/DsUpgradeAllCommand.php`

Symfony Console příkaz. Drží se vzoru ostatních Server příkazů
(viz `DsCreateCommand`, `NextTableIdCommand`).

#### Struktura

- Namespace `Shipard\Command\Server`, strict_types
- Konstruktor bez parametrů
- `configure()`:
  - `setName('ds-upgrade-all')`
  - `setDescription('Run ds-upgrade on all data sources')`
  - Opce:
    - `--ds=<id>` (`InputOption::VALUE_REQUIRED`) — pustit jen na
      konkrétním DS
    - `--stop-on-error` (`VALUE_NONE`) — zastavit při první chybě
      (default: pokračovat)
    - `--dry-run` (`VALUE_NONE`) — jen vypsat, které DS by se upgradovaly

#### Protected metody (kvůli testovatelnosti přes subclassing)

```php
protected function getDataSourcesDir(): string
{
    return '/opt/shipard/data-sources';
}

protected function getShpdDsPath(): string
{
    return dirname(__DIR__, 3) . '/bin/shpd-ds';
}

/**
 * @return array{success: bool, exitCode: int}
 */
protected function runDsUpgrade(string $dsDir, OutputInterface $output): array
{
    $cmd = sprintf(
        'cd %s && %s ds-upgrade',
        escapeshellarg($dsDir),
        escapeshellarg($this->getShpdDsPath())
    );

    $exitCode = 0;
    passthru($cmd, $exitCode);

    return ['success' => $exitCode === 0, 'exitCode' => $exitCode];
}
```

#### `execute()` flow

1. Validace: `is_dir(getDataSourcesDir())` — když ne, error + FAILURE
2. `glob($dir . '/*', GLOB_ONLYDIR)` + `sort()` (deterministické pořadí)
3. Filtruj na adresáře s `config/main.json`
4. Pokud `--ds=<id>`, ponech jen ten jeden
5. Když je seznam prázdný:
   - `--ds=<id>` → "No data source found with ID: <id>", SUCCESS
   - jinak → "No data sources found.", SUCCESS
6. Vypiš seznam DS k upgradu (1 řádek per DS)
7. Když `--dry-run` → vypiš `--dry-run: skipping actual upgrade` a
   return SUCCESS
8. Iteruj DS:
   - Vypiš oddělovač:
     ```
     ==========================================
     ===== Upgrading <id> =====
     ==========================================
     ```
   - Zavolej `runDsUpgrade()`
   - Při úspěchu inkrementuj `$upgraded`
   - Při chybě přidej id do `$failed`. Pokud `--stop-on-error`,
     vypiš zprávu a `break`
9. Závěrem souhrn:
   ```
   ==========================================
   Summary: X upgraded, Y failed
   Failed: <id1>, <id2>
   ==========================================
   ```
10. Return SUCCESS jen když `count($failed) === 0`

### 2. Update `bin/shpd-server`

Přidat:

```php
$app->add(new \Shipard\Command\Server\DsUpgradeAllCommand());
```

Mezi `DsCreateCommand` a `NextTableIdCommand` (DS-related příkazy
pohromadě).

### 3. Update `src/Command/Server/HelpCommand.php`

Do tabulky příkazů přidat:

```
  <info>ds-upgrade-all</info> Run ds-upgrade on all data sources
```

Do sekce Options přidat:

```
  ds-upgrade-all [--ds=<id>] [--stop-on-error] [--dry-run]
                                          Upgrade all data sources
```

Drž se stávajícího stylu (zarovnání, tagy).

### 4. Update `src/Command/DataSource/HelpCommand.php`

**Synchronizace s reálným seznamem příkazů.** Aktuálně Help vypisuje
6 příkazů, ale `bin/shpd-ds` registruje 18. Pro každou `*Command` třídu
v `src/Command/DataSource/` (kromě `VersionCommand` a `HelpCommand`)
přečíst `configure()` metodu, vytáhnout `setName()` a `setDescription()`,
a přidat do helpu. Příkazy seřadit logicky:

1. **Základ:** `version`, `help`, `ds-upgrade`
2. **Users:** `user-create`
3. **Secrets:** `ds-secrets-health`, `ds-secrets-rotate`
4. **Mail:** `mail-router-bootstrap`, `mail-router-setup`,
   `mail-idempotency-prune`
5. **AI Analyzer:** `ai-analyzer-bootstrap`, `ai-analyzer-setup`,
   `ai-analyzer-set-key`, `ai-profile-reload`, `mail-analysis-reap`
6. **Seed:** `seed-persons`, `seed-clear`, `seed-mail`, `seed-mail-clear`

Současně **opravit `<e>` na `<error>`** na konci souboru — `<e>` není
standardní Symfony Console tag.

Existující sekci Examples zachovat (seed příklady), případně přidat 1-2
příklady pro nově zařazené příkazy, pokud mají výrazné options
(např. `ai-analyzer-set-key --key=<api-key>`).

### 5. Nový soubor: `tests/Unit/Command/Server/DsUpgradeAllCommandTest.php`

Vzor: subclassing pattern (CLAUDE.md zmíněný `TestableDsCreateCommand`).

#### TestableDsUpgradeAllCommand

```php
class TestableDsUpgradeAllCommand extends DsUpgradeAllCommand
{
    /** @var array<string, array{success: bool, exitCode: int}> */
    private array $upgradeResults = [];
    public array $callLog = [];

    public function __construct(private readonly string $dataSourcesDir)
    {
        parent::__construct();
    }

    public function setUpgradeResults(array $results): void
    {
        $this->upgradeResults = $results;
    }

    protected function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    protected function runDsUpgrade(string $dsDir, OutputInterface $output): array
    {
        $id = basename($dsDir);
        $this->callLog[] = $id;
        return $this->upgradeResults[$id] ?? ['success' => true, 'exitCode' => 0];
    }
}
```

#### Test scénáře

`setUp()` vytvoří temp dir přes `sys_get_temp_dir() . '/shpd-test-' . uniqid()`.
`tearDown()` rekurzivně smaže.

Helper `createDs(string $id)`: vytvoří `$tmpDir/$id/config/` + prázdný
`main.json`.

Použij `Symfony\Component\Console\Tester\CommandTester` pro spouštění:

```php
$cmd = new TestableDsUpgradeAllCommand($this->tmpDir);
$tester = new CommandTester($cmd);
$tester->execute(['--dry-run' => true]);
$this->assertSame(0, $tester->getStatusCode());
$this->assertStringContainsString('--dry-run', $tester->getDisplay());
```

Test cases:

- **Empty directory** → SUCCESS, output obsahuje "No data sources found"
- **Multiple DS, all succeed** (3 DS) → SUCCESS, output obsahuje
  "3 upgraded, 0 failed", všechny 3 v `callLog`
- **One fails, default behaviour** (3 DS, druhý fail) → FAILURE,
  "1 upgraded" + "Failed: <id>", všechny 3 v `callLog`
- **One fails, --stop-on-error** → FAILURE, jen 2 položky v `callLog`
  (stop po druhém)
- **--ds=<existing>** → běží jen ten jeden, callLog má 1 položku
- **--ds=<nonexistent>** → SUCCESS, output "No data source found with ID:",
  callLog prázdný
- **--dry-run** → SUCCESS, callLog prázdný (žádný DS se nevolal)
- **Skip dirs without config/main.json** → vytvoř DS plus `lost+found`
  adresář bez configu, jen DS se zpracuje

### 6. Nový soubor: `docs/cli.md`

Centrální reference. Cca 500–700 řádků. Struktura:

#### Úvod

Krátký intro: dvě hlavní CLI utility (`shpd-server`, `shpd-ds`) + dva
podpůrné skripty (`dev-update.sh`, `install-packages.sh`). Tabulka 4
nástrojů: jméno, účel, "spouštět z" (libovolný CWD / DS adresář / repo
root jako root).

Poznámka: `install-packages.sh` vytvoří symlinky `/usr/bin/shpd-server`
a `/usr/bin/shpd-ds`. V repu lze nástroje pustit i přímo přes
`php bin/shpd-server`.

#### Sekce: `shpd-server` — kompletní reference

Pro **každý příkaz** podsekce s nadpisem `### <command>`, popisem,
syntaxí (kopírovatelná do shellu), opcemi, kdy/proč spouštět:

- `version`, `help` — krátce
- `server-init` — inicializuje `/etc/shipard/server.json`, generuje
  admin DB credentials, jednou při instalaci serveru
- `ds-create --name <název>` — co generuje (ID, adresář, DB,
  secrets.key, config/main.json), poznámka že po vytvoření je třeba
  `ds-upgrade`
- `ds-upgrade-all` — všechny tři opce (`--ds`, `--stop-on-error`,
  `--dry-run`), implementační poznámka že pouští každý DS v subprocesu
  (havárie jednoho neovlivní ostatní)
- `next-table-id` — pomocný příkaz při tvorbě nových tabulek
- `domain-add`, `domain-list`, `domain-remove`

#### Sekce: `shpd-ds` — kompletní reference

Hlavička: **musí se spouštět z adresáře DS** (musí obsahovat
`config/main.json`), jinak většina příkazů selže s chybou.

Pro **všech 18 příkazů** registrovaných v `bin/shpd-ds` podsekce.
Pro každý: přečti `*Command.php`, vytáhni description z `configure()`,
popiš stručně co dělá. Detailněji rozepsat:

- `ds-upgrade` — všech 6 kroků (modules resolve, table defs +
  extensions, config compile, schema sync, provisioning, secrets
  health), zdůraznit idempotenci
- `seed-persons` — všechny opce s příklady
- `ds-secrets-rotate` — `--dry-run` flag, varování ohledně backupu

#### Sekce: Pomocné skripty

`scripts/dev-update.sh` — co dělá, kdy spouštět, zmínit volitelnou
aktivaci git hooks (`git config core.hooksPath .githooks`).

`scripts/install-packages.sh` — co instaluje (PHP 8.5, MariaDB, nginx,
composer, ext), root požadavek, vytváření symlinků.

#### Sekce: Workflow scénáře

Konkrétní příkazové sekvence:

1. **Po `git pull`**
   ```bash
   bash scripts/dev-update.sh
   sudo shpd-server ds-upgrade-all  # když se měnily moduly
   ```

2. **Vytvoření nového DS**
   ```bash
   sudo shpd-server ds-create --name "Moje firma s.r.o."
   cd /opt/shipard/data-sources/<id>
   sudo shpd-ds ds-upgrade
   sudo shpd-ds user-create --login=admin --password=...
   ```

3. **Upgrade všech DS najednou**
   ```bash
   sudo shpd-server ds-upgrade-all
   ```

4. **Jen jeden konkrétní DS**
   ```bash
   sudo shpd-server ds-upgrade-all --ds=abcd-efgh-ijkl-mnop
   ```

#### Sekce: Konvence

- **Exit codes**: `0` = úspěch, `1` = chyba
- **Výstupní tagy**: `<info>` (zelená), `<comment>` (žlutá), `<error>`
  (červená)
- **Pojmenování**: `ds-*` se týká datového zdroje, `mail-*` mailu,
  `ai-*` AI analyzátoru, `seed-*` testovacích dat, `domain-*`
  doménového routingu, `*-bootstrap` jednorázová inicializace,
  `*-setup` opakovaná konfigurace
- **Idempotence**: všechny `ds-upgrade`, `*-bootstrap`, `*-setup`,
  `seed-*-clear` mají být idempotentní (opakované spuštění bez efektu,
  pokud se nic nezměnilo)

### 7. Update `docs/README.md`

Do hlavní tabulky přidat (umístit logicky — např. mezi `documentation.md`
a `docs-mvp.md`):

```markdown
| [cli.md](cli.md) | CLI nástroje — kompletní reference `shpd-server` a `shpd-ds` příkazů, pomocných skriptů a workflow scénářů |
```

## Konvence k dodržení

- **PHP**: `declare(strict_types=1)`, PSR-4, namespace
  `Shipard\Command\Server`
- **Výstup**: `<info>`/`<comment>`/`<error>` tagy
- **Czech v UI textech a docs, English v kódu a komentářích**
- **Test pattern**: subclassing přes `Testable*Command`, ne mockování
  přes reflexi
- **Symfony Console subprocess**: `passthru()` s `escapeshellarg()`,
  ne dependency `symfony/process`

## Hotovo když

- `vendor/bin/phpunit` — všechny testy procházejí (včetně nových)
- `php bin/shpd-server help` zobrazí `ds-upgrade-all`
- `php bin/shpd-server ds-upgrade-all --dry-run` v prostředí s DSes
  vypíše seznam bez akce
- `php bin/shpd-server ds-upgrade-all --ds=neexistujici` vrátí 0
  a "No data source found"
- `php bin/shpd-ds help` zobrazí všech 18 příkazů, žádný `<e>` tag
- `docs/cli.md` přečteno end-to-end, dává smysl, žádné `<TODO>` nebo
  placeholdery
- `docs/README.md` má funkční odkaz na `cli.md`
