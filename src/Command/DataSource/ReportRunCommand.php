<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportNotFoundException;
use Shipard\Core\Reports\ReportRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spustí report a vypíše `ReportResult::toArray()` jako čistý JSON na stdout
 * (žádné dekorace — pipe-friendly, vstupní materiál pro `report-diff`
 * a skripty). Wiring `ReportRunner` shodný s `dispatchReports`.
 *
 * D15: výsledek se `status: errors|warnings` je legitimní výstup — poznámka
 * jde na stderr, exit code zůstává 0. Nenulový exit = nevalidní vstup
 * (neznámý report, špatné parametry) nebo chyba prostředí.
 */
class ReportRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('report-run')
             ->setDescription('Spustí report a vypíše ReportResult jako JSON na stdout')
             ->addArgument('reportId', InputArgument::REQUIRED, 'Id reportu (např. economy.accounting.generalLedger)')
             ->addOption('fiscal-year', null, InputOption::VALUE_REQUIRED, 'Název fiskálního roku (např. 2026)')
             ->addOption('month-from', null, InputOption::VALUE_REQUIRED, 'První fiskální měsíc intervalu (1–N)')
             ->addOption('month-to', null, InputOption::VALUE_REQUIRED, 'Poslední fiskální měsíc intervalu (1–N)')
             ->addOption('detail', null, InputOption::VALUE_REQUIRED, 'Úroveň detailu: analytic | synthetic', 'analytic')
             ->addOption('pretty', null, InputOption::VALUE_NONE, 'Formátovaný JSON (odsazení)');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        foreach (['fiscal-year', 'month-from', 'month-to'] as $option) {
            if ($input->getOption($option) === null) {
                $err->writeln("<error>Missing required option --{$option}</error>");
                return Command::INVALID;
            }
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $language     = $dsConfig->getDefaultLanguage();

        try {
            $configRuntime = ConfigRuntime::load($dsConfig->getDataSourceDir(), $language);
        } catch (\Throwable $e) {
            $err->writeln('<error>Failed to load compiled config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $registry = ReportDefinitionLoader::load($dsConfig, $this->getModulePathResolver(), $language);
        $runner   = new ReportRunner($registry, $dsConnection, $configRuntime, $dsConfig->getId(), $language);

        $reportId  = (string) $input->getArgument('reportId');
        $rawParams = [
            'fiscalYear' => (string) $input->getOption('fiscal-year'),
            'monthFrom'  => (string) $input->getOption('month-from'),
            'monthTo'    => (string) $input->getOption('month-to'),
        ];
        // `detail` je per-report parametr — reportu, který ho nedeklaruje,
        // se default nepodsouvá (validátor by ho odmítl jako neznámý).
        $definition = $registry->get($reportId);
        if ($definition !== null) {
            foreach ($definition->params as $param) {
                if ($param['id'] === 'detail') {
                    $rawParams['detail'] = (string) $input->getOption('detail');
                    break;
                }
            }
        }

        try {
            $result = $runner->run($reportId, $rawParams);
        } catch (ReportNotFoundException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            $err->writeln('Available reports: ' . implode(', ', array_map(
                static fn ($d): string => $d->id,
                $registry->getAll(),
            )));
            return Command::INVALID;
        } catch (\InvalidArgumentException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ((bool) $input->getOption('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $output->writeln((string) json_encode($result->toArray(), $flags));

        // D15: errors/warnings nejsou chyba requestu — jen zřetelná stopa na stderr.
        if ($result->status->value !== 'ok') {
            $err->writeln(sprintf(
                '<comment>Note: report finished with status "%s" (%d message(s)) — see "messages" in the output.</comment>',
                $result->status->value,
                count($result->messages),
            ));
        }

        return Command::SUCCESS;
    }
}
