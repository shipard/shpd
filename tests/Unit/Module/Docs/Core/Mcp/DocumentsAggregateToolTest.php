<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Mcp;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\Mcp\DocumentsAggregateTool;

/**
 * documents_aggregate — whitelist argumentů, `_dom` sloupce, resolve fiskálního
 * roku, podíly proti grand totalu a stránkování top-N.
 *
 * Mock DataSourceConnection rozlišuje dotazy podle metody: skupiny jdou přes
 * fetchAll nad docs_core_heads, grand total přes fetchRow, resolve fiskálního
 * roku přes fetchAll nad codebookem (rozlišeno needle v SQL).
 */
class DocumentsAggregateToolTest extends TestCase
{
    private const array DOC_TYPES = [
        'invno' => ['name' => 'Faktura vydaná'],
        'invni' => ['name' => 'Faktura přijatá'],
    ];

    private string $capturedSql = '';
    /** @var array<int, mixed> */
    private array $capturedParams = [];
    private string $capturedTotalSql = '';
    /** @var array<int, mixed> */
    private array $capturedTotalParams = [];

    /**
     * @param array<int, array<string, mixed>> $groupRows
     * @param array<string, mixed>|null        $totalRow
     * @param array<int, array<string, mixed>> $fiscalYears
     */
    private function tool(
        array $groupRows,
        ?array $totalRow = null,
        array $fiscalYears = [],
    ): array {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($groupRows, $fiscalYears): array {
                if (str_contains($sql, 'economy_codebooks_fiscal_years')) {
                    return $fiscalYears;
                }
                $this->capturedSql    = $sql;
                $this->capturedParams = $params;
                return $groupRows;
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($totalRow): ?array {
                $this->capturedTotalSql    = $sql;
                $this->capturedTotalParams = $params;
                return $totalRow;
            },
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? self::DOC_TYPES : null,
        );

        $ctx = new McpInvocationContext(new AuthContext(true, 1, 'api_key'), $db, [], $config);

        return [new DocumentsAggregateTool(), $ctx];
    }

    /** @param array<string, mixed> $args */
    private function call(array $args, array $groupRows, ?array $totalRow = null, array $fiscalYears = []): array
    {
        [$tool, $ctx] = $this->tool($groupRows, $totalRow, $fiscalYears);
        return $tool->call($args, $ctx);
    }

    /** @return array<int, array<string, mixed>> */
    private function partnerRows(int $count, float $step = 1000.0): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'dim_key'       => $i,
                'label_raw'     => "Partner {$i}",
                'measure_value' => number_format($step * (float) ($count - $i + 1), 2, '.', ''),
                'doc_count'     => $i,
                'currency'      => 'czk',
            ];
        }
        return $rows;
    }

    // ── whitelist argumentů ─────────────────────────────────────────────────

    public function testMissingDimensionThrows(): void
    {
        [$tool, $ctx] = $this->tool([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dimension/');
        $tool->call([], $ctx);
    }

    public function testInvalidDimensionThrowsAndLeaksNothingToSql(): void
    {
        [$tool, $ctx] = $this->tool([]);
        try {
            $tool->call(['dimension' => 'partner`; DROP TABLE x'], $ctx);
            $this->fail('Neplatná dimension měla vyhodit InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('dimension', $e->getMessage());
            // whitelist drží: k dotazu se to vůbec nedostalo
            $this->assertSame('', $this->capturedSql);
        }
    }

    public function testInvalidMeasureThrows(): void
    {
        [$tool, $ctx] = $this->tool([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/measure/');
        $tool->call(['dimension' => 'partner', 'measure' => 'total_base_dom'], $ctx);
    }

    public function testInvalidOrderThrows(): void
    {
        [$tool, $ctx] = $this->tool([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/order/');
        $tool->call(['dimension' => 'partner', 'order' => 'measure_asc'], $ctx);
    }

    // ── měra jede nad domácí měnou (_dom, ne _home) ─────────────────────────

    public function testTotalBaseUsesDomColumn(): void
    {
        $this->call(['dimension' => 'partner'], $this->partnerRows(1), ['total_value' => '1000.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk']);
        $this->assertStringContainsString('SUM(`h`.`total_base_dom`)', $this->capturedSql);
        $this->assertStringNotContainsString('total_base_home', $this->capturedSql);
        // grand total počítá tutéž měru
        $this->assertStringContainsString('SUM(`h`.`total_base_dom`)', $this->capturedTotalSql);
    }

    public function testTotalAmountUsesDomColumn(): void
    {
        $this->call(['dimension' => 'partner', 'measure' => 'total_amount'], $this->partnerRows(1), ['total_value' => '1210.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk']);
        $this->assertStringContainsString('SUM(`h`.`total_amount_dom`)', $this->capturedSql);
        $this->assertStringNotContainsString('total_amount_home', $this->capturedSql);
    }

    public function testCountMeasureReturnsIntValueWithoutCurrency(): void
    {
        $result = $this->call(
            ['dimension' => 'partner', 'measure' => 'count'],
            [['dim_key' => 5, 'label_raw' => 'ACME', 'measure_value' => 3, 'doc_count' => 3, 'currency' => 'czk']],
            ['total_value' => 12, 'total_docs' => 12, 'currency_count' => 1, 'currency' => 'czk'],
        );
        $this->assertStringContainsString('COUNT(*) AS `measure_value`', $this->capturedSql);
        $this->assertSame(3, $result['items'][0]['value']);
        $this->assertNull($result['items'][0]['currency']);
        $this->assertSame(25.0, $result['items'][0]['share_pct']);
        $this->assertStringContainsString('celkem 12 dokladů', $result['summary']);
    }

    // ── dimenze ─────────────────────────────────────────────────────────────

    public function testPartnerDimensionGroupsAndJoinsPersons(): void
    {
        $result = $this->call(
            ['dimension' => 'partner', 'doc_type' => 'invni'],
            $this->partnerRows(2),
            ['total_value' => '3000.00', 'total_docs' => 3, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertStringContainsString('GROUP BY `h`.`partner`', $this->capturedSql);
        $this->assertStringContainsString('LEFT JOIN `base_persons_persons` `p` ON `p`.`id` = `h`.`partner`', $this->capturedSql);
        $this->assertSame(['type' => 'person', 'id' => 1], $result['items'][0]['ref']);
        $this->assertSame('Partner 1', $result['items'][0]['full_name']);
        $this->assertSame('CZK', $result['items'][0]['currency']);
    }

    public function testDocTypeDimensionLabelsFromCfgItemAndHasNoRef(): void
    {
        $result = $this->call(
            ['dimension' => 'doc_type'],
            [['dim_key' => 'invni', 'measure_value' => '500.00', 'doc_count' => 2, 'currency' => 'czk']],
            ['total_value' => '500.00', 'total_docs' => 2, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertStringContainsString('GROUP BY `h`.`doc_type`', $this->capturedSql);
        $this->assertSame('Faktura přijatá', $result['items'][0]['full_name']);
        $this->assertNull($result['items'][0]['ref']);
    }

    public function testFiscalMonthDimensionLabelsAsYearMonthAndOrdersByPeriod(): void
    {
        $result = $this->call(
            ['dimension' => 'fiscal_month', 'order' => 'dimension_asc'],
            [
                ['dim_key' => 11, 'cal_year' => 2025, 'cal_month' => 1, 'dim_sort' => '2025-01-01', 'measure_value' => '100.00', 'doc_count' => 1, 'currency' => 'czk'],
                ['dim_key' => 12, 'cal_year' => 2025, 'cal_month' => 12, 'dim_sort' => '2025-12-01', 'measure_value' => '200.00', 'doc_count' => 1, 'currency' => 'czk'],
            ],
            ['total_value' => '300.00', 'total_docs' => 2, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertStringContainsString('LEFT JOIN `economy_codebooks_fiscal_months` `fm`', $this->capturedSql);
        $this->assertStringContainsString('ORDER BY `dim_sort` ASC', $this->capturedSql);
        $this->assertSame('2025-01', $result['items'][0]['full_name']);
        $this->assertSame('2025-12', $result['items'][1]['full_name']);
        // časová řada nemá „největší"
        $this->assertStringNotContainsString('největší', $result['summary']);
    }

    public function testVatPeriodDimensionUsesCodebookName(): void
    {
        $result = $this->call(
            ['dimension' => 'vat_period'],
            [['dim_key' => 7, 'label_raw' => '2025/Q1', 'dim_sort' => '2025-01-01', 'measure_value' => '100.00', 'doc_count' => 1, 'currency' => 'czk']],
            ['total_value' => '100.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertStringContainsString('LEFT JOIN `economy_codebooks_vat_periods` `vp`', $this->capturedSql);
        $this->assertSame('2025/Q1', $result['items'][0]['full_name']);
    }

    public function testNullGroupKeyIsKeptAsUnassigned(): void
    {
        $result = $this->call(
            ['dimension' => 'partner'],
            [['dim_key' => null, 'label_raw' => null, 'measure_value' => '50.00', 'doc_count' => 1, 'currency' => 'czk']],
            ['total_value' => '50.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertSame('(nezařazeno)', $result['items'][0]['full_name']);
        $this->assertNull($result['items'][0]['ref']);
    }

    // ── fiskální rok přes codebook ──────────────────────────────────────────

    public function testFiscalYearIsResolvedThroughCodebook(): void
    {
        $this->call(
            ['dimension' => 'partner', 'fiscal_year' => '2025'],
            $this->partnerRows(1),
            ['total_value' => '1000.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
            [['id' => 3, 'name' => '2025'], ['id' => 2, 'name' => '2024']],
        );

        $this->assertStringContainsString('`h`.`fiscal_year` = %i', $this->capturedSql);
        // params: [fiscal_year_id, limit+1]
        $this->assertSame([3, 11], $this->capturedParams);
        // grand total má stejné WHERE a stejné parametry bez limitu
        $this->assertStringContainsString('`h`.`fiscal_year` = %i', $this->capturedTotalSql);
        $this->assertSame([3], $this->capturedTotalParams);
    }

    public function testUnknownFiscalYearThrowsWithAvailableList(): void
    {
        [$tool, $ctx] = $this->tool([], null, [['id' => 3, 'name' => '2025'], ['id' => 2, 'name' => '2024']]);

        try {
            $tool->call(['dimension' => 'partner', 'fiscal_year' => '2019'], $ctx);
            $this->fail('Neznámý fiskální rok měl vyhodit InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('2019', $e->getMessage());
            $this->assertStringContainsString('2025, 2024', $e->getMessage());
        }
    }

    // ── podíly, summary, stránkování ────────────────────────────────────────

    public function testSharePctAndSummaryAgainstGrandTotal(): void
    {
        $result = $this->call(
            ['dimension' => 'partner', 'doc_type' => 'invni', 'state' => 'done'],
            [
                ['dim_key' => 1, 'label_raw' => 'Acme s.r.o.', 'measure_value' => '1204000.00', 'doc_count' => 42, 'currency' => 'czk'],
                ['dim_key' => 2, 'label_raw' => 'Beta a.s.', 'measure_value' => '601500.00', 'doc_count' => 10, 'currency' => 'czk'],
            ],
            ['total_value' => '4816000.00', 'total_docs' => 120, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertSame(25.0, $result['items'][0]['share_pct']);
        $this->assertSame(12.5, $result['items'][1]['share_pct']);
        $this->assertSame('1204000.00', $result['items'][0]['value']);
        $this->assertSame(42, $result['items'][0]['doc_count']);

        $this->assertStringContainsString('Top 2 partnerů podle základu bez DPH', $result['summary']);
        $this->assertStringContainsString('invni', $result['summary']);
        $this->assertStringContainsString('jen V pořádku', $result['summary']);
        $this->assertStringContainsString('celkem 4 816 000,00 CZK (120 dokladů)', $result['summary']);
        $this->assertStringContainsString('největší Acme s.r.o. (1 204 000,00 CZK, 25,0 %)', $result['summary']);
    }

    public function testZeroGrandTotalYieldsNullShare(): void
    {
        $result = $this->call(
            ['dimension' => 'partner'],
            [['dim_key' => 1, 'label_raw' => 'ACME', 'measure_value' => '0.00', 'doc_count' => 1, 'currency' => 'czk']],
            ['total_value' => '0.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );
        $this->assertNull($result['items'][0]['share_pct']);
    }

    public function testHasMoreWhenMoreGroupsThanLimit(): void
    {
        $result = $this->call(
            ['dimension' => 'partner', 'limit' => 3],
            $this->partnerRows(4),
            ['total_value' => '10000.00', 'total_docs' => 10, 'currency_count' => 1, 'currency' => 'czk'],
        );

        $this->assertTrue($result['pagination']['has_more']);
        $this->assertSame(3, $result['pagination']['returned']);
        $this->assertSame(3, $result['pagination']['limit']);
        $this->assertSame(0, $result['pagination']['offset']);
        $this->assertCount(3, $result['items']);
        // LIMIT dostal limit + 1
        $this->assertSame([4], $this->capturedParams);
    }

    public function testEmptyResultIsNotAnError(): void
    {
        $result = $this->call(['dimension' => 'partner'], []);

        $this->assertSame([], $result['items']);
        $this->assertStringContainsString('nejsou žádné doklady', $result['summary']);
        $this->assertFalse($result['pagination']['has_more']);
        // grand total se pro prázdný výsledek vůbec nespouští
        $this->assertSame('', $this->capturedTotalSql);
    }

    public function testMixedHomeCurrencyWarnsInSummary(): void
    {
        $result = $this->call(
            ['dimension' => 'partner'],
            $this->partnerRows(1),
            ['total_value' => '1000.00', 'total_docs' => 2, 'currency_count' => 2, 'currency' => 'czk'],
        );

        $this->assertStringContainsString('POZOR', $result['summary']);
        $this->assertStringContainsString('2 různé domácí měny', $result['summary']);
    }

    // ── state ───────────────────────────────────────────────────────────────

    public function testStateDoneFiltersDocState40(): void
    {
        $this->call(
            ['dimension' => 'partner', 'state' => 'done'],
            $this->partnerRows(1),
            ['total_value' => '1000.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );
        $this->assertStringContainsString('`h`.`docState` = 40', $this->capturedSql);
        $this->assertStringNotContainsString('docState` != 90', $this->capturedSql);
    }

    public function testStateAllHasNoDocStateCondition(): void
    {
        $this->call(
            ['dimension' => 'partner', 'state' => 'all'],
            $this->partnerRows(1),
            ['total_value' => '1000.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );
        $this->assertStringNotContainsString('docState', $this->capturedSql);
        $this->assertStringContainsString('WHERE 1', $this->capturedSql);
    }

    public function testStateDefaultExcludesDeleted(): void
    {
        $this->call(
            ['dimension' => 'partner'],
            $this->partnerRows(1),
            ['total_value' => '1000.00', 'total_docs' => 1, 'currency_count' => 1, 'currency' => 'czk'],
        );
        $this->assertStringContainsString('`h`.`docState` != 90', $this->capturedSql);
    }
}
