<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\LookupController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\Lookup\LookupItem;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\Lookup\TableLookup;

class LookupControllerGuardTest extends TestCase
{
    private LookupController $ctrl;
    private DataSourceConnection $db;

    protected function setUp(): void
    {
        $this->ctrl = new LookupController();
        $this->db = $this->createMock(DataSourceConnection::class);
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        return [
            'core_system_users' => TableDefinition::fromArray([
                'tableId' => 1,
                'name'    => 'Users',
                'columns' => [
                    ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ],
            ]),
            'hosting_core_servers' => TableDefinition::fromArray([
                'tableId'   => 2,
                'name'      => 'Servers',
                'adminOnly' => true,
                'columns'   => [
                    ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ],
            ]),
            't' => TableDefinition::fromArray([
                'tableId' => 3,
                'name'    => 'Test',
                'columns' => [
                    ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ],
            ]),
        ];
    }

    private function searchRequest(): Request
    {
        return Request::fromArray('GET', '/api/v1/_ui/lookup/x/search', ['q' => 'foo'], '', []);
    }

    private function resolveRequest(): Request
    {
        return Request::fromArray('GET', '/api/v1/_ui/lookup/x/resolve', ['ids' => '1,2'], '', []);
    }

    private function nonAdmin(): AuthContext
    {
        return new AuthContext(true, 2, 'session', 'shpd_st_y');
    }

    private function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
    }

    private function getStatus(Response $response): int
    {
        $ref  = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return $prop->getValue($response);
    }

    public function testNonAdminGets403OnSystemTable(): void
    {
        $registry = new LookupRegistry();
        $responses = [
            'search'  => $this->ctrl->search('core_system_users', $this->searchRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null),
            'resolve' => $this->ctrl->resolve('core_system_users', $this->resolveRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null),
        ];

        foreach ($responses as $action => $resp) {
            $this->assertSame(403, $this->getStatus($resp), "action {$action}");
            $this->assertSame('FORBIDDEN_SYSTEM_TABLE', $resp->getPayload()['error']['code'], "action {$action}");
        }
    }

    public function testNonAdminGets403OnAdminOnlyTable(): void
    {
        $registry = new LookupRegistry();
        $responses = [
            'search'  => $this->ctrl->search('hosting_core_servers', $this->searchRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null),
            'resolve' => $this->ctrl->resolve('hosting_core_servers', $this->resolveRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null),
        ];

        foreach ($responses as $action => $resp) {
            $this->assertSame(403, $this->getStatus($resp), "action {$action}");
            $this->assertSame('FORBIDDEN_ADMIN_ONLY', $resp->getPayload()['error']['code'], "action {$action}");
        }
    }

    public function testAdminPassesGuard(): void
    {
        // Lookup není registrovaný → 404 LOOKUP_NOT_REGISTERED. Podstatné je,
        // že admin neskončil na 403 — guard ho pustil dál.
        $registry = new LookupRegistry();
        $responses = [
            'search core_system'   => $this->ctrl->search('core_system_users', $this->searchRequest(), $this->admin(), $this->tables(), $this->db, $registry, null),
            'resolve core_system'  => $this->ctrl->resolve('core_system_users', $this->resolveRequest(), $this->admin(), $this->tables(), $this->db, $registry, null),
            'search admin-only'    => $this->ctrl->search('hosting_core_servers', $this->searchRequest(), $this->admin(), $this->tables(), $this->db, $registry, null),
            'resolve admin-only'   => $this->ctrl->resolve('hosting_core_servers', $this->resolveRequest(), $this->admin(), $this->tables(), $this->db, $registry, null),
        ];

        foreach ($responses as $action => $resp) {
            $this->assertSame(404, $this->getStatus($resp), "action {$action}");
            $this->assertSame('LOOKUP_NOT_REGISTERED', $resp->getPayload()['error']['code'], "action {$action}");
        }
    }

    public function testNonAdminPassesOnRegularTable(): void
    {
        $registry = new LookupRegistry();
        $registry->register('t', FakeGuardLookup::class);

        $search  = $this->ctrl->search('t', $this->searchRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null);
        $resolve = $this->ctrl->resolve('t', $this->resolveRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null);

        $this->assertTrue($search->getPayload()['success']);
        $this->assertCount(1, $search->getPayload()['data']['items']);
        $this->assertTrue($resolve->getPayload()['success']);
    }

    public function testNonAdminRegularTableWithoutLookupStill404(): void
    {
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('t', $this->searchRequest(), $this->nonAdmin(), $this->tables(), $this->db, $registry, null);

        $this->assertSame(404, $this->getStatus($resp));
        $this->assertSame('LOOKUP_NOT_REGISTERED', $resp->getPayload()['error']['code']);
    }

    public function testUnknownTableStill404BeforeGuard(): void
    {
        $registry = new LookupRegistry();

        $resp = $this->ctrl->search('core_system_nonexistent', $this->searchRequest(), $this->nonAdmin(), [], $this->db, $registry, null);

        $this->assertSame(404, $this->getStatus($resp));
        $this->assertSame('TABLE_NOT_FOUND', $resp->getPayload()['error']['code']);
    }
}

class FakeGuardLookup extends TableLookup
{
    public function search(string $q, array $filter, int $limit): array
    {
        return [new LookupItem(id: 1, primary: 'x')];
    }

    public function resolve(array $ids): array
    {
        return [new LookupItem(id: 1, primary: 'x')];
    }
}
