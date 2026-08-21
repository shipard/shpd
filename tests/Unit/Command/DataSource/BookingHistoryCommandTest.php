<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Command\DataSource\BookingHistoryCommand;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryClassifier;
use Shipard\Module\Core\Exchange\BookingHistory\TagCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end `shpd-ds booking-history` nad fixture souborem s mock LLM:
 * validace, report, apply-seed (dry-run i ostrý), tag-items.
 */
class BookingHistoryCommandTest extends TestCase
{
    private string $dsDir;
    private string $inputPath;

    /** @var list<array> SQL zápisy, které by šly do DB */
    public array $sqlCalls = [];

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/bh-cmd-' . uniqid();
        mkdir($this->dsDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->dsDir . '/config/configuration/compiled.cs.json',
            json_encode(['items' => ['core.exchange.contentTags' => [
                'it.internet'     => ['name' => 'Internetové připojení', 'order' => 10],
                'vehicle.fuel'    => ['name' => 'Pohonné hmoty', 'order' => 20],
                'office.supplies' => ['name' => 'Kancelářské potřeby', 'order' => 30],
            ]]]),
        );

        // Účty odpovídají podnikatelské nabídce: 518202 → it.internet,
        // 503100 → vehicle.fuel (obojí jednoznačné).
        $this->inputPath = $this->dsDir . '/history.jsonl';
        file_put_contents($this->inputPath, implode("\n", [
            '{"format":"shpd.economy.booking-history","version":1,"sourceSystem":{"name":"shipard-e10"},"sourceRef":"ds test","chartVariant":"default","currency":"CZK","period":{"from":"2020-01-01","to":"2026-06-30"},"docTypes":["invni"],"recordCount":4}',
            '{"companyId":"11111111","account":"518202","itemName":"Internet","rowText":"Paušál internet 100/10","docCount":40,"rowCount":80,"totalAmount":96000}',
            '{"companyId":"22222222","account":"503100","rowText":"Nafta","docCount":30,"rowCount":60,"totalAmount":180000}',
            '{"companyId":"33333333","account":"999999","rowText":"Nezařaditelná služba","docCount":2,"rowCount":4,"totalAmount":1000}',
            '{"companyId":null,"account":null,"rowText":"","docCount":1,"rowCount":1,"totalAmount":null}',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->dsDir . '/config/configuration/compiled.cs.json');
        @rmdir($this->dsDir . '/config/configuration');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
        $this->sqlCalls = [];
    }

    /** @param array<string, mixed> $llmTags rowTextNorm → štítek pro mock LLM */
    private function tester(
        array $llmTags = [],
        ?array $existingRule = null,
        array $modules = ['economy.items', 'economy.accounting'],
        ?string $chartVariant = null,
    ): CommandTester {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        // Moduly rozhodují, jestli economy_items nese extension
        // accounting_account — bez economy.accounting je --tag-items no-op.
        $dsConfig->method('getModules')->willReturn($modules);

        $dibi = $this->createMock(Connection::class);
        $dibi->method('fetch')->willReturnCallback(
            static fn (mixed ...$args) => $existingRule !== null ? new \Dibi\Row($existingRule) : null,
        );

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        // Settings: varianta osnovy (řídí mapu účet→štítek), backend override
        // není nastavený.
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($chartVariant): ?string {
                $key = (string) ($args[1] ?? '');
                return $key === 'economy.accountChart' && $chartVariant !== null
                    ? json_encode($chartVariant)
                    : null;
            },
        );
        $db->method('fetchAll')->willReturn([]);        // žádné neotagované položky
        $db->method('fetchRow')->willReturn(null);

        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use ($llmTags): LlmChatResult {
                // Prompt nese texty jako „[i] text“ — mock odpovídá dle mapy.
                preg_match_all('/^  \[(\d+)\] (.+)$/m', (string) $params->messages[0]['content'], $m, PREG_SET_ORDER);
                $out = [];
                foreach ($m as $match) {
                    $norm = mb_strtolower(trim($match[2]));
                    $out[] = ['i' => (int) $match[1], 'tag' => $llmTags[$norm] ?? null];
                }
                return new LlmChatResult((string) json_encode($out), 100, 40, 'end_turn', 'claude-x');
            },
        );

        $command = new class($this->dsDir, $dsConfig, $db, $llm) extends BookingHistoryCommand {
            public function __construct(
                private readonly string $testDsDir,
                DataSourceConfig $dsConfig,
                DataSourceConnection $db,
                private readonly LlmClient $testLlm,
            ) {
                parent::__construct($dsConfig, $db, $testLlm);
            }

            protected function getDataSourceDir(): string
            {
                return $this->testDsDir;
            }

            protected function buildResolver(): ModulePathResolver
            {
                return new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
            }

            /** Backend resolver se v testu nedrátuje na DB ani na šifrování. */
            protected function createClassifier(
                DataSourceConnection $db,
                DataSourceConfig $dsConfig,
                ConfigRuntime $config,
                ?int $backendNdx,
                TagCache $cache,
            ): BookingHistoryClassifier {
                $backends = $this->createBackendsStub();
                return new BookingHistoryClassifier($this->testLlm, $backends, $config, null, $cache);
            }

            private function createBackendsStub(): AiBackendResolver
            {
                return new class extends AiBackendResolver {
                    public function __construct() {}

                    public function defaultBackend(): ?array
                    {
                        return ['provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'enc', 'base_url' => null];
                    }

                    public function apiKey(array $backend): ?string
                    {
                        return 'sk-test';
                    }
                };
            }
        };

        $app = new Application('test', '1.0');
        $app->add($command);
        return new CommandTester($app->find('booking-history'));
    }

    public function testMissingInputFails(): void
    {
        $tester = $this->tester();
        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Chybí --input', $tester->getDisplay());
    }

    public function testBrokenFileReportsLineNumber(): void
    {
        $broken = $this->dsDir . '/broken.jsonl';
        file_put_contents($broken, "{\"format\":\"shpd.economy.booking-history\",\"version\":1}\n{\"docCount\":\n");

        $tester = $this->tester();
        $this->assertSame(1, $tester->execute(['--input' => $broken]));
        $this->assertStringContainsString('řádek 2', $tester->getDisplay());
    }

    public function testValidationOnlyWithoutMode(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--input' => $this->inputPath]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('shipard-e10 (ds test)', $display);
        $this->assertStringContainsString('Soubor je validní, záznamů: 4.', $display);
        $this->assertStringContainsString('Bez režimu se nic nepočítá', $display);
        $this->assertFileDoesNotExist($this->inputPath . '.report.md');
    }

    public function testReportWritesMarkdownAndUsesCacheOnSecondRun(): void
    {
        $tags = ['paušál internet 100/10' => 'it.internet', 'nafta' => 'vehicle.fuel'];

        $tester = $this->tester($tags);
        $this->assertSame(0, $tester->execute(['--input' => $this->inputPath, '--report' => true]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Klasifikace: 3 distinct textů', $display);
        $this->assertStringContainsString('z cache 0, nově klasifikováno 3, volání 1', $display);
        $this->assertStringContainsString('Seed kandidátů: 2', $display);

        $reportPath = $this->inputPath . '.report.md';
        $this->assertFileExists($reportPath);
        $markdown = (string) file_get_contents($reportPath);
        $this->assertStringContainsString('# Report účetní historie', $markdown);
        $this->assertStringContainsString('## Konzistence LLM × reverz', $markdown);
        $this->assertStringContainsString('`it.internet` — Internetové připojení', $markdown);
        $this->assertStringContainsString('nové pravidlo', $markdown);

        // Cache sidecar vznikl a druhý běh už LLM nevolá.
        $this->assertFileExists($this->inputPath . '.tags.jsonl');
        $second = $this->tester([]);   // mock by vrátil null štítky — nesmí být zavolán
        $this->assertSame(0, $second->execute(['--input' => $this->inputPath, '--report' => true]));
        $this->assertStringContainsString('z cache 3, nově klasifikováno 0, volání 0', $second->getDisplay());
        $this->assertStringContainsString('`it.internet`', (string) file_get_contents($reportPath));
    }

    public function testReportWithoutLlm(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--input' => $this->inputPath,
            '--report' => true,
            '--no-llm' => true,
            '--report-out' => $this->dsDir . '/custom.md',
        ]);

        $this->assertStringNotContainsString('Klasifikace:', $tester->getDisplay());
        $this->assertFileExists($this->dsDir . '/custom.md');
        $this->assertFileDoesNotExist($this->inputPath . '.tags.jsonl');
        $this->assertStringContainsString(
            'LLM klasifikace neproběhla',
            (string) file_get_contents($this->dsDir . '/custom.md'),
        );
    }

    public function testApplySeedDryRunPrintsPlanWithoutWriting(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute([
            '--input' => $this->inputPath,
            '--apply-seed' => true,
            '--dry-run' => true,
        ]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('--dry-run:', $display);
        $this->assertStringContainsString('nových 2', $display);
    }

    /**
     * Prahy seedu jdou přepnout z příkazové řádky (D37). Ve fixture má
     * každé IČO pokrytí 1.0, takže práh pokrytí 1.1 musí vyřadit všechny.
     */
    public function testSeedThresholdsAreConfigurable(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--input' => $this->inputPath,
            '--apply-seed' => true,
            '--dry-run' => true,
            '--seed-min-coverage' => '1.1',
        ]);
        $this->assertStringContainsString(
            'Žádný kandidát nesplnil prahy',
            $tester->getDisplay(),
        );

        $strictDocs = $this->tester();
        $strictDocs->execute([
            '--input' => $this->inputPath,
            '--apply-seed' => true,
            '--dry-run' => true,
            '--seed-min-docs' => '999',
        ]);
        $this->assertStringContainsString('Žádný kandidát nesplnil prahy', $strictDocs->getDisplay());

        $loose = $this->tester();
        $loose->execute([
            '--input' => $this->inputPath,
            '--apply-seed' => true,
            '--dry-run' => true,
            '--seed-min-share' => '0.1',
            '--seed-min-docs' => '1',
            '--seed-min-coverage' => '0.1',
        ]);
        // IČO účtované na 999999 nemá reverzní štítek vůbec — to prahy
        // nezachrání, zůstávají dvě.
        $this->assertStringContainsString('nových 2', $loose->getDisplay());
    }

    public function testReportShowsCoverageRejectedCandidates(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--input' => $this->inputPath,
            '--report' => true,
            '--no-llm' => true,
            '--report-out' => $this->dsDir . '/coverage.md',
            '--seed-min-coverage' => '1.1',
        ]);

        $markdown = (string) file_get_contents($this->dsDir . '/coverage.md');
        $this->assertStringContainsString('pokrytí ≥ 110,0 %', $markdown, 'report tiskne skutečné prahy');
        $this->assertStringContainsString('**pod prahem pokrytí**', $markdown);
        $this->assertStringContainsString('pod prahem pokrytí 2', $markdown);
    }

    public function testApplySeedSkipsUserRule(): void
    {
        $tester = $this->tester(existingRule: ['tag' => 'goods.stock', 'origin' => 'user']);
        $tester->execute(['--input' => $this->inputPath, '--apply-seed' => true, '--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('přeskočeno', $display);
        $this->assertStringContainsString('(původ user)', $display);
        $this->assertStringContainsString('přeskočeno (user/learned) 2', $display);
    }

    public function testTagItemsReportsNothingToDoWhenNoUntaggedItems(): void
    {
        $tester = $this->tester(chartVariant: 'default');
        $this->assertSame(0, $tester->execute([
            '--input' => $this->inputPath,
            '--tag-items' => true,
            '--dry-run' => true,
        ]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Otagování položek podle účtů', $display);
        $this->assertStringContainsString('netagovaných položek s účtem: 0', $display);
    }

    /**
     * Bez nastavené varianty osnovy je mapa účet→štítek prázdná. Příkaz to
     * musí říct, ne vykázat všechny položky jako „účet mimo nabídku" —
     * na živých DS to byl matoucí výstup u stovky položek.
     */
    public function testTagItemsWithoutChartVariantSaysSoInsteadOfBlamingAccounts(): void
    {
        $tester = $this->tester();
        $tester->execute(['--input' => $this->inputPath, '--tag-items' => true, '--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Varianta účtové osnovy DS není nastavená', $display);
        $this->assertStringNotContainsString('mimo nabídku', $display);
    }

    public function testTagItemsWithoutAccountingModuleDegradesGracefully(): void
    {
        $tester = $this->tester(modules: ['economy.items']);
        $this->assertSame(0, $tester->execute(['--input' => $this->inputPath, '--tag-items' => true]));
        $this->assertStringContainsString('Modul účetnictví není aktivní', $tester->getDisplay());
    }

    public function testModesCombine(): void
    {
        $tester = $this->tester(['nafta' => 'vehicle.fuel'], chartVariant: 'default');
        $this->assertSame(0, $tester->execute([
            '--input'      => $this->inputPath,
            '--report'     => true,
            '--apply-seed' => true,
            '--tag-items'  => true,
            '--dry-run'    => true,
        ]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Report:', $display);
        $this->assertStringContainsString('Seed pravidel IČO → štítek', $display);
        $this->assertStringContainsString('Otagování položek podle účtů', $display);
    }
}
