# Server setup, módy a oprávnění

**Stav:** hotovo

## Status / Cíl

Vyřešit dlouhodobou bolest s oprávněními a uživateli na shpd serveru.
Zavést striktní rozlišení mezi `production` a `development` módem,
jasně určit kdo (který OS user) vlastní `/opt/shipard/` a běží jako
PHP-FPM, a poskytnout nástroje na diagnostiku (`doctor`) a opravu
(`fix-permissions`) rozbitých stavů. Cíl: po one-time root setupu **už
nikdy nepotřebovat sudo** pro běžné shipard operace.

## Návaznost

- Vychází z analýzy reálného problému: dnes vývojář (`sebik`) a
  PHP-FPM (`www-data`) jsou dva různí uživatelé, vývojář to ručně
  obchází přes `chown sebik:www-data` + group write + `chmod 750`
  na `secrets/` (které má být `700`). Křehké.
- `mode` v `/etc/shipard/server.json` už existuje a `ServerConfig::getMode()`
  ho čte. Dev dashboard (fáze 1-3) ho používá jako gate. Tento task
  ho povýší na **architektonický signál** pro celý setup.
- Stávající `install-packages.sh` instaluje jen balíky a vytváří
  symlinky — žádný user/dir setup. Tento task ho výrazně rozšíří.
- `shpd-server server-init` existuje (generuje admin DB credentials
  a `/etc/shipard/server.json`). Drobně se upraví aby nastavoval
  správné ownership/mode výsledku.

## Architektura

### Single-user model

V obou módech je **jediný OS user** vlastníkem `/opt/shipard/` a
běží jako PHP-FPM pool. Žádné group sdílení, žádné permission triky.

| Mód | Vlastník | Detekce |
|-----|----------|---------|
| `production` | systémový uživatel `shipard` (automaticky vytvořený) | `--mode=production` v `install-packages.sh` |
| `development` | vývojářský uživatel (`sebik` apod.) | `--mode=development`, automaticky z `$SUDO_USER` |

Pojem **"shipard-user"** v dalším textu = ten správný uživatel pro
aktuální mód.

### Permission matrix (závazný kontrakt)

| Cesta | Owner | Group | Mode | Poznámka |
|-------|-------|-------|------|----------|
| `/etc/shipard/` | `root` | shipard-user | `0750` | adresář |
| `/etc/shipard/server.json` | `root` | shipard-user | `0640` | admin DB credentials |
| `/opt/shipard/` | shipard-user | shipard-user | `0751` | root datový adresář (others=x kvůli nginx traversal pro `/opt/shipard/shpd/public`) |
| `/opt/shipard/data-sources/` | shipard-user | shipard-user | `0750` | parent všech DS |
| `/opt/shipard/data-sources/<id>/` | shipard-user | shipard-user | `0750` | per-DS |
| `/opt/shipard/data-sources/<id>/config/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/data-sources/<id>/config/main.json` | shipard-user | shipard-user | `0600` | obsahuje DB heslo |
| `/opt/shipard/data-sources/<id>/config/configuration/` | shipard-user | shipard-user | `0750` | compiled configs |
| `/opt/shipard/data-sources/<id>/config/configuration/*.json` | shipard-user | shipard-user | `0640` | |
| `/opt/shipard/data-sources/<id>/secrets/` | shipard-user | shipard-user | `0700` | striktní |
| `/opt/shipard/data-sources/<id>/secrets/secrets.key` | shipard-user | shipard-user | `0600` | encryption key |
| `/opt/shipard/data-sources/<id>/att/` | shipard-user | shipard-user | `0750` | writable, dir |
| `/opt/shipard/data-sources/<id>/cache/` | shipard-user | shipard-user | `0750` | writable, dir |
| `/opt/shipard/data-sources/<id>/cache/thumbnails/` | shipard-user | shipard-user | `0750` | writable, dir |
| `/opt/shipard/log/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/log/shipard.log` | shipard-user | shipard-user | `0640` | append-only kontrakt |
| `/etc/php/8.5/fpm/pool.d/shipard.conf` | `root` | `root` | `0644` | system config |
| `/etc/nginx/sites-available/shipard.conf` | `root` | `root` | `0644` | system config |

Tato matice je **závazný kontrakt** — `doctor` kontroluje, `fix-permissions`
vynucuje.

### Komponenty

```
scripts/install-packages.sh                     ← rozšířit výrazně
scripts/php-fpm-pool.conf.template              ← nový (template)
src/Command/Server/ServerInitCommand.php        ← drobně upravit
src/Command/Server/DoctorCommand.php            ← nový
src/Command/Server/FixPermissionsCommand.php    ← nový
src/Command/Server/HelpCommand.php              ← rozšířit
src/Core/Server/HealthChecker.php               ← nový (sdílená logika)
src/Core/Server/PermissionSpec.php              ← nový (kontrakt jako kód)
bin/shpd-server                                 ← registrace nových commands
docs/cli.md                                     ← přepis sekcí + 2 nové commands
DEVELOPERS.md                                   ← přepis "Setup" sekce
tests/Unit/Core/Server/HealthCheckerTest.php    ← nový
tests/Unit/Command/Server/DoctorCommandTest.php ← nový
tests/Unit/Command/Server/FixPermissionsCommandTest.php ← nový
```

## Co je potřeba udělat

### 1. `src/Core/Server/PermissionSpec.php` — kontrakt jako kód

Třída, která vrátí permission matici pro daný režim. Sdílená mezi
`doctor`, `fix-permissions` a testy.

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Encodes the permission contract from docs.
 *
 * @phpstan-type SpecEntry array{path: string, type: 'dir'|'file', owner: 'root'|'user', group: 'user', mode: int, optional?: bool}
 */
final class PermissionSpec
{
    public function __construct(
        private readonly string $shipardUser,
        private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
        private readonly string $logDir = '/opt/shipard/log',
        private readonly string $configDir = '/etc/shipard',
    ) {}

    /**
     * @return list<SpecEntry>
     */
    public function getGlobalEntries(): array
    {
        return [
            ['path' => $this->configDir,                 'type' => 'dir',  'owner' => 'root', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->configDir . '/server.json','type' => 'file', 'owner' => 'root', 'group' => 'user', 'mode' => 0640],
            ['path' => '/opt/shipard',                   'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->dataSourcesDir,            'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->logDir,                    'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->logDir . '/shipard.log',   'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0640, 'optional' => true],
        ];
    }

    /**
     * @return list<SpecEntry>
     */
    public function getDataSourceEntries(string $dsDir): array
    {
        return [
            ['path' => $dsDir,                              'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $dsDir . '/config',                  'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $dsDir . '/config/main.json',        'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0600],
            ['path' => $dsDir . '/config/configuration',    'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/secrets',                 'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0700, 'optional' => true],
            ['path' => $dsDir . '/secrets/secrets.key',     'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0600, 'optional' => true],
            ['path' => $dsDir . '/att',                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/cache',                   'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/cache/thumbnails',        'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
        ];
    }

    public function getShipardUser(): string { return $this->shipardUser; }
    public function getDataSourcesDir(): string { return $this->dataSourcesDir; }
    public function getConfigDir(): string { return $this->configDir; }
    public function getLogDir(): string { return $this->logDir; }

    /**
     * Resolves 'user' → $shipardUser, 'root' → 'root'.
     */
    public function resolveOwner(string $token): string
    {
        return $token === 'user' ? $this->shipardUser : $token;
    }

    /**
     * Glob všech existujících `config/main.json` v `dataSourcesDir`.
     * @return list<string> DS root directories
     */
    public function discoverDataSources(): array
    {
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        $found = [];
        foreach ($dirs as $d) {
            if (is_file($d . '/config/main.json')) {
                $found[] = $d;
            }
        }
        sort($found);
        return $found;
    }
}
```

### 2. `src/Core/Server/HealthChecker.php` — diagnostika

Sdílená logika mezi `doctor` (jen čte) a `fix-permissions` (čte + opravuje).

```php
<?php
declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * @phpstan-import-type SpecEntry from PermissionSpec
 * @phpstan-type Issue array{severity: 'ok'|'warn'|'error', path: string, message: string, fixable: bool}
 */
final class HealthChecker
{
    public function __construct(
        private readonly PermissionSpec $spec,
    ) {}

    /**
     * @return list<Issue>
     */
    public function checkAll(): array
    {
        $issues = [];

        // Existence + ownership + mode pro globální cesty
        foreach ($this->spec->getGlobalEntries() as $entry) {
            $issues = array_merge($issues, $this->checkEntry($entry));
        }

        // Per-DS cesty
        foreach ($this->spec->discoverDataSources() as $dsDir) {
            foreach ($this->spec->getDataSourceEntries($dsDir) as $entry) {
                $issues = array_merge($issues, $this->checkEntry($entry));
            }
        }

        return $issues;
    }

    /**
     * @param SpecEntry $entry
     * @return list<Issue>
     */
    private function checkEntry(array $entry): array
    {
        $path = $entry['path'];
        $optional = $entry['optional'] ?? false;

        if (!file_exists($path)) {
            if ($optional) return [];
            return [[
                'severity' => 'error',
                'path' => $path,
                'message' => 'does not exist',
                'fixable' => false,  // fix-permissions nevytváří chybějící povinné cesty
            ]];
        }

        $issues = [];

        // Type
        $actualType = is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'other');
        if ($actualType !== $entry['type']) {
            $issues[] = [
                'severity' => 'error',
                'path' => $path,
                'message' => "expected {$entry['type']}, found {$actualType}",
                'fixable' => false,
            ];
            return $issues;  // další kontroly nemají smysl
        }

        // Owner
        $expectedOwner = $this->spec->resolveOwner($entry['owner']);
        $stat = stat($path);
        $actualOwner = posix_getpwuid($stat['uid'])['name'] ?? (string) $stat['uid'];
        if ($actualOwner !== $expectedOwner) {
            $issues[] = [
                'severity' => 'error',
                'path' => $path,
                'message' => "owner {$actualOwner}, expected {$expectedOwner}",
                'fixable' => true,
            ];
        }

        // Group
        $expectedGroup = $this->spec->resolveOwner($entry['group']);
        $actualGroup = posix_getgrgid($stat['gid'])['name'] ?? (string) $stat['gid'];
        if ($actualGroup !== $expectedGroup) {
            $issues[] = [
                'severity' => 'error',
                'path' => $path,
                'message' => "group {$actualGroup}, expected {$expectedGroup}",
                'fixable' => true,
            ];
        }

        // Mode
        $actualMode = $stat['mode'] & 0777;
        if ($actualMode !== $entry['mode']) {
            $issues[] = [
                'severity' => 'error',
                'path' => $path,
                'message' => sprintf('mode %04o, expected %04o', $actualMode, $entry['mode']),
                'fixable' => true,
            ];
        }

        return $issues;
    }

    /**
     * Pokusí se najít čitelnost server.json pro PHP-FPM. Read-only kontrola,
     * která ale vyžaduje fork/spustit jako shipard-user — což v doctorovi
     * nemůžeme bez sudo, takže jen vrátíme heuristiku z owner/mode.
     */
    public function checkServerJsonReadability(): array
    {
        // Bonus check: existuje pool config? jaký user?
        // Implementace: scanovat /etc/php/*/fpm/pool.d/shipard.conf,
        // parse user = X, porovnat s spec->shipardUser
        // Vrátit issue pokud nesedí.
        // ...
    }
}
```

### 3. `src/Command/Server/DoctorCommand.php` — read-only diagnostika

```php
<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;
// ...

class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('doctor')
             ->setDescription('Diagnose shpd server configuration and permissions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Shipard server health check</info>');
        $output->writeln(str_repeat('═', 55));

        // 1. Server config
        $configFile = '/etc/shipard/server.json';
        if (!is_file($configFile)) {
            $output->writeln("<error>Config file missing: {$configFile}</error>");
            $output->writeln('<comment>→ Run: sudo bash scripts/install-packages.sh --mode=development</comment>');
            return Command::FAILURE;
        }

        // Detekce mode
        $configContent = @file_get_contents($configFile);
        if ($configContent === false) {
            $output->writeln("<error>Config file not readable: {$configFile}</error>");
            $output->writeln('<comment>→ Check group membership or run as root</comment>');
            return Command::FAILURE;
        }
        $config = json_decode($configContent, true);
        $mode = $config['mode'] ?? 'unknown';

        $output->writeln("");
        $output->writeln("  Mode:                {$mode}");

        // Detekce shipard-user
        $shipardUser = $this->detectShipardUser($mode);
        $output->writeln("  Shipard user:        {$shipardUser}");

        // Detekce PHP-FPM pool user
        $poolUser = $this->detectPoolUser();
        $poolUserStatus = ($poolUser === $shipardUser) ? '✓' : '✗ (mismatch)';
        $output->writeln("  PHP-FPM pool user:   " . ($poolUser ?? 'not configured') . "   {$poolUserStatus}");

        $output->writeln("");
        $output->writeln('<info>Filesystem checks</info>');

        $spec = new PermissionSpec($shipardUser);
        $checker = new HealthChecker($spec);
        $issues = $checker->checkAll();

        if (count($issues) === 0) {
            $output->writeln('  All paths OK ✓');
        } else {
            foreach ($issues as $issue) {
                $marker = $issue['severity'] === 'error' ? '✗' : '⚠';
                $output->writeln("  {$marker} {$issue['path']}: {$issue['message']}");
            }
        }

        // Per-DS DB connection check
        $output->writeln("");
        $output->writeln('<info>Data source DB connections</info>');
        $dsErrors = $this->checkDataSourceConnections($spec, $output);

        // Souhrn
        $output->writeln("");
        $output->writeln(str_repeat('─', 55));
        $totalIssues = count($issues) + $dsErrors;
        if ($totalIssues === 0) {
            $output->writeln('<info>✓ All checks passed.</info>');
            return Command::SUCCESS;
        }

        $fixableCount = count(array_filter($issues, fn($i) => $i['fixable']));
        $output->writeln("<error>Issues found: {$totalIssues}</error>");
        if ($fixableCount > 0) {
            $output->writeln("<comment>→ Run: sudo shpd-server fix-permissions</comment>");
        }
        return Command::FAILURE;
    }

    private function detectShipardUser(string $mode): string
    {
        if ($mode === 'production') {
            return 'shipard';
        }
        // development — detekce z owner /opt/shipard
        if (is_dir('/opt/shipard')) {
            $stat = stat('/opt/shipard');
            $name = posix_getpwuid($stat['uid'])['name'] ?? null;
            if ($name !== null) return $name;
        }
        return posix_getlogin() ?: 'unknown';
    }

    private function detectPoolUser(): ?string
    {
        // Scan /etc/php/*/fpm/pool.d/shipard.conf
        foreach (glob('/etc/php/*/fpm/pool.d/shipard.conf') ?: [] as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;
            if (preg_match('/^\s*user\s*=\s*(\S+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output): int
    {
        $errors = 0;
        foreach ($spec->discoverDataSources() as $dsDir) {
            $id = basename($dsDir);
            try {
                $dsConfig = new \Shipard\Core\Config\DataSourceConfig($dsDir);
                $conn = new \Shipard\Core\Database\DataSourceConnection($dsConfig);
                $conn->fetchRow('SELECT 1');
                $output->writeln("  ✓ {$id}");
            } catch (\Throwable $e) {
                $output->writeln("  ✗ {$id}: " . $e->getMessage());
                $errors++;
            }
        }
        return $errors;
    }
}
```

### 4. `src/Command/Server/FixPermissionsCommand.php` — opravy

```php
<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

// ...

class FixPermissionsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('fix-permissions')
             ->setDescription('Fix ownership and modes in /opt/shipard and /etc/shipard')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without applying')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Require root (chown vyžaduje root)
        $dryRun = (bool) $input->getOption('dry-run');
        if (!$dryRun && posix_geteuid() !== 0) {
            $output->writeln('<error>This command must be run as root (use sudo)</error>');
            $output->writeln('<comment>Or use --dry-run to preview changes</comment>');
            return Command::FAILURE;
        }

        // Detekce mode + shipard user (z server.json + os)
        // ... stejně jako v doctorovi

        $output->writeln("Target user: <comment>{$shipardUser}</comment>");
        $output->writeln("Mode:        <comment>{$mode}</comment>");
        $output->writeln('');

        $spec = new PermissionSpec($shipardUser);
        $checker = new HealthChecker($spec);
        $issues = array_filter($checker->checkAll(), fn($i) => $i['fixable']);

        if (count($issues) === 0) {
            $output->writeln('<info>Nothing to fix — all paths already match the contract.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("Will apply " . count($issues) . " changes:");
        foreach ($issues as $issue) {
            $output->writeln("  {$issue['path']}: {$issue['message']}");
        }

        if (!$dryRun && !$input->getOption('force')) {
            $output->writeln('');
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Proceed? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $output->writeln('<info>--dry-run: no changes applied</info>');
            return Command::SUCCESS;
        }

        // Apply
        $applied = 0;
        foreach ($spec->getGlobalEntries() as $entry) {
            $applied += $this->fixEntry($entry, $spec, $output);
        }
        foreach ($spec->discoverDataSources() as $dsDir) {
            foreach ($spec->getDataSourceEntries($dsDir) as $entry) {
                $applied += $this->fixEntry($entry, $spec, $output);
            }
        }

        $output->writeln('');
        $output->writeln("<info>Applied {$applied} fixes.</info>");
        return Command::SUCCESS;
    }

    private function fixEntry(array $entry, PermissionSpec $spec, OutputInterface $output): int
    {
        $path = $entry['path'];
        if (!file_exists($path) && ($entry['optional'] ?? false)) {
            return 0;
        }
        if (!file_exists($path)) {
            $output->writeln("  <error>SKIP {$path}: does not exist (cannot create)</error>");
            return 0;
        }

        $count = 0;
        $expectedOwner = $spec->resolveOwner($entry['owner']);
        $expectedGroup = $spec->resolveOwner($entry['group']);

        if (chown($path, $expectedOwner) !== false) {
            $output->writeln("  chown {$expectedOwner} {$path}");
            $count++;
        }
        if (chgrp($path, $expectedGroup) !== false) {
            $output->writeln("  chgrp {$expectedGroup} {$path}");
            $count++;
        }
        if (chmod($path, $entry['mode']) !== false) {
            $output->writeln(sprintf("  chmod %04o {$path}", $entry['mode']));
            $count++;
        }
        return $count;
    }
}
```

**Detekce "old layout"** (group hack se `www-data`):
Implementuje se jako vedlejší efekt — `fix-permissions` prostě
přepíše ownership na shipard-user, ať byl předtím cokoli. Žádná
explicitní migrace, jen "uveď do souladu s kontraktem".

### 5. Registrace v `bin/shpd-server`

```php
$app->add(new \Shipard\Command\Server\DoctorCommand());
$app->add(new \Shipard\Command\Server\FixPermissionsCommand());
```

### 6. Drobná úprava `ServerInitCommand.php`

Po `file_put_contents('/etc/shipard/server.json', ...)`:

1. Zjisti shipard-user (z env `$SUDO_USER` nebo z `--user` flagu, default `shipard`)
2. `chown('/etc/shipard', 'root')` + `chgrp('/etc/shipard', $user)` + `chmod 0750`
3. `chown('/etc/shipard/server.json', 'root')` + `chgrp(..., $user)` + `chmod 0640`

Přidat option `--user=<name>` (default detekuje z `$SUDO_USER` env).

Mode v server.json: také přijmout přes `--mode=development|production` (default `development`).

### 7. Přepis `scripts/install-packages.sh`

Větší rozšíření. Strukturně:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Parse args
MODE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode=*) MODE="${1#*=}" ;;
        --mode) MODE="$2"; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done

# Interaktivní fallback
if [ -z "$MODE" ]; then
    echo "Select mode:"
    echo "  1) development (single developer)"
    echo "  2) production (dedicated shipard user)"
    read -rp "Mode [1/2]: " choice
    case "$choice" in
        1) MODE="development" ;;
        2) MODE="production" ;;
        *) echo "Invalid"; exit 1 ;;
    esac
fi

# Require root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: must be run as root (sudo bash scripts/install-packages.sh --mode=$MODE)"
    exit 1
fi

# 1. apt packages (existující sekce)
# ...

# 2. Determine shipard user
if [ "$MODE" = "production" ]; then
    SHIPARD_USER="shipard"
    if ! id "$SHIPARD_USER" >/dev/null 2>&1; then
        useradd --system --shell /usr/sbin/nologin --home-dir /opt/shipard "$SHIPARD_USER"
    fi
elif [ "$MODE" = "development" ]; then
    SHIPARD_USER="${SUDO_USER:-$(logname 2>/dev/null || true)}"
    if [ -z "$SHIPARD_USER" ] || [ "$SHIPARD_USER" = "root" ]; then
        echo "Error: cannot determine developer user. Run via sudo, or set SUDO_USER explicitly."
        exit 1
    fi
    if ! id "$SHIPARD_USER" >/dev/null 2>&1; then
        echo "Error: user '$SHIPARD_USER' does not exist."
        exit 1
    fi
else
    echo "Error: invalid mode '$MODE'"
    exit 1
fi

# 3. Create directories
mkdir -p /etc/shipard
mkdir -p /opt/shipard/data-sources
mkdir -p /opt/shipard/log

chown "$SHIPARD_USER:$SHIPARD_USER" /opt/shipard
chown "$SHIPARD_USER:$SHIPARD_USER" /opt/shipard/data-sources
chown "$SHIPARD_USER:$SHIPARD_USER" /opt/shipard/log
chmod 0750 /opt/shipard /opt/shipard/data-sources /opt/shipard/log

chown "root:$SHIPARD_USER" /etc/shipard
chmod 0750 /etc/shipard

# 4. Symlinks (existující sekce)
# ...

# 5. PHP-FPM pool
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
POOL_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/shipard.conf"

cat > "$POOL_FILE" <<EOF
[shipard]
user = $SHIPARD_USER
group = $SHIPARD_USER

listen = /run/php/php${PHP_VERSION}-fpm-shipard.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

php_admin_value[error_log] = /var/log/php${PHP_VERSION}-fpm-shipard.log
php_admin_flag[log_errors] = on
EOF

chmod 0644 "$POOL_FILE"

systemctl restart "php${PHP_VERSION}-fpm"

# 6. nginx site (development.conf nebo production.conf jako template)
TEMPLATE="docs/nginx/${MODE}.conf"
SITE_FILE="/etc/nginx/sites-available/shipard.conf"

# V templatu nahradit fastcgi_pass na shipard pool socket
sed "s|fastcgi_pass.*|fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm-shipard.sock;|" "$TEMPLATE" > "$SITE_FILE"

# Aktivovat site
ln -sf "$SITE_FILE" /etc/nginx/sites-enabled/shipard.conf

nginx -t && systemctl reload nginx

# 7. Závěr
echo ""
echo "==> Installation complete."
echo ""
echo "Next steps:"
echo "  1. Initialize server config:"
echo "       sudo shpd-server server-init --mode=$MODE --user=$SHIPARD_USER"
echo "  2. Verify setup:"
echo "       shpd-server doctor"
```

### 8. nginx template úprava

V `docs/nginx/development.conf` a `production.conf` změnit
`fastcgi_pass` na placeholder, který `install-packages.sh` nahradí.
Nebo nechat reálný socket path a počítat s tím, že template má
default — sed v installu ho prostě přepíše.

### 9. `src/Command/Server/HelpCommand.php` rozšíření

Do tabulky příkazů přidat:

```
  <info>doctor</info>          Diagnose server configuration and permissions
  <info>fix-permissions</info> Fix ownership and modes (requires sudo)
```

Do Options:

```
  doctor                                  No options
  fix-permissions [--dry-run] [--force]   Fix /opt/shipard and /etc/shipard
```

### 10. Přepis `DEVELOPERS.md`

Setup sekce (1-4) přepsat. Nový text:

```markdown
## 1. Prerekvizity

Ubuntu LTS 22.04 nebo 24.04. Root přístup (přes sudo) pro one-time
instalaci.

## 2. Instalace

\`\`\`bash
git clone https://github.com/shipard/shpd /home/sebik/sw/shpd
cd /home/sebik/sw/shpd
sudo bash scripts/install-packages.sh --mode=development
\`\`\`

Skript zařídí:
- Instalaci PHP 8.5, MariaDB, nginx, composer
- Vytvoření adresářů `/opt/shipard/` a `/etc/shipard/`
  vlastněných vaším uživatelem
- Konfiguraci samostatného PHP-FPM poolu `shipard` běžícího pod
  vaším uživatelem
- Aktivaci nginx site

## 3. Inicializace server configu

\`\`\`bash
sudo shpd-server server-init --mode=development
\`\`\`

Vytvoří `/etc/shipard/server.json` s admin DB credentials. Soubor
je vlastněný `root:<váš-user>` s módem 0640 — vy ho čtete, root edituje.

## 4. Ověření

\`\`\`bash
shpd-server doctor
\`\`\`

Vypíše report. Pokud něco nesouhlasí, použijte:

\`\`\`bash
sudo shpd-server fix-permissions --dry-run   # preview
sudo shpd-server fix-permissions             # apply
\`\`\`

## 5. Po `git pull`

(beze změny — odkaz na dev-update.sh)

## 6. CLI utility

(beze změny — odkaz na docs/cli.md)
```

A přidat **migration guide** úplně dole, jako přílohu:

```markdown
## Migrace ze starého setupu (před touto změnou)

Pokud máš ručně nastavený starý layout (`sebik:www-data` group hack):

\`\`\`bash
# 1. Re-run install-packages s explicit mode
sudo bash scripts/install-packages.sh --mode=development

# 2. Fix ownership na všech existujících souborech
sudo shpd-server fix-permissions

# 3. Ověř
shpd-server doctor
\`\`\`
```

### 11. Update `docs/cli.md`

Sekce `shpd-server` rozšířit o `doctor` a `fix-permissions`. Pattern:

```markdown
### doctor

Read-only diagnostika oprávnění, konfigurace a DB konektivity. Neměni
nic, vrací exit code 0 (vše OK) nebo 1 (issues found).

\`\`\`bash
shpd-server doctor
\`\`\`

### fix-permissions

Sjednotí ownership a modes pod kontrakt z `PermissionSpec`. Vyžaduje
sudo (volá `chown`). Idempotentní.

\`\`\`bash
sudo shpd-server fix-permissions --dry-run   # preview
sudo shpd-server fix-permissions             # apply
sudo shpd-server fix-permissions --force     # skip confirmation
\`\`\`
```

Plus rozšířit sekci `server-init` o nové opce `--mode` a `--user`.

V sekci "Konvence" přidat odkaz na permission matrix:
```markdown
- **Permission matrix**: viz [docs/operations/permissions.md](operations/permissions.md)
```

A vytvořit nový `docs/operations/permissions.md` s plnou matricí
(zkopírovanou z tohoto tasku) pro snadné dohledání. Nebo nechat
jen v `docs/cli.md`. Drobnost na uvážení — můj sklon: samostatný
soubor, aby tabulka byla snadno najitelná.

### 12. Testy

#### `tests/Unit/Core/Server/HealthCheckerTest.php`

`setUp()` vytvoří temp dir přes `sys_get_temp_dir() . '/shpd-health-test-' . uniqid()`.

`PermissionSpec` přijímá `dataSourcesDir`, `logDir`, `configDir` v
konstruktoru — testy je injectují na temp paths.

Test cases:

- **`testReturnsNoIssuesWhenAllCorrect`** — vytvoř všechny dirs s
  správným ownership/mode (`chown` jako test user, `chmod`), checkAll() = []
- **`testDetectsMissingMandatoryPath`** — neexistující `/opt/shipard/`
  ekvivalent → issue "does not exist", `fixable: false`
- **`testIgnoresMissingOptionalPath`** — DS bez `secrets/` (optional) → 0 issues
- **`testDetectsWrongMode`** — vytvoř soubor s mode `0644` (expected `0640`)
  → issue "mode 0644, expected 0640", `fixable: true`
- **`testDetectsWrongOwner`** — pokud test běží jako non-root, simulace
  je obtížná; můžeme buď přeskočit s `markTestSkipped`, nebo přes
  `posix_getpwuid()` mockování (komplikované)... Realisticky: test
  obsahuje aspoň jeden scénář, který funguje jako current user, a
  ownership mismatch testujeme jen na `group` (chgrp na primární
  group test usera funguje bez root)
- **`testDiscoversDataSources`** — vytvoř 3 adresáře s config/main.json
  + 1 bez → discoverDataSources() vrátí 3
- **`testSkipsDataSourceWithoutMainJson`** — adresář existuje, ale
  bez config/main.json → ignored

#### `tests/Unit/Command/Server/DoctorCommandTest.php`

Subclassing `TestableDoctorCommand` — přepiše `getConfigDir()` /
`getDataSourcesDir()` / `getLogDir()` na temp paths. Nebo lépe:
inject `PermissionSpec` do konstruktoru a v `TestableDoctorCommand`
ho přepíše.

Test cases:

- **`testReportsSuccessWhenAllOk`** — temp setup vše OK → exit 0,
  output "All checks passed"
- **`testReportsFailureWhenIssuesFound`** — temp setup s chybou →
  exit 1, output obsahuje "Issues found: N"
- **`testReportsFixableHint`** — issue je `fixable: true` → output
  obsahuje "Run: sudo shpd-server fix-permissions"
- **`testReportsMissingConfigFile`** — config dir bez server.json →
  exit 1, output obsahuje "Config file missing"
- **`testReportsModeFromServerJson`** — server.json s `"mode": "production"`
  → output "Mode: production"

#### `tests/Unit/Command/Server/FixPermissionsCommandTest.php`

Stejný pattern. Subclassing s injectable spec.

- **`testRequiresRootWhenNotDryRun`** — pokud test nerunuje jako root
  (`posix_geteuid() !== 0`) → exit 1 s hláškou. Test ověří, že fix bez
  `--dry-run` neprojde, a s `--dry-run` projde.
- **`testDryRunDoesNotChangeAnything`** — vytvoř temp adresář s
  rozbitými právy, spusť s `--dry-run` → output ukazuje plánované
  změny, ale `stat()` po runu vrátí původní hodnoty
- **`testListsPlannedChanges`** — temp setup s 3 issues → output
  obsahuje 3 řádky popisu
- **`testReportsNothingToFix`** — temp setup vše OK → output "Nothing
  to fix"

Pozn.: skutečný apply (`chown`/`chmod`) je obtížné testovat bez root.
Test může ověřit jen flow do `chmod` (na soubor vlastněný test
userem), `chown` testovat v rámci dry-run preview.

## Co netřeba

- Migrace DB schématu (nic se nedotýká struktury DB)
- Změna runtime PHP-FPM userů přes API — to dělá systemd reload
- Multi-user dev setup (shared server s víc vývojáři) — pokud bude
  potřeba, fáze 2 (pravděpodobně přes `shipard` group s víc členy)
- Automatický restart PHP-FPM z `fix-permissions` — kontrakt řeší
  jen files, ne services. Pool config je system-level.
- Detekce `selinux` / `apparmor` — Ubuntu LTS default je bez SELinux,
  apparmor nemělo by zasahovat

## Konvence k dodržení

- PHP `declare(strict_types=1)`, PSR-4
- Žádný `exec`/`shell_exec` pro chown/chmod — používáme PHP nativní
  `chown()`, `chgrp()`, `chmod()`
- `posix_*` funkce pro user/group resolution (přes name ↔ uid/gid)
- Bash `set -euo pipefail` ve všech shell skriptech
- Idempotence: `install-packages.sh` lze pustit opakovaně bez škod
  (existující dirs se přechownují, pool config přepíše, mode v
  server.json zachová pokud už existuje)

## Hotovo když

- `vendor/bin/phpunit` projde, včetně všech nových testů
- `sudo bash scripts/install-packages.sh --mode=development` na
  čistém Ubuntu vytvoří:
  - `/opt/shipard/{data-sources,log}` s ownership `$SUDO_USER:$SUDO_USER`,
    mode `0750`
  - `/etc/shipard/` s ownership `root:$SUDO_USER`, mode `0750`
  - `/etc/php/8.5/fpm/pool.d/shipard.conf` s `user = $SUDO_USER`
  - nginx site `shipard.conf` aktivní
- `sudo shpd-server server-init --mode=development` vytvoří
  `/etc/shipard/server.json` s ownership `root:$SUDO_USER`, mode `0640`
- `shpd-server doctor` (bez sudo) vypíše report, exit 0
- `shpd-server ds-create --name="Test"` (bez sudo) projde a vytvoří DS
- `cd /opt/shipard/data-sources/<id> && shpd-ds ds-upgrade` (bez sudo)
  projde
- Dev dashboard (`/_dev/ds-create/`) vytvoří DS bez permission errors
- Po manuálním rozbití (`sudo chmod 0644 /opt/shipard/data-sources/<id>/secrets/secrets.key`):
  - `shpd-server doctor` reportuje issue
  - `sudo shpd-server fix-permissions --dry-run` ukazuje plánovanou
    opravu, ale neaplikuje
  - `sudo shpd-server fix-permissions` opraví mode zpět na `0600`
  - `shpd-server doctor` znova reportuje OK
- Migrace ze starého layoutu (`sebik:www-data` ownership):
  - `sudo bash scripts/install-packages.sh --mode=development` přepíše
    pool config, restart PHP-FPM
  - `sudo shpd-server fix-permissions` přepíše ownership/modes
  - `shpd-server doctor` reportuje OK
- `DEVELOPERS.md` má přepsanou setup sekci 1-6
- `docs/cli.md` má sekce pro `doctor`, `fix-permissions`, updatovaný
  `server-init`
- `docs/operations/permissions.md` (nebo ekvivalent) obsahuje permission
  matrix
