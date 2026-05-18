<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form\Lookup;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\Lookup\TableLookup;
use Shipard\Core\Module\ModuleDefinition;

class LookupRegistryTest extends TestCase
{
    public function testRegisterAndHas(): void
    {
        $registry = new LookupRegistry();
        $registry->register('base_persons_persons', FakeLookup::class);

        $this->assertTrue($registry->has('base_persons_persons'));
        $this->assertFalse($registry->has('other_table'));
    }

    public function testRegisterRejectsMissingClass(): void
    {
        $registry = new LookupRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        $registry->register('t', 'Nonexistent\\Lookup\\Klass');
    }

    public function testRegisterRejectsNonTableLookupClass(): void
    {
        $registry = new LookupRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a TableLookup');
        $registry->register('t', \stdClass::class);
    }

    public function testCreateReturnsNullForUnregisteredTable(): void
    {
        $registry = new LookupRegistry();
        $db = $this->createMock(DataSourceConnection::class);

        $this->assertNull($registry->create('unknown', $db, null, null));
    }

    public function testCreateReturnsConfiguredInstance(): void
    {
        $registry = new LookupRegistry();
        $registry->register('t', FakeLookup::class);
        $db = $this->createMock(DataSourceConnection::class);

        $instance = $registry->create('t', $db, null, null);

        $this->assertInstanceOf(FakeLookup::class, $instance);
        $this->assertSame($db, $instance->getDbForTest());
    }

    public function testLoadFromModulesRegistersLookups(): void
    {
        $module = new ModuleDefinition(
            id: 'test.mod',
            name: 'Test',
            description: '',
            dependencies: [],
            tables: [],
            extensions: [],
            config: [],
            documentClasses: [],
            viewers: [],
            forms: [],
            settingsItems: [],
            lookups: [
                ['table' => 'tableA', 'class' => FakeLookup::class],
                ['table' => 'tableB', 'class' => FakeLookup::class],
            ],
        );

        $registry = new LookupRegistry();
        $registry->loadFromModules([$module]);

        $this->assertTrue($registry->has('tableA'));
        $this->assertTrue($registry->has('tableB'));
    }
}

class FakeLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        return [new LookupItem(id: 1, primary: 'Mock')];
    }

    public function resolve(array $ids): array
    {
        return [];
    }

    public function getDbForTest(): ?DataSourceConnection
    {
        return $this->db;
    }
}
