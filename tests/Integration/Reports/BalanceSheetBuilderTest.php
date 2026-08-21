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
 * Rozvaha nad reálným dev DS — vzor GeneralLedgerBuilderTest. Pro kind-based
 * zařazení do sekcí se do rozvrhu vkládají dočasné analytiky (097999 aktivum,
 * 497999 pasivum, 397998/397999 aktivně pasivní); deník se seeduje přímými
 * INSERTy vyrovnaných zápisů. Na sdíleném DS mohou existovat cizí pohyby —
 * per-doklad jsou ale vyrovnané uvnitř měsíce, takže invarianty (aktiva =
 * pasiva, vyrovnaný deník) platí na celém výsledku; computed výsledek se
 * ověřuje proti nezávislému SQL a proti výsledovce za stejný interval.
 */
class BalanceSheetBuilderTest extends IntegrationTestCase
{
    private const REPORT_ID = 'economy.accounting.balanceSheet';

    private const ACC_ASSET     = '097999'; // kind 0
    private const ACC_LIABILITY = '497999'; // kind 1
    private const ACC_MIXED_POS = '397998'; // kind 5, skončí s kladným zůstatkem
    private const ACC_MIXED_NEG = '397999'; // kind 5, skončí se záporným zůstatkem

    /** @var list<int> */
    private array $createdJournalIds = [];
    /** @var list<int> */
    private array $createdAccountIds = [];

    private string $yearName;
    private int $yearId;
    /** @var list<int> Id běžných měsíců roku v pořadí date_begin. */
    private array $regularMonthIds;

    protected function setUp(): void
    {
        parent::setUp();

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
                $this->yearId          = (int) $year['id'];
                $this->regularMonthIds = array_map(static fn (array $m): int => (int) $m['id'], $months);
                $this->seedAccounts();
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
        foreach ($this->createdAccountIds as $id) {
            $this->db->deleteWhere('economy_accounting_accounts', 'id = %i', $id);
        }
    }

    private function seedAccounts(): void
    {
        $kinds = [
            self::ACC_ASSET     => 0,
            self::ACC_LIABILITY => 1,
            self::ACC_MIXED_POS => 5,
            self::ACC_MIXED_NEG => 5,
        ];
        foreach ($kinds as $number => $kind) {
            $this->createdAccountIds[] = $this->db->insertRow('economy_accounting_accounts', [
                'number'        => (string) $number,
                'name'          => 'BalanceSheetBuilderTest ' . $number,
                'account_level' => 4,
                'account_kind'  => $kind,
                'docState'      => 40,
                'docStateMain'  => 3,
            ]);
        }
    }

    private function seedJournalRow(int $monthOrdinal, string $accountNumber, float $dr, float $cr): void
    {
        $monthId   = $this->regularMonthIds[$monthOrdinal - 1];
        $dateBegin = $this->db->fetchSingle(
            'SELECT [date_begin] FROM [economy_codebooks_fiscal_months] WHERE [id] = %i',
            $monthId,
        );

        $this->createdJournalIds[] = $this->db->insertRow('economy_accounting_journal', [
            'source_kind'     => 'doc',
            'doc_head'        => null,
            'doc_number'      => 'RPT-TEST',
            'accounting_date' => $dateBegin,
            'fiscal_year'     => $this->yearId,
            'fiscal_month'    => $monthId,
            'account'         => null,
            'account_number'  => $accountNumber,
            'is_error'        => 0,
            'money_dr'        => $dr,
            'money_cr'        => $cr,
            'text'            => 'BalanceSheetBuilderTest seed',
        ]);
    }

    private function runReport(string $reportId, string $detail): ReportResult
    {
        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry           = ReportDefinitionLoader::load($this->dsConfig, $modulePathResolver, 'cs');
        $runner             = new ReportRunner($registry, $this->db, null, $this->dsConfig->getId(), 'cs');

        return $runner->run($reportId, [
            'fiscalYear' => $this->yearName,
            'monthFrom'  => '4',
            'monthTo'    => '6',
            'detail'     => $detail,
        ]);
    }

    /**
     * Rozdělí řádky na sekce podle Total řádků: [aktiva, pasiva] včetně totalu
     * na konci každé sekce.
     *
     * @return array{0: list<ReportRow>, 1: list<ReportRow>}
     */
    private function splitSections(ReportResult $result): array
    {
        $sections = [[], []];
        $index    = 0;
        foreach ($result->rows as $row) {
            $sections[$index][] = $row;
            if ($row->kind === ReportRowKind::Total) {
                $index++;
            }
        }
        $this->assertSame(2, $index, 'Expected exactly two Total rows (assets, liabilities).');
        return [$sections[0], $sections[1]];
    }

    /** @param list<ReportRow> $rows */
    private function findDetail(array $rows, string $account): ?ReportRow
    {
        foreach ($rows as $row) {
            if ($row->kind === ReportRowKind::Detail && $row->account === $account) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Vyrovnaný deník: aktivum, pasivum, aktivně pasivní účty v obou sekcích
     * dle znaménka, computed výsledek z tříd 5/6, invarianty a shoda
     * s výsledovkou.
     */
    public function testBalanceSheetAnalytic(): void
    {
        // Před intervalem (měsíc 1) — vstoupí jen do opening.
        $this->seedJournalRow(1, self::ACC_ASSET, 1000.0, 0.0);
        $this->seedJournalRow(1, self::ACC_LIABILITY, 0.0, 1000.0);
        // V intervalu (měsíc 4), všechny zápisy vyrovnané:
        $this->seedJournalRow(4, self::ACC_MIXED_POS, 300.0, 0.0);
        $this->seedJournalRow(4, self::ACC_LIABILITY, 0.0, 300.0);
        $this->seedJournalRow(4, self::ACC_MIXED_NEG, 0.0, 200.0);
        $this->seedJournalRow(4, self::ACC_ASSET, 200.0, 0.0);
        // Výnos 500 a náklad 150 → výsledek +350.
        $this->seedJournalRow(4, self::ACC_ASSET, 500.0, 0.0);
        $this->seedJournalRow(4, '697996', 0.0, 500.0);
        $this->seedJournalRow(4, '597996', 150.0, 0.0);
        $this->seedJournalRow(4, self::ACC_LIABILITY, 0.0, 150.0);

        $result = $this->runReport(self::REPORT_ID, 'analytic');
        $array  = $result->toArray();

        $this->assertSame(self::REPORT_ID, $array['reportId']);
        $this->assertSame(['opening', 'closing'], array_column($array['columns'], 'id'));

        [$assets, $liabilities] = $this->splitSections($result);

        // Aktiva: kind 0 + aktivně pasivní s kladným zůstatkem, syrové balance.
        $row = $this->findDetail($assets, self::ACC_ASSET);
        $this->assertNotNull($row);
        $this->assertSame(['md' => 1000.0, 'd' => 0.0, 'balance' => 1000.0], $row->values['opening']);
        $this->assertSame(['md' => 1700.0, 'd' => 0.0, 'balance' => 1700.0], $row->values['closing']);
        $row = $this->findDetail($assets, self::ACC_MIXED_POS);
        $this->assertNotNull($row);
        $this->assertSame(300.0, $row->values['closing']['balance']);
        $this->assertNull($this->findDetail($assets, self::ACC_LIABILITY));
        $this->assertNull($this->findDetail($assets, self::ACC_MIXED_NEG));

        // Pasiva: kind 1 + aktivně pasivní se záporným zůstatkem; balance
        // otočené (md/d syrové), takže pasivum se zobrazuje kladně.
        $row = $this->findDetail($liabilities, self::ACC_LIABILITY);
        $this->assertNotNull($row);
        $this->assertSame(['md' => 0.0, 'd' => 1000.0, 'balance' => 1000.0], $row->values['opening']);
        $this->assertSame(['md' => 0.0, 'd' => 1450.0, 'balance' => 1450.0], $row->values['closing']);
        $row = $this->findDetail($liabilities, self::ACC_MIXED_NEG);
        $this->assertNotNull($row);
        $this->assertSame(200.0, $row->values['closing']['balance']);

        // Computed výsledek: poslední položka pasiv před totalem, md/d = 0,
        // hodnota = nezávislé SQL (na sdíleném DS mohou být cizí pohyby 5/6).
        $computed = $liabilities[count($liabilities) - 2];
        $this->assertSame(ReportRowKind::Computed, $computed->kind);
        $this->assertNull($computed->account);
        $this->assertSame(0.0, $computed->values['closing']['md']);
        $this->assertSame(0.0, $computed->values['closing']['d']);
        $openingMonthIds = array_map(
            static fn (array $m): int => (int) $m['id'],
            $this->db->fetchAll(
                'SELECT [id] FROM [economy_codebooks_fiscal_months]'
                . ' WHERE [fiscal_year] = %i AND [period_type] = 0',
                $this->yearId,
            ),
        );
        $beforeIds = array_merge($openingMonthIds, array_slice($this->regularMonthIds, 0, 3));
        $ytdIds    = array_merge($openingMonthIds, array_slice($this->regularMonthIds, 0, 6));
        foreach (['opening' => $beforeIds, 'closing' => $ytdIds] as $columnId => $monthIds) {
            $sql = (float) $this->db->fetchSingle(
                'SELECT COALESCE(SUM([money_cr] - [money_dr]), 0)'
                . ' FROM [economy_accounting_journal]'
                . ' WHERE [fiscal_month] IN %in AND LEFT([account_number], 1) IN %in',
                $monthIds,
                ['5', '6'],
            );
            $this->assertEqualsWithDelta($sql, $computed->values[$columnId]['balance'], 0.005);
        }

        // Invarianty D15: AKTIVA CELKEM == PASIVA CELKEM, status ok.
        $assetsTotal = $assets[count($assets) - 1];
        $liabTotal   = $liabilities[count($liabilities) - 1];
        $this->assertSame(ReportRowKind::Total, $assetsTotal->kind);
        $this->assertSame(ReportRowKind::Total, $liabTotal->kind);
        $this->assertSame('AKTIVA CELKEM', $assetsTotal->label);
        $this->assertSame('PASIVA CELKEM', $liabTotal->label);
        foreach (['opening', 'closing'] as $columnId) {
            $this->assertEqualsWithDelta(
                $assetsTotal->values[$columnId]['balance'],
                $liabTotal->values[$columnId]['balance'],
                0.005,
                "assets != liabilities ({$columnId})",
            );
        }
        $this->assertSame('ok', $array['status']);

        // Zisk z rozvahy == zisk z výsledovky za stejný interval (ytd).
        $profitLoss = $this->runReport('economy.accounting.profitLoss', 'analytic');
        $plComputed = $profitLoss->rows[count($profitLoss->rows) - 1];
        $this->assertSame(ReportRowKind::Computed, $plComputed->kind);
        $this->assertEqualsWithDelta(
            $plComputed->values['ytd']['balance'],
            $computed->values['closing']['balance'],
            0.005,
        );
    }

    /** Synthetic: analytiky kind 5 téhož syntetického účtu v opačných sekcích. */
    public function testBalanceSheetSynthetic(): void
    {
        $this->seedJournalRow(4, self::ACC_MIXED_POS, 300.0, 0.0);
        $this->seedJournalRow(4, self::ACC_MIXED_NEG, 0.0, 200.0);
        $this->seedJournalRow(4, self::ACC_ASSET, 0.0, 100.0);

        $result = $this->runReport(self::REPORT_ID, 'synthetic');
        [$assets, $liabilities] = $this->splitSections($result);

        // Syntetika 397 se objeví v obou sekcích — každá jen se svými analytikami.
        $row = $this->findDetail($assets, '397');
        $this->assertNotNull($row);
        $this->assertSame(3, $row->level);
        $this->assertSame(300.0, $row->values['closing']['balance']);
        $row = $this->findDetail($liabilities, '397');
        $this->assertNotNull($row);
        $this->assertSame(200.0, $row->values['closing']['balance']);
    }

    /**
     * Záměrně nevyrovnaný deník: jednostranný zápis v intervalu → oba
     * invarianty porušené pro closing, totaly přesto spočtené, HTTP kontrakt
     * (status errors) drží.
     */
    public function testBalanceSheetImbalance(): void
    {
        $this->seedJournalRow(4, self::ACC_ASSET, 500.0, 0.0);

        $result = $this->runReport(self::REPORT_ID, 'analytic');
        $array  = $result->toArray();

        $this->assertSame('errors', $array['status']);
        $codes = array_column($array['messages'], 'code');
        $this->assertContains('balanceSheet.notBalanced', $codes);
        $this->assertContains('balanceSheet.journalImbalance', $codes);

        // Opening nevyrovnaný není (seed je v intervalu) — chyby jen pro closing.
        foreach ($array['messages'] as $message) {
            $this->assertStringContainsString("'closing'", $message['text']);
        }

        // Totaly jsou přesto spočtené a liší se právě o seedovaných 500.
        [$assets, $liabilities] = $this->splitSections($result);
        $assetsTotal = $assets[count($assets) - 1];
        $liabTotal   = $liabilities[count($liabilities) - 1];
        $this->assertEqualsWithDelta(
            $assetsTotal->values['closing']['balance'] - 500.0,
            $liabTotal->values['closing']['balance'],
            0.005,
        );
    }

    public function testBalanceOnWrongSideWarning(): void
    {
        // Vyrovnaný deník: pasivní účet (kind 1) skončí s MD zůstatkem
        // („pohledávková" strana — vzor: přeplatek DPH na účtu značeném
        // jako pasivum), protistrana na kind 5 účtu (bez warningu).
        $this->seedJournalRow(2, self::ACC_LIABILITY, 300.0, 0.0);
        $this->seedJournalRow(2, self::ACC_MIXED_NEG, 0.0, 300.0);

        $result = $this->runReport(self::REPORT_ID, 'analytic');
        $array  = $result->toArray();

        // Warning, ne error — status warnings.
        $this->assertSame('warnings', $array['status']);
        $warnings = array_values(array_filter(
            $array['messages'],
            static fn (array $m): bool => $m['code'] === 'balanceSheet.balanceOnWrongSide',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['severity']);
        $this->assertSame(self::ACC_LIABILITY, $warnings[0]['rowRef']);

        // Zařazení respektuje rozvrh: účet zůstává v pasivech s otočeným
        // (záporným) balance; kind 5 protistrana jde dle znaménka také
        // do pasiv — sekce Aktiva je prázdná a totaly sedí (0 == 0).
        [$assets, $liabilities] = $this->splitSections($result);
        $liab = $this->findDetail($liabilities, self::ACC_LIABILITY);
        $this->assertNotNull($liab);
        $this->assertEqualsWithDelta(-300.0, $liab->values['closing']['balance'], 0.005);

        $assetsTotal = $assets[count($assets) - 1];
        $liabTotal   = $liabilities[count($liabilities) - 1];
        $this->assertEqualsWithDelta(
            $assetsTotal->values['closing']['balance'],
            $liabTotal->values['closing']['balance'],
            0.005,
        );
    }
}
