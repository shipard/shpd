<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;

/**
 * buildSortedOrderBy (docs/viewer-grid.md §7.1, D9): bez aktivního sortu
 * (nebo mimo mapu) vrací default beze změny; s validním sortem skládá
 * výraz + směr + uniqueTail se stejným směrem (deterministické stránkování).
 */
class TableViewerSortTest extends TestCase
{
    private const COLUMN_MAP = [
        'accounting_date' => 'j.`accounting_date`',
        'partner_name'    => 'p.`full_name`',
    ];

    private const DEFAULT_ORDER = 'ORDER BY j.`accounting_date` DESC, j.`id` DESC';

    /** Testovací subclass exponující protected helper. */
    private function makeViewer(): object
    {
        $ref = new \ReflectionClass(DataSourceConnection::class);
        $db  = $ref->newInstanceWithoutConstructor();

        return new class ($db, 'test_table') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return ['id' => (int) $rowData['id'], 't1' => 'x'];
            }

            public function orderBy(array $columnMap, string $default, string $uniqueTail): string
            {
                return $this->buildSortedOrderBy($columnMap, $default, $uniqueTail);
            }
        };
    }

    public function testWithoutSortReturnsDefaultUnchanged(): void
    {
        $viewer = $this->makeViewer();

        $this->assertSame(
            self::DEFAULT_ORDER,
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );
    }

    public function testSortColumnOutsideMapReturnsDefault(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'text', 'dir' => 'asc']);

        $this->assertSame(
            self::DEFAULT_ORDER,
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );
    }

    public function testValidSortBuildsExprWithDirAndUniqueTail(): void
    {
        $viewer = $this->makeViewer();

        $viewer->setSort(['column' => 'accounting_date', 'dir' => 'asc']);
        $this->assertSame(
            'ORDER BY j.`accounting_date` ASC, j.`id` ASC',
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );

        $viewer->setSort(['column' => 'partner_name', 'dir' => 'desc']);
        $this->assertSame(
            'ORDER BY p.`full_name` DESC, j.`id` DESC',
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );
    }

    public function testInvalidDirFallsBackToAsc(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'accounting_date', 'dir' => 'sideways']);

        $this->assertSame(
            'ORDER BY j.`accounting_date` ASC, j.`id` ASC',
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );
    }

    public function testSetSortNullRestoresDefault(): void
    {
        $viewer = $this->makeViewer();
        $viewer->setSort(['column' => 'accounting_date', 'dir' => 'desc']);
        $viewer->setSort(null);

        $this->assertSame(
            self::DEFAULT_ORDER,
            $viewer->orderBy(self::COLUMN_MAP, self::DEFAULT_ORDER, 'j.`id`'),
        );
    }
}
