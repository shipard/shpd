<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\DataSourceStateScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * shpd-server ds-state-check — D8 z #56: DS v maintenance déle než
 * DataSourceStateScanner::MAINTENANCE_WARN_DAYS → warning do logu.
 *
 * Server-level job v `daily` slotu (CronCommand::SERVER_SLOT_JOBS) — alert
 * checky běží uvnitř DS a v maintenance jsou vypnuté, „zapomenutou"
 * maintenance tedy musí hlásit server. Jednou denně = žádný spam.
 * Fail-closed (poškozený) state.json se hlásí taky — jinak by DS zavřený
 * omylem nikdo neviděl. Exit SUCCESS i s nálezy: nález není chyba jobu,
 * čte se z logu a z `doctor`.
 */
class DsStateCheckCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ds-state-check')
             ->setDescription('Warn about data sources stuck in maintenance or with an unusable state.json (daily cron job)');
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    /** Log path ze server.json; null (= default ErrorLoggeru) když config chybí. */
    protected function getLogPath(): ?string
    {
        try {
            $cfg = new ServerConfig();
            $cfg->load();
            ErrorLogger::setLogLevel($cfg->getLogLevel());
            return $cfg->getLogFile();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ErrorLogger::setLogPath($this->getLogPath());
        ErrorLogger::setRequestContext('cli: ds-state-check');

        $threshold = DataSourceStateScanner::MAINTENANCE_WARN_DAYS;
        $entries = (new DataSourceStateScanner($this->getDataSourcesDir()))->scan($this->now());

        $findings = 0;
        foreach (DataSourceStateScanner::overdueMaintenance($entries, $threshold) as $e) {
            $findings++;
            $ctx = [
                'ds' => $e->dsId,
                'reason' => $e->state->getMaintenanceReason(),
                'since' => $e->state->getMaintenanceSince(),
                'days' => $e->maintenanceDays,
                'thresholdDays' => $threshold,
            ];
            ErrorLogger::warn('data source in maintenance longer than threshold — forgotten?', $ctx);
            $output->writeln(sprintf(
                '⚠ %s: maintenance (%s) for %d days — shpd-ds ds-state maintenance --off?',
                $e->dsId, $e->state->getMaintenanceReason() ?? '?', $e->maintenanceDays,
            ));
        }
        foreach ($entries as $e) {
            if (!$e->isCorrupted()) {
                continue;
            }
            $findings++;
            // DataSourceState::load už zalogoval error s cestou a důvodem;
            // tady jen shrnutí pro výstup jobu.
            $output->writeln("✗ {$e->dsId}: state.json unusable — data source is fail-closed (suspended)");
        }

        $output->writeln(sprintf(
            '%d data source(s) scanned, %d finding(s).',
            count($entries), $findings,
        ));
        return Command::SUCCESS;
    }
}
