<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\HostingStatsCommand;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Module\Core\Mail\IncomingMessageDocument;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TestableHostingStatsCommand extends HostingStatsCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class HostingStatsCommandTest extends TestCase
{
    private const ALL_TABLES = [
        'core_alerts_alerts',
        'core_mail_extracted_documents',
        'core_mail_incoming_messages',
        'core_system_users',
    ];

    private DataSourceConfig $dsConfig;

    protected function setUp(): void
    {
        $this->dsConfig = $this->createMock(DataSourceConfig::class);
    }

    /**
     * @param list<string> $tables
     * @param list<array{sql: string, args: list<mixed>}> $queries zachycené fetchSingle dotazy
     */
    private function tester(array $tables, array &$queries): CommandTester
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getAllTableNames')->willReturn($tables);
        $db->method('fetchSingle')->willReturnCallback(
            function (mixed ...$args) use (&$queries): int {
                $sql = (string) $args[0];
                $queries[] = ['sql' => $sql, 'args' => array_slice($args, 1)];
                if (str_contains($sql, 'core_alerts_alerts')) {
                    return 2;
                }
                if (str_contains($sql, 'core_mail_extracted_documents')) {
                    return 3;
                }
                if (str_contains($sql, 'core_mail_incoming_messages')) {
                    return 4;
                }
                throw new \LogicException("unexpected fetchSingle: {$sql}");
            },
        );
        // Read-only kontrakt commandu.
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->never())->method('updateWhere');
        $db->expects($this->never())->method('execute');

        $command = new TestableHostingStatsCommand($this->dsConfig, $db, '/nonexistent');
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testJsonOutputCountsBothMetrics(): void
    {
        $queries = [];
        $tester = $this->tester(self::ALL_TABLES, $queries);

        $this->assertSame(0, $tester->execute(['--json' => true]));

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(['alerts' => 2, 'mail' => 7], $decoded);
        $this->assertCount(3, $queries);
    }

    public function testQueriesUseSharedStateConstants(): void
    {
        $queries = [];
        $this->tester(self::ALL_TABLES, $queries)->execute(['--json' => true]);

        [$alerts, $extracted, $failed] = $queries;

        // Aktivní alerty — jen STATE_ACTIVE.
        $this->assertStringContainsString('`alert_state` = %i', $alerts['sql']);
        $this->assertSame([AlertReconciler::STATE_ACTIVE], $alerts['args']);

        // Extrahované doklady: stavy 10/20/30 (40+ se nepočítá), bez 'other'
        // — stejná sémantika jako feed karty.
        $this->assertStringContainsString('`status` IN %in', $extracted['sql']);
        $this->assertStringContainsString("`doc_type` != 'other'", $extracted['sql']);
        $this->assertSame(
            [[
                ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
                ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
                ExtractedDocumentDocument::STATUS_LOW_CONFIDENCE,
            ]],
            $extracted['args'],
        );

        // Selhané analýzy mimo Archiv/Koš.
        $this->assertStringContainsString('`analysis_state` = %i', $failed['sql']);
        $this->assertStringContainsString('`docState` NOT IN %in', $failed['sql']);
        $this->assertSame(
            [
                IncomingMessageDocument::ANALYSIS_FAILED,
                [IncomingMessageDocument::DOC_STATE_ARCHIVED, IncomingMessageDocument::DOC_STATE_TRASH],
            ],
            $failed['args'],
        );
    }

    public function testMissingAlertsTableYieldsNull(): void
    {
        $queries = [];
        $tester = $this->tester(['core_mail_extracted_documents', 'core_mail_incoming_messages'], $queries);
        $tester->execute(['--json' => true]);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(['alerts' => null, 'mail' => 7], $decoded);
    }

    public function testMissingMailTablesYieldNull(): void
    {
        // Stačí jedna chybějící mail tabulka → modul se nepočítá.
        $queries = [];
        $tester = $this->tester(['core_alerts_alerts', 'core_mail_incoming_messages'], $queries);
        $tester->execute(['--json' => true]);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(['alerts' => 2, 'mail' => null], $decoded);
        $this->assertCount(1, $queries);
    }

    public function testHumanOutputWithoutJsonFlag(): void
    {
        $queries = [];
        $tester = $this->tester(self::ALL_TABLES, $queries);

        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Alerts pending: 2', $display);
        $this->assertStringContainsString('Mail pending:   7', $display);
    }
}
