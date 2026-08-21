<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Hlavní kniha nad reálným dev DS — deník se seeduje přímými INSERTy
 * (vzor AccountingEngineTest: engine čte DB, nezajímá ho, jak data vznikla).
 * Používají se účty třídy 9 s prefixem 999/997, které v provisionovaném
 * rozvrhu nemají pohyby — na sdíleném DS ale mohou existovat cizí data,
 * proto se sumy ověřují jen na seedovaných účtech a invarianty
 * (closing = opening + turnover, total = suma tříd) na celém výsledku.
 *
 * Běh přes ReportRunner s registry z ReportDefinitionLoader = end-to-end
 * včetně deklarace v modules/economy/accounting/config/reports.jsonc.
 */
class GeneralLedgerBuilderTest extends IntegrationTestCase
{
    private const REPORT_ID  = 'economy.accounting.generalLedger';
    private const ERROR_MASK = '99????';

    /** @var list<int> */
    private array $createdJournalIds = [];

    private string $yearName;
    /** @var list<int> Id běžných měsíců roku v pořadí date_begin. */
    private array $regularMonthIds;

    protected function setUp(): void
    {
        parent::setUp();

        // Fiskální rok s aspoň 7 běžnými měsíci (interval Q2 + pohyb po něm).
        $years = $this->db->fetchAll(
            'SELECT [id], [name] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != 90 ORDER BY [name]',
        );
        foreach ($years as $year) {
            $months = $this->db->fetchAll(
                'SELECT [id] FROM [economy_codebooks_fiscal_months]'
                . ' WHERE [fiscal_year] = %i AND [period_type] = 1 ORDER BY [date_begin], [id]',
                (int) $year['id'],
            );
            if (count($months) >= 7) {
                $this->yearName        = (string) $year['name'];
                $this->regularMonthIds = array_map(static fn (array $m): int => (int) $m['id'], $months);
                return;
            }
        }
        $this->markTestSkipped('DS has no fiscal year with at least 7 regular months.');
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdJournalIds as $id) {
            $this->db->deleteWhere('economy_accounting_journal', 'id = %i', $id);
        }
    }

    private function seedJournalRow(int $monthOrdinal, string $accountNumber, float $dr, float $cr, bool $isError = false): void
    {
        $monthId = $this->regularMonthIds[$monthOrdinal - 1];
        $yearId  = (int) $this->db->fetchSingle(
            'SELECT [fiscal_year] FROM [economy_codebooks_fiscal_months] WHERE [id] = %i',
            $monthId,
        );
        $dateBegin = $this->db->fetchSingle(
            'SELECT [date_begin] FROM [economy_codebooks_fiscal_months] WHERE [id] = %i',
            $monthId,
        );

        $this->createdJournalIds[] = $this->db->insertRow('economy_accounting_journal', [
            'source_kind'     => 'doc',
            'doc_head'        => null,
            'doc_number'      => 'RPT-TEST',
            'accounting_date' => $dateBegin,
            'fiscal_year'     => $yearId,
            'fiscal_month'    => $monthId,
            'account'         => null,
            'account_number'  => $accountNumber,
            'is_error'        => $isError ? 1 : 0,
            'money_dr'        => $dr,
            'money_cr'        => $cr,
            'text'            => 'GeneralLedgerBuilderTest seed',
        ]);
    }

    private function runReport(string $detail): ReportResult
    {
        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry           = ReportDefinitionLoader::load($this->dsConfig, $modulePathResolver, 'cs');
        $runner             = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs');

        return $runner->run(self::REPORT_ID, [
            'fiscalYear' => $this->yearName,
            'monthFrom'  => '4',
            'monthTo'    => '6',
            'detail'     => $detail,
        ]);
    }

    private function findDetailRow(ReportResult $result, string $account): ?ReportRow
    {
        foreach ($result->rows as $row) {
            if ($row->kind === ReportRowKind::Detail && $row->account === $account) {
                return $row;
            }
        }
        return null;
    }

    public function testGeneralLedgerAnalytic(): void
    {
        // Otevírací stav (měsíc 1, před intervalem 4–6).
        $this->seedJournalRow(1, '999901', 1000.0, 0.0);
        $this->seedJournalRow(1, '997001', 0.0, 500.0);
        // Pohyby v intervalu.
        $this->seedJournalRow(4, '999901', 200.0, 50.0);
        $this->seedJournalRow(5, '999902', 10.0, 0.0);
        $this->seedJournalRow(6, '997001', 30.0, 0.0);
        // Chybový řádek (nedohledaný účet) v intervalu.
        $this->seedJournalRow(5, self::ERROR_MASK, 99.99, 0.0, isError: true);
        // Pohyb PO intervalu — nesmí vstoupit ani do opening, ani do turnover.
        $this->seedJournalRow(7, '999901', 7777.0, 0.0);

        $result = $this->runReport('analytic');
        $array  = $result->toArray();

        $this->assertSame(self::REPORT_ID, $array['reportId']);
        $this->assertSame(
            ['fiscalYear' => $this->yearName, 'monthFrom' => 4, 'monthTo' => 6],
            $array['params']['period'],
        );
        $this->assertSame(['opening', 'turnover', 'closing'], array_column($array['columns'], 'id'));

        // Opening / turnover / closing per účet.
        $row = $this->findDetailRow($result, '999901');
        $this->assertNotNull($row);
        $this->assertSame(['md' => 1000.0, 'd' => 0.0, 'balance' => 1000.0], $row->values['opening']);
        $this->assertSame(['md' => 200.0, 'd' => 50.0, 'balance' => 150.0], $row->values['turnover']);
        $this->assertSame(['md' => 1200.0, 'd' => 50.0, 'balance' => 1150.0], $row->values['closing']);

        $row = $this->findDetailRow($result, '999902');
        $this->assertNotNull($row);
        $this->assertSame(['md' => 0.0, 'd' => 0.0, 'balance' => 0.0], $row->values['opening']);
        $this->assertSame(['md' => 10.0, 'd' => 0.0, 'balance' => 10.0], $row->values['turnover']);
        $this->assertSame(['md' => 10.0, 'd' => 0.0, 'balance' => 10.0], $row->values['closing']);

        $row = $this->findDetailRow($result, '997001');
        $this->assertNotNull($row);
        $this->assertSame(['md' => 0.0, 'd' => 500.0, 'balance' => -500.0], $row->values['opening']);
        $this->assertSame(['md' => 30.0, 'd' => 0.0, 'balance' => 30.0], $row->values['turnover']);
        $this->assertSame(['md' => 30.0, 'd' => 500.0, 'balance' => -470.0], $row->values['closing']);

        // Chybový řádek: samostatný detail s maskou + zpráva + status errors.
        $errorRow = $this->findDetailRow($result, self::ERROR_MASK);
        $this->assertNotNull($errorRow);
        $this->assertSame(self::ERROR_MASK, $errorRow->label);
        $this->assertSame(['md' => 99.99, 'd' => 0.0, 'balance' => 99.99], $errorRow->values['turnover']);

        $this->assertSame('errors', $array['status']);
        $maskMessages = array_values(array_filter(
            $array['messages'],
            fn (array $m): bool => str_contains($m['text'], self::ERROR_MASK),
        ));
        $this->assertCount(1, $maskMessages);
        $this->assertSame('error', $maskMessages[0]['severity']);
        $this->assertSame('journal.accountNotFound', $maskMessages[0]['code']);

        // Invariant na CELÉM výsledku: closing = opening + turnover per strany.
        foreach ($result->rows as $row) {
            foreach (['md', 'd'] as $side) {
                $this->assertEqualsWithDelta(
                    $row->values['opening'][$side] + $row->values['turnover'][$side],
                    $row->values['closing'][$side],
                    0.005,
                    "closing != opening + turnover ({$side}) on account {$row->account}",
                );
            }
        }

        // Total = suma tříd (subtotal level 1) — na celém výsledku.
        $totalRow   = null;
        $classSums  = ['md' => 0.0, 'd' => 0.0];
        foreach ($result->rows as $row) {
            if ($row->kind === ReportRowKind::Total) {
                $totalRow = $row;
            }
            if ($row->kind === ReportRowKind::Subtotal && $row->level === 1) {
                $classSums['md'] += $row->values['closing']['md'];
                $classSums['d']  += $row->values['closing']['d'];
            }
        }
        $this->assertNotNull($totalRow);
        $this->assertEqualsWithDelta($classSums['md'], $totalRow->values['closing']['md'], 0.005);
        $this->assertEqualsWithDelta($classSums['d'], $totalRow->values['closing']['d'], 0.005);
    }

    public function testGeneralLedgerSynthetic(): void
    {
        $this->seedJournalRow(1, '999901', 1000.0, 0.0);
        $this->seedJournalRow(4, '999901', 200.0, 50.0);
        $this->seedJournalRow(5, '999902', 10.0, 0.0);
        $this->seedJournalRow(5, self::ERROR_MASK, 99.99, 0.0, isError: true);

        $result = $this->runReport('synthetic');

        // Analytiky 999901 + 999902 se slily do syntetiky 999.
        $this->assertNull($this->findDetailRow($result, '999901'));
        $row = $this->findDetailRow($result, '999');
        $this->assertNotNull($row);
        $this->assertSame(3, $row->level);
        $this->assertSame(['md' => 1000.0, 'd' => 0.0, 'balance' => 1000.0], $row->values['opening']);
        $this->assertSame(['md' => 210.0, 'd' => 50.0, 'balance' => 160.0], $row->values['turnover']);
        $this->assertSame(['md' => 1210.0, 'd' => 50.0, 'balance' => 1160.0], $row->values['closing']);

        // Chybová maska se na prefix neagreguje — zůstává samostatným řádkem.
        $errorRow = $this->findDetailRow($result, self::ERROR_MASK);
        $this->assertNotNull($errorRow);
        $this->assertSame('errors', $result->toArray()['status']);
    }
}
