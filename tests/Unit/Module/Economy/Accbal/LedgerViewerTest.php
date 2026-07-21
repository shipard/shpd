<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accbal\LedgerViewer;

/**
 * Viewer saldo pohybů — grid layout se skupinami per partner
 * (docs/viewer-grid.md §7.4): řazení primárně dle partnera (kontrakt D12),
 * viewGroup filtr přes kód saldokonta, footer se součty v domácí měně
 * sdílející WHERE se selectRows vč. replikace filtru „Jen otevřené".
 */
class LedgerViewerTest extends TestCase
{
    /** @var list<array{sql: string, params: array}> */
    private array $queries = [];

    private function makeViewer(array $fetchAllRows = [], ?array $fetchRowResult = null): LedgerViewer
    {
        $this->queries = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchAllRows): array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchAllRows;
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($fetchRowResult): ?array {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
                return $fetchRowResult;
            },
        );

        $viewer = new LedgerViewer($db, 'economy_accbal_ledger');
        $viewer->setLanguage('cs');
        return $viewer;
    }

    // ── selectRows: JOIN deníku + řazení per partner (D12) ──────────────────

    public function testSelectRowsJoinsJournalAndOrdersByPartner(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [], 0);

        $sql = $this->queries[0]['sql'];
        $this->assertStringContainsString('LEFT JOIN `economy_accounting_journal` j ON j.`id` = l.`journal_row`', $sql);
        $this->assertStringContainsString('j.`accounting_date`', $sql);
        // D12: primárně partner (bez partnera na konec), l.partner jistí
        // shodná jména; uvnitř role + datum.
        $this->assertStringContainsString(
            'ORDER BY ISNULL(p.`full_name`) ASC, p.`full_name` ASC, l.`partner` ASC,'
            . ' l.`bal_side` ASC, j.`accounting_date` ASC, l.`id` ASC',
            $sql,
        );
        $this->assertStringContainsString('LIMIT 0, 51', $sql, 'pageSize + 1 kvůli hasMore');
    }

    public function testViewGroupFilterMatchesBalanceCode(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [['id' => 'viewGroup', 'value' => 'receivables']], 0);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString('b.`code` = %s', $sql);
        $this->assertSame(['receivables'], $params);
    }

    public function testViewGroupAllAndStaleActiveSkipCondition(): void
    {
        foreach (['all', 'active'] as $vg) {
            $viewer = $this->makeViewer();
            $viewer->selectRows(null, [['id' => 'viewGroup', 'value' => $vg]], 0);
            ['sql' => $sql, 'params' => $params] = $this->queries[0];
            $this->assertStringNotContainsString('b.`code`', $sql, "viewGroup={$vg}");
            $this->assertSame([], $params, "viewGroup={$vg}");
        }
    }

    public function testOnlyOpenFilterUsesHaving(): void
    {
        $viewer = $this->makeViewer();
        $viewer->selectRows(null, [['id' => 'only_open', 'value' => '1']], 0);

        // HAVING navazuje přímo na JOINy — only_open nepřidává WHERE
        // (per-row residual nejde filtrovat ve WHERE).
        $this->assertStringContainsString(
            'j.`id` = l.`journal_row` HAVING residual <> 0',
            $this->queries[0]['sql'],
        );
    }

    // ── Grid: layout, sloupce ────────────────────────────────────────────────

    public function testDefaultLayoutIsGrid(): void
    {
        $this->assertSame('grid', $this->makeViewer()->getDefaultLayout());
    }

    public function testGridColumnsShapeWithoutSortable(): void
    {
        $columns = $this->makeViewer()->getGridColumns();

        $this->assertSame(
            ['accounting_date', 'role', 'payment_reference', 'due_date', 'amount', 'residual', 'text', 'balance'],
            array_column($columns, 'id'),
        );
        // Sort klikem by rozbil clustering skupin (D12) — žádný sloupec
        // nesmí být sortable.
        foreach ($columns as $col) {
            $this->assertArrayNotHasKey('sortable', $col, $col['id']);
        }
    }

    // ── Grid: render řádků se skupinami ─────────────────────────────────────

    public function testRenderGridRowWithPartnerGroup(): void
    {
        $row = $this->makeViewer()->renderGridRow([
            'id'                 => 7,
            'bal_side'           => 0,
            'partner'            => 42,
            'partner_name'       => 'AKIMA, spol. s r.o.',
            'accounting_date'    => '2026-07-01',
            'payment_reference'  => '2026001',
            'due_date'           => '2026-07-15',
            'currency'           => 'czk',
            'amount'             => 12100.0,
            'residual'           => 12100.0,
            'text'               => 'FV 2026001',
            'balance_name'       => 'Pohledávky z obchodních vztahů',
            'balance_short_name' => 'Pohledávky',
        ]);

        $this->assertSame(7, $row['id']);
        $this->assertSame('primary', $row['stateStyle']);
        $this->assertSame(['key' => 'p42', 'label' => 'AKIMA, spol. s r.o.'], $row['group']);
        $this->assertSame(['text' => 'Předpis', 'badge' => 'primary'], $row['cells']['role']);
        $this->assertSame(
            [['text' => '12 100,00', 'class' => 'amount'], ['text' => 'CZK', 'class' => 'muted']],
            $row['cells']['amount'],
        );
        $this->assertSame(['text' => '12 100,00', 'class' => 'amount'], $row['cells']['residual']);
        $this->assertSame('Pohledávky', $row['cells']['balance'], 'short_name má přednost');
        $this->assertSame('1. 7. 2026', $row['cells']['accounting_date']);
    }

    public function testRenderGridRowWithoutPartnerAndSettled(): void
    {
        $row = $this->makeViewer()->renderGridRow([
            'id'           => 8,
            'bal_side'     => 1,
            'partner'      => null,
            'partner_name' => null,
            'currency'     => 'czk',
            'amount'       => 500.0,
            'residual'     => 0.0,
            'balance_name' => 'Nespárované platby',
        ]);

        $this->assertSame(['key' => 'p0', 'label' => '(Bez partnera)'], $row['group']);
        $this->assertSame('done', $row['stateStyle']);
        $this->assertSame(['text' => 'Úhrada', 'badge' => 'success'], $row['cells']['role']);
        $this->assertNull($row['cells']['residual'], 'vyrovnaný pohyb má prázdné Zbývá');
        $this->assertSame('Nespárované platby', $row['cells']['balance'], 'fallback na name');
    }

    // ── Grid: footer (D7) ────────────────────────────────────────────────────

    public function testRenderGridFooterSharesWhereAndComputesBalance(): void
    {
        $viewer = $this->makeViewer(fetchRowResult: [
            'sum_requests'  => 250000.0,
            'sum_payments'  => 100000.0,
            'home_currency' => 'czk',
        ]);

        $footer = $viewer->renderGridFooter(null, [['id' => 'viewGroup', 'value' => 'payables']]);

        ['sql' => $sql, 'params' => $params] = $this->queries[0];
        $this->assertStringContainsString('b.`code` = %s', $sql, 'footer sdílí WHERE se selectRows');
        $this->assertSame(['payables'], $params);
        $this->assertStringContainsString('SUM(CASE WHEN x.`bal_side` = 0 THEN x.`amount_hc` ELSE 0 END)', $sql);

        $this->assertSame(
            [
                ['text' => 'Zůstatek', 'class' => 'muted'],
                ['text' => '150 000,00 CZK', 'class' => 'amount'],
            ],
            $footer['residual'],
        );
        $this->assertSame(
            [
                ['text' => 'Předpisy', 'class' => 'muted'],
                ['text' => '250 000,00 CZK'],
                ['text' => 'Úhrady', 'class' => 'muted'],
                ['text' => '100 000,00 CZK'],
            ],
            $footer['text'],
        );
    }

    public function testRenderGridFooterReplicatesOnlyOpenViaSubselect(): void
    {
        $viewer = $this->makeViewer(fetchRowResult: []);
        $viewer->renderGridFooter(null, [['id' => 'only_open', 'value' => '1']]);

        $sql = $this->queries[0]['sql'];
        // HAVING ze selectRows se ve footeru replikuje filtrem nad
        // subselectem — agregace jen přes otevřené řádky.
        $this->assertStringContainsString(') x WHERE x.`residual` <> 0', $sql);
        $this->assertStringContainsString('AS residual', $sql);
    }

    public function testRenderGridFooterWithoutOnlyOpenSkipsResidual(): void
    {
        $viewer = $this->makeViewer(fetchRowResult: []);
        $viewer->renderGridFooter(null, []);

        $sql = $this->queries[0]['sql'];
        // Bez only_open se per-row residual subdotazy nepočítají zbytečně.
        $this->assertStringNotContainsString('residual', $sql);
    }
}
