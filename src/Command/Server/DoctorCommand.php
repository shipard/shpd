<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Command
{
    protected string $serverConfigPath = '/etc/shipard/server.json';

    public function __construct(
        private readonly ?PermissionSpec $injectedSpec = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('doctor')
             ->setDescription('Diagnose shpd server configuration and permissions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Shipard server health check</info>');
        $output->writeln(str_repeat('═', 55));

        $configFile = $this->serverConfigPath;
        if (!is_file($configFile)) {
            $output->writeln("<error>Config file not found or not accessible: {$configFile}</error>");
            $output->writeln('<comment>→ If it exists, you likely lack permission — run as the shipard user (or root):</comment>');
            $output->writeln('<comment>    sudo -u shipard shpd-server doctor</comment>');
            $output->writeln('<comment>→ If it is genuinely missing, initialize the server:</comment>');
            $output->writeln('<comment>    sudo shpd-server server-init --mode=...</comment>');
            return Command::FAILURE;
        }

        $configContent = @file_get_contents($configFile);
        if ($configContent === false) {
            $output->writeln("<error>Config file not readable: {$configFile}</error>");
            $output->writeln('<comment>→ Run as the shipard user (or root): sudo -u shipard shpd-server doctor</comment>');
            return Command::FAILURE;
        }

        $config = json_decode($configContent, true);
        if (!is_array($config)) {
            $output->writeln("<error>Config file is not valid JSON: {$configFile}</error>");
            return Command::FAILURE;
        }

        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'unknown';
        $spec = $this->injectedSpec ?? $this->buildSpec($mode);
        $shipardUser = $spec->getShipardUser();

        $output->writeln('');
        $output->writeln("  Mode:                {$mode}");
        $output->writeln("  Shipard user:        {$shipardUser}");

        $checker = new HealthChecker($spec);
        $poolUser = $this->detectPoolUser($checker);
        $poolDisplay = $poolUser ?? 'not configured';
        $poolStatus = ($poolUser === $shipardUser) ? '✓' : '✗ (mismatch)';
        $output->writeln("  PHP-FPM pool user:   {$poolDisplay}   {$poolStatus}");

        $output->writeln('');
        $output->writeln('<info>Filesystem checks</info>');

        $issues = $checker->checkAll();
        if (count($issues) === 0) {
            $output->writeln('  All paths OK ✓');
        } else {
            foreach ($issues as $issue) {
                $marker = $issue['severity'] === 'error' ? '✗' : '⚠';
                $output->writeln("  {$marker} {$issue['path']}: {$issue['message']}");
            }
        }

        $output->writeln('');
        $output->writeln('<info>Nginx + PHP-FPM routing</info>');
        $expectedSocket = $this->detectShipardSocket();
        $fpmErrors = $this->checkFpmSocket($output, $expectedSocket);
        $nginxErrors = $this->checkNginxRouting($output, $expectedSocket);

        $output->writeln('');
        $output->writeln('<info>System config includes</info>');
        $this->checkSystemConfigIncludes($output);

        $output->writeln('');
        $output->writeln('<info>Data source DB connections</info>');
        $dsErrors = $this->checkDataSourceConnections($spec, $output, $mode);

        $output->writeln('');
        $output->writeln(str_repeat('─', 55));

        $totalIssues = count($issues) + $dsErrors + $fpmErrors + $nginxErrors
                     + ($poolUser !== $shipardUser ? 1 : 0);
        if ($totalIssues === 0) {
            $output->writeln('<info>✓ All checks passed.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("<error>Issues found: {$totalIssues}</error>");
        $fixableCount = count(array_filter($issues, static fn($i) => $i['fixable']));
        if ($fixableCount > 0) {
            $output->writeln('<comment>→ Run: sudo shpd-server fix-permissions</comment>');
        }
        return Command::FAILURE;
    }

    protected function buildSpec(string $mode): PermissionSpec
    {
        return new PermissionSpec($this->detectShipardUser($mode));
    }

    protected function detectPoolUser(HealthChecker $checker): ?string
    {
        return $checker->detectPoolUser();
    }

    protected function detectShipardUser(string $mode): string
    {
        if ($mode === 'production') {
            return 'shipard';
        }
        if (is_dir('/opt/shipard')) {
            $stat = @stat('/opt/shipard');
            if ($stat !== false) {
                $info = posix_getpwuid($stat['uid']);
                if (is_array($info) && isset($info['name'])) {
                    return $info['name'];
                }
            }
        }
        $login = posix_getlogin();
        return $login !== false && $login !== '' ? $login : 'unknown';
    }

    /**
     * @return int number of DS that failed to connect
     */
    protected function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output, string $mode): int
    {
        $dsList = $spec->discoverDataSources();
        if (count($dsList) === 0) {
            $output->writeln('  (no data sources)');
            return 0;
        }

        $errors = 0;
        foreach ($dsList as $dsDir) {
            $id = basename($dsDir);
            try {
                $cfg = new DataSourceConfig($dsDir);
                if ($mode === 'production' && $cfg->allowsReset()) {
                    $output->writeln("  ⚠ {$id}: enableReset is set — data source is resettable on a production server.");
                }
                $conn = new DataSourceConnection($cfg);
                $conn->fetchRow('SELECT 1');
                $output->writeln("  ✓ {$id}");
            } catch (\Throwable $e) {
                $output->writeln("  ✗ {$id}: " . $e->getMessage());
                $errors++;
            }
        }
        return $errors;
    }

    protected function getPoolConfigGlob(): string
    {
        return '/etc/php/*/fpm/pool.d/shipard.conf';
    }

    protected function getNginxSitesEnabledDir(): string
    {
        return '/etc/nginx/sites-enabled';
    }

    /**
     * Parses the shipard PHP-FPM pool config and returns the `listen` socket path.
     * Returns null when no shipard pool config is present.
     */
    protected function detectShipardSocket(): ?string
    {
        foreach (glob($this->getPoolConfigGlob()) ?: [] as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/^\s*listen\s*=\s*(\S+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Warn-only: verifies that the live nginx site and FPM pool configs include
     * the versioned system parameter files, and that those files exist in the
     * repo. Never affects the exit code — without the includes the app still
     * works, only with default (low) upload limits.
     */
    protected function checkSystemConfigIncludes(OutputInterface $output): void
    {
        $repoRoot = $this->getRepoRoot();
        $repoFiles = [
            'docs/nginx/shipard-common.conf',
            'docs/nginx/shipard-tls.conf',
            'docs/php/shipard-fpm-common.conf',
        ];
        foreach ($repoFiles as $rel) {
            if (!is_file($repoRoot . '/' . $rel)) {
                $output->writeln("  ⚠ Include file missing in repo: {$rel}");
            }
        }

        $checked = 0;

        $siteFile = $this->getNginxSitesEnabledDir() . '/shipard.conf';
        if (is_file($siteFile)) {
            $checked++;
            $content = (string) @file_get_contents($siteFile);
            // Strip `# ...` comments so a commented-out include does not count.
            $stripped = preg_replace('/#[^\n]*/', '', $content) ?? $content;
            if (str_contains($stripped, 'shipard-common.conf')) {
                $output->writeln('  ✓ nginx site includes shipard-common.conf');
            } else {
                $output->writeln("  ⚠ {$siteFile}: missing include of shipard-common.conf");
                $output->writeln('    <comment>→ Add to each server block: include /opt/shipard/shpd/docs/nginx/shipard-common.conf;</comment>');
                $output->writeln('    <comment>  HTTPS (443 ssl) blocks also: include /opt/shipard/shpd/docs/nginx/shipard-tls.conf;</comment>');
            }
        }

        foreach (glob($this->getPoolConfigGlob()) ?: [] as $poolFile) {
            $checked++;
            $content = (string) @file_get_contents($poolFile);
            // Strip `; ...` comments so a commented-out include does not count.
            $stripped = preg_replace('/^\s*;[^\n]*/m', '', $content) ?? $content;
            if (str_contains($stripped, 'shipard-fpm-common.conf')) {
                $output->writeln('  ✓ FPM pool includes shipard-fpm-common.conf');
            } else {
                $output->writeln("  ⚠ {$poolFile}: missing include of shipard-fpm-common.conf");
                $output->writeln('    <comment>→ Add to the pool: include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf</comment>');
            }
        }

        if ($checked === 0) {
            $output->writeln('  (no live configs found — skipped)');
        }
    }

    /**
     * @return int number of FPM-related issues
     */
    protected function checkFpmSocket(OutputInterface $output, ?string $expectedSocket): int
    {
        if ($expectedSocket === null) {
            $globDir = dirname($this->getPoolConfigGlob());
            $output->writeln("  ✗ Shipard PHP-FPM pool config not found in {$globDir}");
            $output->writeln('    <comment>→ Re-run: sudo bash scripts/install-packages.sh --mode=...</comment>');
            return 1;
        }
        if (!file_exists($expectedSocket)) {
            $output->writeln("  ✗ FPM socket missing: {$expectedSocket}");
            $output->writeln('    <comment>→ Pool config exists but daemon did not create the socket.</comment>');
            $output->writeln('    <comment>→ Restart: sudo systemctl restart php8.5-fpm</comment>');
            return 1;
        }
        $stat = @stat($expectedSocket);
        if ($stat === false) {
            $output->writeln("  ⚠ Cannot stat FPM socket: {$expectedSocket}");
            return 0;
        }
        $ownerInfo = posix_getpwuid($stat['uid']);
        $ownerName = $ownerInfo['name'] ?? (string) $stat['uid'];
        $output->writeln("  ✓ FPM socket: {$expectedSocket} (owner: {$ownerName})");
        return 0;
    }

    /**
     * Extracts every fastcgi_pass target from an nginx config snippet, ignoring
     * directives inside `#` comments. Works for multi-line as well as inline
     * blocks like `{ fastcgi_pass unix:/x.sock; }`.
     *
     * @return list<string>
     */
    protected function extractFastcgiPassTargets(string $content): array
    {
        // Strip `# ... end-of-line` comments so commented-out directives don't match.
        $stripped = preg_replace('/#[^\n]*/', '', $content);
        if ($stripped === null) {
            $stripped = $content;
        }
        if (!preg_match_all('/\bfastcgi_pass\s+(\S+?);/', $stripped, $matches)) {
            return [];
        }
        return $matches[1];
    }

    /**
     * Iterates ALL files in sites-enabled (regardless of extension — nginx
     * loads `sites-enabled/*` literally) and verifies that every fastcgi_pass
     * directive routes to the shipard FPM socket.
     *
     * @return int number of nginx routing issues
     */
    protected function checkNginxRouting(OutputInterface $output, ?string $expectedSocket): int
    {
        $sitesEnabled = $this->getNginxSitesEnabledDir();
        if (!is_dir($sitesEnabled)) {
            $output->writeln('  ⚠ nginx sites-enabled directory not found');
            return 0;
        }
        if ($expectedSocket === null) {
            $output->writeln('  ⚠ Cannot verify routing — shipard pool config not found');
            return 0;
        }

        $files = glob($sitesEnabled . '/*') ?: [];
        if (count($files) === 0) {
            $output->writeln('  ⚠ No site configs in sites-enabled');
            return 0;
        }

        $shipardSites = 0;
        $foreignSites = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            foreach ($this->extractFastcgiPassTargets($content) as $target) {
                $socketPath = str_starts_with($target, 'unix:')
                    ? substr($target, 5)
                    : $target;
                if ($socketPath === $expectedSocket) {
                    $shipardSites++;
                } else {
                    $foreignSites[] = ['file' => $file, 'target' => $target];
                }
            }
        }

        if ($shipardSites > 0 && count($foreignSites) === 0) {
            $output->writeln("  ✓ nginx routes to shipard socket ({$shipardSites} active site(s))");
            return 0;
        }

        $errors = 0;
        if ($shipardSites === 0) {
            $output->writeln('  ✗ No nginx site routes to shipard FPM socket');
            $output->writeln("    <comment>→ Expected fastcgi_pass: unix:{$expectedSocket}</comment>");
            $errors++;
        }
        foreach ($foreignSites as $site) {
            $output->writeln("  ✗ {$site['file']}");
            $output->writeln("    fastcgi_pass {$site['target']} (not shipard socket)");
            $output->writeln('    <comment>→ Edit this file to use shipard socket, or remove if obsolete</comment>');
            $output->writeln('    <comment>  (Note: nginx loads `sites-enabled/*` regardless of file extension)</comment>');
            $errors++;
        }
        return $errors;
    }
}
