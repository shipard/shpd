<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;

/**
 * `TableViewer::structuredFieldProperties()` (#74, I6) — bloky pro obsah
 * detailu typu `properties`. Schéma se vybírá podle `_schema` v hodnotě.
 */
class TableViewerStructuredFieldsTest extends TestCase
{
    private const PROFILE = [
        'version' => '2026',
        'groups'  => [['id' => 'office', 'name' => 'Finanční úřad']],
        'fields'  => [
            ['id' => 'c_ufo', 'type' => 'enumString', 'length' => 5, 'cfgItem' => 'test.offices',
                'group' => 'office', 'name' => 'Finanční úřad'],
        ],
    ];

    private const HEADER = [
        'version' => '2026',
        'fields'  => [['id' => 'druh', 'type' => 'text', 'name' => 'Druh hlášení']],
    ];

    /** @return object{properties: callable} */
    private function viewer(bool $withConfig = true): TableViewer
    {
        $ref = new \ReflectionClass(DataSourceConnection::class);
        $db  = $ref->newInstanceWithoutConstructor();

        $viewer = new class ($db, 'registrations') extends TableViewer {
            public function selectRows(?string $search, array $filters, int $pageNumber): array
            {
                return [];
            }

            public function renderRow(array $rowData): array
            {
                return ['id' => (int) $rowData['id'], 't1' => 'x'];
            }

            /** @return list<array{title: ?string, items: list<array{label: string, value: string}>}> */
            public function properties(array $row, string $column, ?string $fallback = null): array
            {
                return $this->structuredFieldProperties($row, $column, $fallback);
            }
        };

        if ($withConfig) {
            $items = [
                'test.profile'  => self::PROFILE,
                'test.header'   => self::HEADER,
                'test.offices'  => ['464' => ['name' => 'Zlínský kraj']],
            ];
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
            $viewer->setConfig($config);
        }

        return $viewer;
    }

    public function testFallbackSchemaKeyIsUsedForValueWithoutSchemaKey(): void
    {
        $groups = $this->viewer()->properties(
            ['filing_profile' => '{"c_ufo":"464"}'],
            'filing_profile',
            'test.profile',
        );

        $this->assertSame(
            [['title' => 'Finanční úřad', 'items' => [['label' => 'Finanční úřad', 'value' => 'Zlínský kraj']]]],
            $groups,
        );
    }

    public function testSchemaKeyInValueWins(): void
    {
        // Záznam nesoucí jiné schéma (hlavička podání per typ) se zobrazí
        // podle svého `_schema`, ne podle statického fallbacku.
        $groups = $this->viewer()->properties(
            ['header' => '{"_schema":"test.header/2026","druh":"Řádné"}'],
            'header',
            'test.profile',
        );

        $this->assertSame(
            [['title' => 'General', 'items' => [['label' => 'Druh hlášení', 'value' => 'Řádné']]]],
            $groups,
        );
    }

    public function testNullValueAndMissingColumnGiveNoGroups(): void
    {
        $this->assertSame([], $this->viewer()->properties(['filing_profile' => null], 'filing_profile', 'test.profile'));
        $this->assertSame([], $this->viewer()->properties([], 'filing_profile', 'test.profile'));
    }

    public function testUnknownSchemaGivesNoGroups(): void
    {
        $this->assertSame([], $this->viewer()->properties(['x' => '{"a":1}'], 'x', 'nope.missing'));
        $this->assertSame([], $this->viewer(false)->properties(['x' => '{"a":1}'], 'x', 'test.profile'));
    }
}
