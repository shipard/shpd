<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\HostingPortalController;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;

/**
 * GET /_hosting/portal/my-datasources (D10) — scopování na session
 * uživatele, gating na neaktivní modul, filtrace lifecycle/docState
 * (podmínky ověřujeme na zachyceném SQL — DB je mock).
 */
class HostingPortalControllerTest extends TestCase
{
    private HostingPortalController $controller;

    protected function setUp(): void
    {
        $this->controller = new HostingPortalController();
    }

    public function testMissingModuleTablesReturn404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');

        $response = $this->controller->myDatasources($this->userAuth(42), $db, []);

        $payload = $response->getPayload();
        $this->assertSame(404, self::responseStatus($response));
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');

        $response = $this->controller->myDatasources(AuthContext::anonymous(), $db, $this->hostingTables());

        $payload = $response->getPayload();
        $this->assertSame(401, self::responseStatus($response));
        $this->assertSame('UNAUTHORIZED', $payload['error']['code']);
    }

    public function testUserWithoutLinksGetsEmptyList(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $payload = $response->getPayload();
        $this->assertSame(200, self::responseStatus($response));
        $this->assertSame([], $payload['data']['items']);
    }

    public function testReturnsOnlyPortalContractFields(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            [
                'id'      => '7',
                'ds_id'   => 'abcd-efgh-ijkl-mnop',
                'name'    => 'Alfa s.r.o.',
                'url_app' => 'https://alfa.example.com',
                'role'    => 'admin',
                // Sloupce navíc (kdyby je SELECT někdy přinesl) nesmí protéct ven.
                'server'  => '3',
            ],
        ]);

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $payload = $response->getPayload();
        $this->assertSame(200, self::responseStatus($response));
        $this->assertSame([
            [
                'id'      => 7,
                'ds_id'   => 'abcd-efgh-ijkl-mnop',
                'name'    => 'Alfa s.r.o.',
                'url_app' => 'https://alfa.example.com',
                'role'    => 'admin',
            ],
        ], $payload['data']['items']);
    }

    public function testQueryScopesToUserActiveLifecycleAndLiveDocStates(): void
    {
        $capturedSql = null;
        $capturedArgs = null;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$args) use (&$capturedSql, &$capturedArgs) {
                $capturedSql = $sql;
                $capturedArgs = $args;
                return [];
            },
        );

        $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('du.`user` = %i', $capturedSql);
        $this->assertStringContainsString('ds.`lifecycle` = %s', $capturedSql);
        $this->assertStringContainsString('du.`docState` IN %in', $capturedSql);
        $this->assertStringContainsString('ds.`docState` IN %in', $capturedSql);

        $this->assertSame(42, $capturedArgs[0]);
        $this->assertSame('active', $capturedArgs[1]);
        $this->assertSame([10, 40, 80], $capturedArgs[2]);
        $this->assertSame([10, 40, 80], $capturedArgs[3]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function responseStatus(\Shipard\Api\Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    private function userAuth(int $userId): AuthContext
    {
        return new AuthContext(true, $userId, 'session', 'tok', isAdmin: false);
    }

    /** @return array<string, TableDefinition> */
    private function hostingTables(): array
    {
        return [
            'hosting_core_data_sources' => $this->makeTable('hosting_core_data_sources'),
            'hosting_core_ds_users'     => $this->makeTable('hosting_core_ds_users'),
        ];
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId'   => 1,
            'name'      => $name,
            'adminOnly' => true,
            'columns'   => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        ]);
    }
}
