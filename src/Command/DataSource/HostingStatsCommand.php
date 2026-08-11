<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only agregát „kolik čeho čeká" pro hosting (D7) — počty se sémantikou
 * karet feedu, ale laciné COUNTy bez per-user kontextu:
 *
 *   - alerts: aktivní alerty (`alert_state = AlertReconciler::STATE_ACTIVE`)
 *   - mail:   extrahované doklady ve stavech 10/20/30 s `doc_type != 'other'`
 *             + zprávy s trvale selhanou analýzou (70) mimo Archiv/Koš
 *
 * `null` = modul (jeho tabulky) na DS není aktivní. Jen SELECTy, žádný zápis.
 *
 * S `--json` je stdout jediný JSON objekt {"alerts": N|null, "mail": N|null}
 * (žádné dekorace, chyby jdou na stderr) — strojové rozhraní pro stats krok
 * agenta `hosting-sync`.
 */
class HostingStatsCommand extends Command
{
    private const ALERTS_TABLE = 'core_alerts_alerts';
    private const ANALYSES_TABLE = 'core_mail_message_analyses';
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-stats')
             ->setDescription('Collect pending-work counts (alerts, mail) for the hosting stats push')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Print a single JSON object {"alerts", "mail"} to stdout');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $human = $json ? $err : $output;

        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $human->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $tables = $dsConnection->getAllTableNames();

        $alerts = in_array(self::ALERTS_TABLE, $tables, true)
            ? $this->countAlerts($dsConnection)
            : null;
        $mail = in_array(self::ANALYSES_TABLE, $tables, true) && in_array(self::MESSAGES_TABLE, $tables, true)
            ? $this->countMail($dsConnection)
            : null;

        if ($json) {
            $output->writeln((string) json_encode(['alerts' => $alerts, 'mail' => $mail]));
            return Command::SUCCESS;
        }

        $output->writeln('Alerts pending: ' . ($alerts ?? 'n/a (module not active)'));
        $output->writeln('Mail pending:   ' . ($mail ?? 'n/a (module not active)'));

        return Command::SUCCESS;
    }

    private function countAlerts(DataSourceConnection $db): int
    {
        return (int) $db->fetchSingle(
            'SELECT COUNT(*) FROM `' . self::ALERTS_TABLE . '` WHERE `alert_state` = %i',
            AlertReconciler::STATE_ACTIVE,
        );
    }

    /**
     * Stejná sémantika jako MailSuggestionsSource::fetchSuggestionRows()
     * a fetchErrorRows() — návrhové karty = zprávy v 10/20 s otevřeným
     * dokumentovým návrhem poslední úspěšné analýzy, chybové karty mimo
     * Archiv/Koš.
     */
    private function countMail(DataSourceConnection $db): int
    {
        $suggestions = (int) $db->fetchSingle(
            'SELECT COUNT(*) FROM ('
            . 'SELECT `m`.`id`,'
            . ' (SELECT `a`.`canonical_json` IS NOT NULL AND `a`.`resolution` IS NULL'
            . '    AND COALESCE(`a`.`proposed_type`, \'other\') != \'other\''
            . '  FROM `' . self::ANALYSES_TABLE . '` `a`'
            . '  WHERE `a`.`message` = `m`.`id` AND `a`.`status` = 2'
            . '  ORDER BY `a`.`analyzed_at` DESC, `a`.`id` DESC LIMIT 1) AS `open_proposal`'
            . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
            . ' WHERE `m`.`docState` IN %in'
            . ') `t` WHERE `t`.`open_proposal` = 1',
            [IncomingMessageDocument::DOC_STATE_NEW, IncomingMessageDocument::DOC_STATE_OPEN],
        );

        $failed = (int) $db->fetchSingle(
            'SELECT COUNT(*) FROM `' . self::MESSAGES_TABLE . '`'
            . ' WHERE `analysis_state` = %i AND `docState` NOT IN %in',
            IncomingMessageDocument::ANALYSIS_FAILED,
            [IncomingMessageDocument::DOC_STATE_ARCHIVED, IncomingMessageDocument::DOC_STATE_TRASH],
        );

        return $suggestions + $failed;
    }
}
