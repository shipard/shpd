<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\BalanceMatcher;
use Shipard\Module\Economy\Accbal\MatchResult;
use Shipard\Module\Economy\Accbal\MatchSummary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dávkové párování úhrad saldokonta (matcher, Fáze 3). Tenká vrstva nad
 * {@see BalanceMatcher}; runtime se nadrátuje stejně jako BankImportStatementCommand
 * (config + DB + JournalEventHandlerLoader — bez něj se po reaccountu nespustí
 * re-derivace ledgeru).
 *
 * Bez `--all`/filtru příkaz nic neudělá (vyžádá si rozsah).
 */
class AccbalMatchCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('accbal-match')
            ->setDescription('Spáruje nespárované bankovní úhrady proti otevřeným předpisům (clearing → 311/321)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Zpracovat všechny clearingové kandidáty')
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'Jen platby tohoto partnera (id)')
            ->addOption('fiscal-year', null, InputOption::VALUE_REQUIRED, 'Jen platby tohoto fiskálního roku (id)')
            ->addOption('rematch-partner', null, InputOption::VALUE_REQUIRED, 'Přegeneruj auto allocations bucketu (formát: partnerId:balanceId:currency)')
            ->addOption('unmatch', null, InputOption::VALUE_REQUIRED, 'Úplné rozpárování platby (id transakce) — destruktivní (smaže i ruční allocations)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš plán, nic neměň');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir        = $this->getDataSourceDir();
        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $lang         = $dsConfig->getDefaultLanguage();
        $resolver     = $this->buildResolver();

        $config = ConfigRuntime::load($dsDir, $lang);
        $dibi   = $dsConnection->getDibiConnection();
        // Bez handler loaderu by se po reaccountu nespustila re-derivace ledgeru.
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);

        $matcher = new BalanceMatcher($dibi, $config, $journalEvents, $dsConfig);
        $dryRun  = (bool) $input->getOption('dry-run');

        // Úplné rozpárování — samostatná destruktivní cesta.
        if (($unmatch = $input->getOption('unmatch')) !== null) {
            $txId = (int) $unmatch;
            if ($dryRun) {
                $output->writeln("<comment>--dry-run: rozpárování transakce #{$txId} by vrátilo platbu na clearing a smazalo její allocations.</comment>");
                return Command::SUCCESS;
            }
            $matcher->unmatch($txId);
            $output->writeln("Transakce #{$txId} rozpárována (platba zpět na clearingu, allocations smazány).");
            return Command::SUCCESS;
        }

        // Přegenerace bucketu.
        if (($rematch = $input->getOption('rematch-partner')) !== null) {
            $parts = explode(':', (string) $rematch);
            if (count($parts) !== 3) {
                $output->writeln('<error>--rematch-partner očekává formát partnerId:balanceId:currency</error>');
                return Command::FAILURE;
            }
            if ($dryRun) {
                $output->writeln('<comment>--dry-run: přegenerace bucketu nepodporuje náhled (mění allocations in-place). Spusť bez --dry-run.</comment>');
                return Command::SUCCESS;
            }
            $summary = $matcher->rematchBucket((int) $parts[0], (int) $parts[1], strtolower(trim($parts[2])));
            $this->printSummary($output, $summary, true);
            return Command::SUCCESS;
        }

        // Dávka.
        $filters = [];
        if (($p = $input->getOption('partner')) !== null) {
            $filters['partner'] = (int) $p;
        }
        if (($fy = $input->getOption('fiscal-year')) !== null) {
            $filters['fiscalYear'] = (int) $fy;
        }

        if (!$input->getOption('all') && $filters === []) {
            $output->writeln('<error>Vyžaduje --all nebo filtr (--partner / --fiscal-year). Pro náhled přidej --dry-run.</error>');
            return Command::FAILURE;
        }

        $summary = $matcher->matchAll($filters, $dryRun);
        $this->printSummary($output, $summary, $dryRun);
        return Command::SUCCESS;
    }

    private function printSummary(OutputInterface $output, MatchSummary $summary, bool $dryRun): void
    {
        foreach ($summary->results as $r) {
            $this->printResult($output, $r);
        }

        $output->writeln('');
        $output->writeln(sprintf('Kandidátů: %d', $summary->candidates()));
        if ($dryRun) {
            $output->writeln(sprintf('  k spárování (plán): %d, Σ %.2f', $summary->planned, $summary->matchedAmount));
        } else {
            $output->writeln(sprintf('  spárováno: %d, Σ %.2f', $summary->allocated, $summary->matchedAmount));
            if ($summary->routedUnallocated > 0) {
                $output->writeln(sprintf('  <comment>přesměrováno bez alokace: %d</comment>', $summary->routedUnallocated));
            }
        }
        if ($summary->skipped !== []) {
            $parts = [];
            foreach ($summary->skipped as $reason => $count) {
                $parts[] = "{$reason}: {$count}";
            }
            $output->writeln('  přeskočeno — ' . implode(', ', $parts));
        }
    }

    private function printResult(OutputInterface $output, MatchResult $r): void
    {
        switch ($r->status) {
            case MatchResult::STATUS_ALLOCATED:
            case MatchResult::STATUS_PLANNED:
                $verb = $r->status === MatchResult::STATUS_PLANNED ? 'plán' : 'spárováno';
                $output->writeln(sprintf(
                    'tx #%d → %s (partner %s, %.2f %s) [%s]',
                    $r->txId,
                    $r->targetCode !== '' ? $r->targetCode : 'bucket',
                    $r->partner ?? '?',
                    $r->paymentAmount,
                    strtoupper((string) $r->currency),
                    $verb,
                ));
                foreach ($r->items as $item) {
                    $output->writeln(sprintf(
                        '    → předpis #%d: %.2f (hc %.2f)',
                        $item['request_entry'],
                        $item['amount'],
                        $item['amount_hc'],
                    ));
                }
                break;
            case MatchResult::STATUS_ROUTED_UNALLOCATED:
                $output->writeln(sprintf('<comment>tx #%d → přesměrováno na %s, ale úhrada se nenašla (alokace přeskočena)</comment>', $r->txId, $r->targetCode));
                break;
            case MatchResult::STATUS_SKIPPED:
                // not_on_clearing je u --all běžný šum (už spárované) — ztlumit.
                if ($r->reason !== 'not_on_clearing') {
                    $output->writeln(sprintf('tx #%d — přeskočeno (%s)', $r->txId, $r->reason));
                }
                break;
        }
    }
}
