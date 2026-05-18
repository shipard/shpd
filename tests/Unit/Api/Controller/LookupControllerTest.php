<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\LookupController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\Lookup\TableLookup;

class LookupControllerTest extends TestCase
{
    private LookupController $ctrl;
    private DataSourceConnection $db;

    protected function setUp(): void
    {
        $this->ctrl = new LookupController();
        $this->db = $this->createMock(DataSourceConnection::class);
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);
    }

    private function makeRequest(array $queryParams = []): Request
    {
        return Request::fromArray('GET', '/api/v1/_ui/lookup/test_table/search', $queryParams, '', []);
    }

    private function registryWith(string $table, TableLookup $instance): LookupRegistry
    {
        $registry = new LookupRegistry();
        $registry->register($table, $instance::class);
        return $registry;
    }

    public function testSearchReturnsItems(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['q' => 'foo']), $tables, $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(20, $payload['data']['limit']);
        $this->assertNull($payload['data']['total']);
        $this->assertCount(1, $payload['data']['items']);
        $this->assertSame(['id' => 42, 'primary' => 'foo', 'secondary' => null], $payload['data']['items'][0]);
    }

    public function testSearchUnknownTableReturns404(): void
    {
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('unknown', $this->makeRequest(), [], $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertFalse($payload['success']);
        $this->assertSame('TABLE_NOT_FOUND', $payload['error']['code']);
    }

    public function testSearchUnregisteredLookupReturns404(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('t', $this->makeRequest(), $tables, $this->db, $registry, null);
        $payload = $resp->getPayload();

        $this->assertFalse($payload['success']);
        $this->assertSame('LOOKUP_NOT_REGISTERED', $payload['error']['code']);
    }

    public function testSearchLimitClampedToMax(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => '500']), $tables, $this->db, $registry, null);

        $this->assertSame(50, $resp->getPayload()['data']['limit']);
    }

    public function testSearchRejectsZeroLimit(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => '0']), $tables, $this->db, $registry, null);

        $this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
    }

    public function testSearchRejectsNonNumericLimit(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $resp = $this->ctrl->search('t', $this->makeRequest(['limit' => 'abc']), $tables, $this->db, $registry, null);

        $this->assertSame('BAD_REQUEST', $resp->getPayload()['error']['code']);
    }

    public function testSearchAllowedFilterKeyForwarded(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakePersonFilterLookup());

        $resp = $this->ctrl->search(
            't',
            $this->makeRequest(['filter' => ['person' => '42']]),
            $tables,
            $this->db,
            $registry,
            null,
        );

        $this->assertTrue($resp->getPayload()['success']);
        $this->assertSame(['person' => '42'], FakePersonFilterLookup::$lastFilter);
    }

    public function testSearchUnknownFilterKeyDropped(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakePersonFilterLookup());

        $this->ctrl->search(
            't',
            $this->makeRequest(['filter' => ['person' => '42', 'rogue' => '1']]),
            $tables,
            $this->db,
            $registry,
            null,
        );

        $this->assertSame(['person' => '42'], FakePersonFilterLookup::$lastFilter);
    }

    public function testResolveByCommaSeparatedIds(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $req = Request::fromArray('GET', '/r', ['ids' => '1,2,abc'], '', []);
        $resp = $this->ctrl->resolve('t', $req, $tables, $this->db, $registry, null);

        $this->assertTrue($resp->getPayload()['success']);
        $this->assertSame([1, 2, 'abc'], FakeControllerLookup::$lastResolveIds);
    }

    public function testResolveEmptyIdsReturnsEmpty(): void
    {
        $tables = ['t' => $this->makeTable('Test')];
        $registry = $this->registryWith('t', new FakeControllerLookup());

        $req = Request::fromArray('GET', '/r', ['ids' => ''], '', []);
        $resp = $this->ctrl->resolve('t', $req, $tables, $this->db, $registry, null);

        $this->assertSame(['items' => []], $resp->getPayload()['data']);
    }
}

class FakeControllerLookup extends TableLookup
{
    /** @var list<int|string> */
    public static array $lastResolveIds = [];

    public function search(string $q, array $filter, int $limit): array
    {
        return [new LookupItem(id: 42, primary: $q !== '' ? $q : 'default')];
    }

    public function resolve(array $ids): array
    {
        self::$lastResolveIds = $ids;
        return [];
    }
}

class FakePersonFilterLookup extends TableLookup
{
    /** @var array<string, scalar> */
    public static array $lastFilter = [];

    public function search(string $q, array $filter, int $limit): array
    {
        self::$lastFilter = $filter;
        return [];
    }

    public function resolve(array $ids): array
    {
        return [];
    }

    public function getAllowedFilterKeys(): array
    {
        return ['person'];
    }
}
