# Doctor — nginx/FPM kontroly + fix install skriptu

## Status / Cíl

Reakce na reálnou situaci po nasazení [`server-setup-permissions.md`](server-setup-permissions.md):
install skript "deaktivoval" starý nginx site config přejmenováním na
`*.disabled-<timestamp>`, jenže nginx inkluduje `sites-enabled/*` bez
ohledu na příponu, takže starý config zůstal aktivní a routoval
requesty do defaultního `www` poolu místo do shipard poolu. **Doctor
to nezachytil** — neměl kontroly nginx routingu ani FPM socketů.

Tento task fixuje obojí:

1. `install-packages.sh` opravdu přesouvá staré site configy mimo
   `sites-enabled/`, ne jen přejmenovává
2. `doctor` přidává dvě nové kontroly (FPM socket, nginx fastcgi_pass)
3. `install-packages.sh` na konci automaticky volá `doctor`,
   takže problémy odhalí už při instalaci

## Návaznost

- Vychází z [`server-setup-permissions.md`](server-setup-permissions.md)
  — hotová, ale install skript a doctor mají popsanou díru.
- `DoctorCommand` rozšíříme — nové kontroly jsou system-level (nginx +
  FPM), nepatří do `PermissionSpec` / `HealthChecker`. Implementujeme
  je inline v `DoctorCommand`.

## Komponenty

```
scripts/install-packages.sh                         ← oprava + volání doctor
src/Command/Server/DoctorCommand.php                ← dvě nové kontroly
tests/Unit/Command/Server/DoctorCommandTest.php     ← rozšířit
```

## Co je potřeba udělat

### 1. Oprava `scripts/install-packages.sh` — disable přes přesun

V sekci, kde install skript zachází se starými nginx site configy v
`/etc/nginx/sites-enabled/`, nahradit **rename pattern**:

```bash
# ŠPATNĚ (současný stav — nginx soubor pořád inkluduje):
mv "$old_file" "${old_file}.disabled-$(date +%Y%m%d-%H%M%S)"
```

za **move pattern** — přesun do `sites-available/`, odkud nginx
nečte:

```bash
# SPRÁVNĚ:
timestamp=$(date +%Y%m%d-%H%M%S)
basename=$(basename "$old_file")
mv "$old_file" "/etc/nginx/sites-available/${basename}.disabled-${timestamp}"
```

Pokud původní soubor v `sites-enabled/` je symlink (běžné, např.
ubuntu-style site management), tak místo `mv` udělat `rm`
symlinku — originál v `sites-available/` necháváme nedotčený a jen
vytvoříme `.disabled-<timestamp>` marker:

```bash
if [ -L "$old_file" ]; then
    # Symlink — jen ho odstranit, originál zůstane v sites-available
    rm "$old_file"
    echo "    Removed symlink: $old_file (original preserved)"
elif [ -f "$old_file" ]; then
    # Regular file — přesunout
    timestamp=$(date +%Y%m%d-%H%M%S)
    basename=$(basename "$old_file")
    target="/etc/nginx/sites-available/${basename}.disabled-${timestamp}"
    mv "$old_file" "$target"
    echo "    Moved: $old_file → $target"
fi
```

### 2. Dvě nové kontroly v `DoctorCommand`

Po existující sekci "Filesystem checks" a před "Data source DB connections"
přidat novou sekci `<info>Nginx + PHP-FPM routing</info>` se dvěma
kontrolami.

#### Kontrola A — Shipard FPM socket existuje

```php
private function checkFpmSocket(OutputInterface $output, string $shipardUser): int
{
    $errors = 0;

    // Najdi socket podle pool configu (může být /run/php/php8.5-fpm-shipard.sock
    // nebo jiná cesta podle install skriptu)
    $expectedSocket = $this->detectShipardSocket();

    if ($expectedSocket === null) {
        $output->writeln('  ✗ Shipard PHP-FPM pool config not found in /etc/php/*/fpm/pool.d/');
        $output->writeln('    <comment>→ Re-run: sudo bash scripts/install-packages.sh --mode=...</comment>');
        return 1;
    }

    if (!file_exists($expectedSocket)) {
        $output->writeln("  ✗ FPM socket missing: {$expectedSocket}");
        $output->writeln('    <comment>→ Pool config exists but daemon did not create the socket.</comment>');
        $output->writeln('    <comment>→ Restart: sudo systemctl restart php8.5-fpm</comment>');
        return 1;
    }

    // Ownership socketu — install script ho nastavuje na www-data:www-data
    // s mode 0660 (listen.owner/group/mode). Nginx (www-data) musí mít přístup.
    $stat = stat($expectedSocket);
    $ownerName = posix_getpwuid($stat['uid'])['name'] ?? (string) $stat['uid'];
    $output->writeln("  ✓ FPM socket: {$expectedSocket} (owner: {$ownerName})");

    return $errors;
}

private function detectShipardSocket(): ?string
{
    foreach (glob('/etc/php/*/fpm/pool.d/shipard.conf') ?: [] as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;
        if (preg_match('/^\s*listen\s*=\s*(\S+)/m', $content, $m)) {
            return $m[1];
        }
    }
    return null;
}
```

#### Kontrola B — nginx fastcgi_pass v sites-enabled směřuje na shipard

```php
private function checkNginxRouting(OutputInterface $output, ?string $expectedSocket): int
{
    $sitesEnabled = '/etc/nginx/sites-enabled';
    if (!is_dir($sitesEnabled)) {
        $output->writeln('  ⚠ nginx sites-enabled directory not found');
        return 0;
    }

    if ($expectedSocket === null) {
        $output->writeln('  ⚠ Cannot verify routing — shipard pool config not found');
        return 0;
    }

    // Iteruj VŠECHNY soubory v sites-enabled, ne jen *.conf — nginx
    // inkluduje `sites-enabled/*` bez ohledu na příponu
    $files = glob($sitesEnabled . '/*') ?: [];
    if (count($files) === 0) {
        $output->writeln('  ⚠ No site configs in sites-enabled');
        return 0;
    }

    $errors = 0;
    $shipardSites = 0;
    $foreignSites = [];

    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;

        if (preg_match_all('/^\s*fastcgi_pass\s+(\S+);/m', $content, $matches)) {
            foreach ($matches[1] as $target) {
                // Normalizace: "unix:/run/php/..." → "/run/php/..."
                $socketPath = str_starts_with($target, 'unix:')
                    ? substr($target, 5)
                    : $target;

                if ($socketPath === $expectedSocket) {
                    $shipardSites++;
                } else {
                    $foreignSites[] = ['file' => $file, 'target' => $target];
                    $errors++;
                }
            }
        }
    }

    if ($shipardSites > 0 && count($foreignSites) === 0) {
        $output->writeln("  ✓ nginx routes to shipard socket ({$shipardSites} active site(s))");
        return 0;
    }

    if ($shipardSites === 0) {
        $output->writeln('  ✗ No nginx site routes to shipard FPM socket');
        $output->writeln("    <comment>→ Expected fastcgi_pass: unix:{$expectedSocket}</comment>");
    }

    foreach ($foreignSites as $site) {
        $output->writeln("  ✗ {$site['file']}");
        $output->writeln("    fastcgi_pass {$site['target']} (not shipard socket)");
        $output->writeln('    <comment>→ Edit this file to use shipard socket, or remove if obsolete</comment>');
        $output->writeln('    <comment>  (Note: nginx loads `sites-enabled/*` regardless of file extension)</comment>');
    }

    return $errors;
}
```

**Integrace v `execute()`** — vložit po sekci "Filesystem checks":

```php
$output->writeln("");
$output->writeln('<info>Nginx + PHP-FPM routing</info>');
$expectedSocket = $this->detectShipardSocket();
$fpmErrors = $this->checkFpmSocket($output, $shipardUser);
$nginxErrors = $this->checkNginxRouting($output, $expectedSocket);

// V souhrnu na konci započítat do totalIssues:
$totalIssues = count($issues) + $dsErrors + $fpmErrors + $nginxErrors;
```

### 3. Auto-volání doctor na konci install-packages.sh

Úplně na konec skriptu (před závěrečné `echo "==> Installation complete."`):

```bash
echo ""
echo "==> Verifying installation with shpd-server doctor..."
echo ""

if shpd-server doctor; then
    echo ""
    echo "==> Installation complete and verified."
else
    echo ""
    echo "==> Installation finished, but doctor found issues."
    echo "    Review the report above and fix before proceeding."
    exit 1
fi
```

**Pozn:** `shpd-server doctor` je read-only, takže nevadí, že běží
jako root (ze sudo context install skriptu). Doctor vypíše report
stejně, jako kdyby ho pustil dev user.

### 4. Update testů `DoctorCommandTest`

Subclassing `TestableDoctorCommand` z původního tasku se rozšíří o
override metod, které pracují s nginx/FPM konfigurací:

```php
class TestableDoctorCommand extends DoctorCommand
{
    public ?string $fakePoolConfigDir = null;
    public ?string $fakeNginxDir = null;
    public ?string $fakeSocketPath = null;

    protected function getPoolConfigGlob(): string
    {
        return $this->fakePoolConfigDir
            ? $this->fakePoolConfigDir . '/shipard.conf'
            : parent::getPoolConfigGlob();
    }

    protected function getNginxSitesEnabledDir(): string
    {
        return $this->fakeNginxDir ?? '/etc/nginx/sites-enabled';
    }
}
```

To znamená v `DoctorCommand` udělat tyhle dvě cesty jako protected
metody (`getPoolConfigGlob()`, `getNginxSitesEnabledDir()`), aby se
daly v testu přepsat.

Test cases (nové):

- **`testFpmSocketMissingIsReported`** — fake pool config existuje s
  `listen = /tmp/nonexistent.sock`, ale socket file neexistuje →
  output obsahuje "FPM socket missing", exit 1
- **`testFpmPoolConfigMissingIsReported`** — žádný pool config v fake
  dir → output "pool config not found", exit 1
- **`testNginxRoutingAllShipardIsOk`** — fake sites-enabled obsahuje
  1 soubor s `fastcgi_pass unix:/tmp/test.sock;`, fake pool config má
  stejný socket → output "routes to shipard socket (1 active site)",
  exit 0
- **`testNginxRoutingForeignFastcgiPassIsReported`** — fake sites-enabled
  obsahuje 2 soubory: jeden ukazuje na shipard, druhý na
  `unix:/run/php/php-fpm.sock` → exit 1, output zmiňuje druhý soubor
  + nápovědu o file extension
- **`testNginxRoutingScansFilesRegardlessOfExtension`** — fake
  sites-enabled obsahuje soubor `development.conf.disabled-20260511`
  (přesně bug z reálu) s cizím `fastcgi_pass` → reportováno jako issue
- **`testNginxRoutingEmptyDirIsWarning`** — prázdný sites-enabled dir →
  warning, ale ne error (exit 0 ze samotné kontroly)
- **`testNginxRoutingHandlesMultipleFastcgiPassPerFile`** — site config
  s víc location bloky a víc `fastcgi_pass` direktivami → každý
  vyhodnocen separátně
- **`testSocketPathNormalizationStripsUnixPrefix`** — `fastcgi_pass unix:/tmp/x.sock`
  matchuje expected `/tmp/x.sock` (bez `unix:` prefixu)

## Co netřeba

- `nginx -T` validation pro detekci duplicitních server_name —
  kontrola B pokrývá reálný bug case (cizí fastcgi_pass v sites-enabled).
  Plný server_name parsing je over-engineering.
- Walk přes `nginx/conf.d/` — site configy v dev/prod setupu jsou
  jen v `sites-enabled/`. Pokud někdo přidá vlastní v `conf.d/`,
  je to advanced setup mimo náš scope.
- Detekce uživatele FPM master procesu (typicky root) — irrelevant,
  pool worker procesy běží pod `user =` z pool configu

## Konvence k dodržení

- PHP `declare(strict_types=1)`
- Read-only doctor — žádné modifikace souborů, žádné restarty služeb
- Bash `set -euo pipefail`
- Protected getter metody pro fake paths v testech (subclassing
  pattern, ne reflexe)

## Hotovo když

- `vendor/bin/phpunit` projde, včetně 8 nových `DoctorCommandTest` cases
- Reprodukce původního bugu: před opravou install skriptu, simulovat
  starý nginx site config v sites-enabled (např.
  `touch /etc/nginx/sites-enabled/old.disabled-20260101`
  s `fastcgi_pass unix:/run/php/php-fpm.sock;`) → `shpd-server doctor`
  reportuje issue se správnou cestou k souboru
- `sudo bash scripts/install-packages.sh --mode=development` na
  systému s existujícím `default` (Ubuntu default nginx site):
  - `default` symlink se odstraní z `sites-enabled/`
  - `default` originál v `sites-available/` zůstane
  - Jediný aktivní site je `shipard.conf` → shipard socket
- Install skript na konci automaticky pustí `shpd-server doctor`:
  - Pokud OK, vypíše "Installation complete and verified."
  - Pokud doctor failne, vypíše "Installation finished, but doctor
    found issues" a exit 1
- Na rozbitém setupu (přejmenovaný `development.conf.disabled-X` v
  sites-enabled), který právě řešíme — doctor jasně reportuje:
  - "nginx routes to shipard socket (1 active site)" pro `shipard.conf`
  - "✗ /etc/nginx/sites-enabled/development.conf.disabled-X — fastcgi_pass
    unix:/run/php/php-fpm.sock (not shipard socket)"
