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
            $output->writeln("<error>Config file missing: {$configFile}</error>");
            $output->writeln('<comment>→ Run: sudo bash scripts/install-packages.sh --mode=development</comment>');
            return Command::FAILURE;
        }

        $configContent = @file_get_contents($configFile);
        if ($configContent === false) {
            $output->writeln("<error>Config file not readable: {$configFile}</error>");
            $output->writeln('<comment>→ Check group membership or run as root</comment>');
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
        $output->writeln('<info>Data source DB connections</info>');
        $dsErrors = $this->checkDataSourceConnections($spec, $output);

        $output->writeln('');
        $output->writeln(str_repeat('─', 55));

        $totalIssues = count($issues) + $dsErrors + ($poolUser !== $shipardUser ? 1 : 0);
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
    protected function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output): int
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
}
