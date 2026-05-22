<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Persons\PersonsViewer;

class PersonsViewerTest extends TestCase
{
    public function testToolbarOnEmptySelectionIncludesImportFromRegistry(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new PersonsViewer($db, 'base_persons_persons');

        $actions = $viewer->getToolbarActions(null);

        $ids = array_column($actions, 'id');
        $this->assertContains('create', $ids);
        $this->assertContains('import_from_registry', $ids);

        $registryAction = $actions[array_search('import_from_registry', $ids, true)];
        $this->assertSame('From registry', $registryAction['label'], 'falls back to English label without compiled config');
        $this->assertSame('secondary', $registryAction['variant']);
        $this->assertSame('cloud-download', $registryAction['icon'] ?? null);
    }

    public function testToolbarOnSelectedRowDoesNotIncludeImportFromRegistry(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $viewer = new PersonsViewer($db, 'base_persons_persons');

        $actions = $viewer->getToolbarActions(['id' => 42, 'full_name' => 'Acme']);

        $ids = array_column($actions, 'id');
        $this->assertContains('create', $ids);
        $this->assertContains('edit', $ids);
        $this->assertNotContains(
            'import_from_registry',
            $ids,
            'registry import is a list-level action — hide it when a row is focused',
        );
    }
}
