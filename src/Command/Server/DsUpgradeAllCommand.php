<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DsUpgradeAllCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-upgrade-all')
             ->setDescription('Run ds-upgrade on all data sources')
             ->addOption('ds', null, InputOption::VALUE_REQUIRED, 'Run only on the data source with the given ID')
             ->addOption('stop-on-error', null, InputOption::VALUE_NONE, 'Stop on the first failure (default: continue)')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list which data sources would be upgraded');
    }

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
        $verbosityFlag = match (true) {
            $output->isDebug() => ' -vvv',
            $output->isVeryVerbose() => ' -vv',
            $output->isVerbose() => ' -v',
            default => '',
        };

        $cmd = sprintf(
            'cd %s && %s ds-upgrade%s',
            escapeshellarg($dsDir),
            escapeshellarg($this->getShpdDsPath()),
            $verbosityFlag,
        );

        $exitCode = 0;
        passthru($cmd, $exitCode);

        return ['success' => $exitCode === 0, 'exitCode' => $exitCode];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourcesDir();

        if (!is_dir($dsDir)) {
            $output->writeln('<error>Data sources directory not found: ' . $dsDir . '</error>');
            return Command::FAILURE;
        }

        $allDirs = glob($dsDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($allDirs);

        $candidates = array_values(array_filter(
            $allDirs,
            static fn(string $d): bool => is_file($d . '/config/main.json')
        ));

        $only = $input->getOption('ds');
        if ($only !== null && $only !== '') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn(string $d): bool => basename($d) === $only
            ));

            if (empty($candidates)) {
                $output->writeln('<comment>No data source found with ID: ' . $only . '</comment>');
                return Command::SUCCESS;
            }
        }

        if (empty($candidates)) {
            $output->writeln('<comment>No data sources found.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>Data sources to upgrade:</info>');
        foreach ($candidates as $d) {
            $output->writeln('  - ' . basename($d));
        }
        $output->writeln('');

        if ($input->getOption('dry-run')) {
            $output->writeln('<comment>--dry-run: skipping actual upgrade</comment>');
            return Command::SUCCESS;
        }

        $stopOnError = (bool) $input->getOption('stop-on-error');
        $upgraded = 0;
        $failed = [];

        foreach ($candidates as $d) {
            $id = basename($d);

            $output->writeln('==========================================');
            $output->writeln('===== Upgrading ' . $id . ' =====');
            $output->writeln('==========================================');

            $result = $this->runDsUpgrade($d, $output);

            if ($result['success']) {
                $upgraded++;
            } else {
                $failed[] = $id;
                if ($stopOnError) {
                    $output->writeln('<error>Stopping on first error (--stop-on-error)</error>');
                    break;
                }
            }
        }

        $output->writeln('');
        $output->writeln('==========================================');
        $output->writeln(sprintf('Summary: %d upgraded, %d failed', $upgraded, count($failed)));
        if (!empty($failed)) {
            $output->writeln('Failed: ' . implode(', ', $failed));
        }
        $output->writeln('==========================================');

        return empty($failed) ? Command::SUCCESS : Command::FAILURE;
    }
}
