<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\HostingConfig;
use Shipard\Core\Server\HostingSyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pull agent hostingu (D3) — rekonciliace + provisioning fronta + confirm.
 * Periodicky ho spouští `shpd-server cron --slot=two-minutes`; bez sekce
 * `hosting` v server.json informativně skončí s exit 0 (hosting je plně
 * opt-in, na nespravovaném serveru se nic nemění).
 *
 * Vlastní logika žije v Shipard\Core\Server\HostingSyncRunner.
 */
class HostingSyncCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?HostingSyncRunner $runner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-sync')
             ->setDescription('Sync with the hosting: reconcile local data sources and provision queued requests')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the provisioning queue without any action');
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function getShpdServerPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/shpd-server';
    }

    protected function getShpdDsPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/shpd-ds';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->serverConfig ?? new ServerConfig();
        try {
            $config->load();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed to load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $hosting = $config->getHosting();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Invalid hosting config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($hosting === null && $this->runner === null) {
            $output->writeln('No hosting section in server.json — nothing to sync.');
            return Command::SUCCESS;
        }

        $runner = $this->runner ?? new HostingSyncRunner(
            $hosting,
            $this->getDataSourcesDir(),
            $this->getShpdServerPath(),
            $this->getShpdDsPath(),
            static function (string $message) use ($output): void {
                $output->writeln($message);
            },
        );

        return $runner->run((bool) $input->getOption('dry-run')) ? Command::SUCCESS : Command::FAILURE;
    }
}
