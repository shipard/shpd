<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\HostingPortalController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Module\InstallModuleRegistry;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Portálové endpointy (D10 + hosting-08) — scopování na session uživatele,
 * gating na neaktivní modul, limity self-service create (podmínky ověřujeme
 * na zachyceném SQL — DB je mock).
 */
class HostingPortalControllerTest extends TestCase
{
    private HostingPortalController $controller;
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        $this->controller = new HostingPortalController();
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null) {
            $this->rrmdir($this->tmpDir);
            $this->tmpDir = null;
        }
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
        $db = $this->mockDb();

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $payload = $response->getPayload();
        $this->assertSame(200, self::responseStatus($response));
        $this->assertSame([], $payload['data']['items']);
    }

    public function testReturnsOnlyPortalContractFields(): void
    {
        $db = $this->mockDb(linkedRows: [
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
                'stats'   => null,
                'state'   => 'active',
            ],
        ], $payload['data']['items']);
    }

    public function testStatsSnapshotIsPassedThrough(): void
    {
        $db = $this->mockDb(linkedRows: [
            [
                'id'           => '7',
                'ds_id'        => 'abcd-efgh-ijkl-mnop',
                'name'         => 'Alfa s.r.o.',
                'url_app'      => 'https://alfa.example.com',
                'role'         => 'member',
                'alerts_count' => '3',
                'mail_count'   => null,
                'collected_at' => '2026-08-06 10:00:00',
            ],
        ]);

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $stats = $response->getPayload()['data']['items'][0]['stats'];
        $this->assertSame(3, $stats['alerts']);
        $this->assertNull($stats['mail']);
        // ISO 8601 s offsetem — klient počítá stáří přes Date.parse.
        $this->assertStringStartsWith('2026-08-06T10:00:00', $stats['collected_at']);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $stats['collected_at']);
    }

    public function testDsWithoutSnapshotHasNullStats(): void
    {
        // LEFT JOIN bez řádku → sloupce ze ds_stats jsou NULL.
        $db = $this->mockDb(linkedRows: [
            [
                'id'           => '7',
                'ds_id'        => 'abcd-efgh-ijkl-mnop',
                'name'         => 'Alfa s.r.o.',
                'url_app'      => 'https://alfa.example.com',
                'role'         => 'member',
                'alerts_count' => null,
                'mail_count'   => null,
                'collected_at' => null,
            ],
        ]);

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $this->assertNull($response->getPayload()['data']['items'][0]['stats']);
    }

    public function testNoStatsJoinWithoutDsStatsTable(): void
    {
        // Hosting před ds-upgrade — žádný dotaz nesmí na chybějící tabulku sáhnout.
        $capturedSqls = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$args) use (&$capturedSqls) {
                $capturedSqls[] = $sql;
                return [];
            },
        );

        $response = $this->controller->myDatasources(
            $this->userAuth(42),
            $db,
            $this->hostingTables(withStats: false),
        );

        $this->assertNotEmpty($capturedSqls);
        foreach ($capturedSqls as $sql) {
            $this->assertStringNotContainsString('hosting_core_ds_stats', $sql);
        }
        $this->assertSame([], $response->getPayload()['data']['items']);
    }

    public function testQueryScopesToUserActiveLifecycleAndLiveDocStates(): void
    {
        $captured = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$args) use (&$captured) {
                $captured[] = ['sql' => $sql, 'args' => $args];
                return [];
            },
        );

        $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());

        $joinQuery = null;
        $pendingQuery = null;
        foreach ($captured as $q) {
            if (str_contains($q['sql'], 'hosting_core_ds_users')) {
                $joinQuery = $q;
            } elseif (str_contains($q['sql'], '`owner` = %i')) {
                $pendingQuery = $q;
            }
        }

        $this->assertNotNull($joinQuery);
        $this->assertStringContainsString('LEFT JOIN `hosting_core_ds_stats`', $joinQuery['sql']);
        $this->assertStringContainsString('du.`user` = %i', $joinQuery['sql']);
        $this->assertStringContainsString('ds.`lifecycle` = %s', $joinQuery['sql']);
        $this->assertStringContainsString('du.`docState` IN %in', $joinQuery['sql']);
        $this->assertStringContainsString('ds.`docState` IN %in', $joinQuery['sql']);
        $this->assertSame(42, $joinQuery['args'][0]);
        $this->assertSame('active', $joinQuery['args'][1]);
        $this->assertSame([10, 40, 80], $joinQuery['args'][2]);
        $this->assertSame([10, 40, 80], $joinQuery['args'][3]);

        // Pending dotaz (hosting-08 D5) — scopovaný na ownera a živé docStates.
        $this->assertNotNull($pendingQuery);
        $this->assertStringContainsString('`lifecycle` IN %in', $pendingQuery['sql']);
        $this->assertSame(42, $pendingQuery['args'][0]);
        $this->assertSame(['request', 'creating', 'failed'], $pendingQuery['args'][1]);
        $this->assertSame([10, 40, 80], $pendingQuery['args'][2]);
    }

    // -------------------------------------------------------------------------
    // myDatasources — pending požadavky (hosting-08 D5)
    // -------------------------------------------------------------------------

    public function testPendingItemsComeFirstWithStateAndNoStats(): void
    {
        $db = $this->mockDb(
            linkedRows: [
                [
                    'id' => '7', 'ds_id' => 'abcd-efgh-ijkl-mnop', 'name' => 'Alfa s.r.o.',
                    'url_app' => 'https://alfa.example.com', 'role' => 'admin',
                    'alerts_count' => null, 'mail_count' => null, 'collected_at' => null,
                ],
            ],
            pendingRows: [
                ['id' => '12', 'ds_id' => 'qqqq-wwww-eeee-rrrr', 'name' => 'Nová', 'url_app' => 'https://nova.example.com', 'lifecycle' => 'request'],
                ['id' => '11', 'ds_id' => 'zzzz-xxxx-cccc-vvvv', 'name' => 'Selhaná', 'url_app' => 'https://selhana.example.com', 'lifecycle' => 'failed'],
            ],
        );

        $response = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables());
        $items = $response->getPayload()['data']['items'];

        $this->assertCount(3, $items);
        // Pending první (v pořadí dotazu — nejnovější nahoře), pak active.
        $this->assertSame(12, $items[0]['id']);
        $this->assertSame('creating', $items[0]['state']);
        $this->assertSame('owner', $items[0]['role']);
        $this->assertNull($items[0]['stats']);
        $this->assertSame('failed', $items[1]['state']);
        $this->assertSame('active', $items[2]['state']);
    }

    public function testPendingItemWinsOverDuplicateActiveRow(): void
    {
        // Pojistka: řádek v obou dotazech (nečekané) → jen pending karta.
        $db = $this->mockDb(
            linkedRows: [
                ['id' => '7', 'ds_id' => 'x', 'name' => 'X', 'url_app' => '', 'role' => 'member',
                    'alerts_count' => null, 'mail_count' => null, 'collected_at' => null],
            ],
            pendingRows: [
                ['id' => '7', 'ds_id' => 'x', 'name' => 'X', 'url_app' => '', 'lifecycle' => 'failed'],
            ],
        );

        $items = $this->controller->myDatasources($this->userAuth(42), $db, $this->hostingTables())
            ->getPayload()['data']['items'];

        $this->assertCount(1, $items);
        $this->assertSame('failed', $items[0]['state']);
    }

    // -------------------------------------------------------------------------
    // createMeta (hosting-08 D1/D2/D6)
    // -------------------------------------------------------------------------

    public function testCreateMetaWithoutDefaultServerReturnsNoServer(): void
    {
        $db = $this->mockDb(defaultServerId: null);

        $response = $this->controller->createMeta(
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs',
        );

        $data = $response->getPayload()['data'];
        $this->assertFalse($data['canCreate']);
        $this->assertSame('no_server', $data['reason']);
    }

    public function testCreateMetaWithOpenRequestReturnsOpenRequest(): void
    {
        $db = $this->mockDb(defaultServerId: 3, openRequests: 1);

        $data = $this->controller->createMeta(
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs',
        )->getPayload()['data'];

        $this->assertFalse($data['canCreate']);
        $this->assertSame('open_request', $data['reason']);
    }

    public function testCreateMetaAtMaxOwnedReturnsMaxOwned(): void
    {
        // Setting chybí → default 5; 5 aktivních vlastněných DS = strop.
        $db = $this->mockDb(defaultServerId: 3, activeOwned: 5);

        $data = $this->controller->createMeta(
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs',
        )->getPayload()['data'];

        $this->assertFalse($data['canCreate']);
        $this->assertSame('max_owned', $data['reason']);
    }

    public function testCreateMetaZeroMaxOwnedSettingMeansNoLimit(): void
    {
        $db = $this->mockDb(defaultServerId: 3, activeOwned: 50, settings: ['hosting.selfService.maxOwned' => 0]);

        $data = $this->controller->createMeta(
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs',
        )->getPayload()['data'];

        $this->assertTrue($data['canCreate']);
        $this->assertNull($data['reason']);
    }

    public function testCreateMetaListsOnlySelfServiceModulesAndDefaults(): void
    {
        $db = $this->mockDb(defaultServerId: 3);

        $data = $this->controller->createMeta(
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs',
        )->getPayload()['data'];

        $this->assertTrue($data['canCreate']);
        $this->assertSame(['install.base'], array_column($data['installModules'], 'id'));
        $this->assertSame(['language' => 'cs', 'country' => 'cz'], $data['defaults']);
        // Bez compiled configu degradace na minimální nabídku.
        $this->assertSame(['cs', 'en'], array_column($data['languages'], 'id'));
        $this->assertNotEmpty($data['countries']);
    }

    public function testCreateMetaGuards(): void
    {
        $db = $this->createMock(DataSourceConnection::class);

        $r404 = $this->controller->createMeta($this->userAuth(42), $db, [], $this->installRegistry(), null, 'cs');
        $this->assertSame(404, self::responseStatus($r404));

        $r401 = $this->controller->createMeta(AuthContext::anonymous(), $db, $this->hostingTables(), $this->installRegistry(), null, 'cs');
        $this->assertSame(401, self::responseStatus($r401));
    }

    // -------------------------------------------------------------------------
    // checkWebId (hosting-08 D3/D4)
    // -------------------------------------------------------------------------

    public function testCheckWebIdReasons(): void
    {
        $db = $this->mockDb(takenWebIds: ['obsazene']);
        $tables = $this->hostingTables();
        $auth = $this->userAuth(42);

        $cases = [
            ['ab', false, 'format'],
            ['nova_firma', false, 'format'],
            ['www', false, 'reserved'],
            ['obsazene', false, 'taken'],
            ['volne-jmeno', true, null],
            // Normalizace před kontrolou — velká písmena a mezery projdou.
            ['  Volne-Jmeno ', true, null],
        ];
        foreach ($cases as [$value, $available, $reason]) {
            $data = $this->controller->checkWebId(
                $this->makeRequest('GET', ['value' => $value]), $auth, $db, $tables,
            )->getPayload()['data'];
            $this->assertSame($available, $data['available'], "value: {$value}");
            $this->assertSame($reason, $data['reason'], "value: {$value}");
        }
    }

    // -------------------------------------------------------------------------
    // createDatasource (hosting-08 D4/D6)
    // -------------------------------------------------------------------------

    public function testCreateDatasourceHappyPath(): void
    {
        $db = $this->mockDb(defaultServerId: 3);
        $gateway = FakePortalGateway::create(DocumentResult::ok([
            'id' => 55, 'ds_id' => 'aaaa-bbbb-cccc-dddd', 'name' => 'Nová firma',
            'url_app' => 'https://nova.shpd.dev', 'lifecycle' => 'request',
        ]));
        $controller = $this->testableController($gateway);

        $response = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'Nová firma', 'web_id' => 'nova', 'language' => 'cs', 'country' => 'cz']),
            $this->userAuth(42),
            $db,
            $this->hostingTables(),
            $this->installRegistry(),
            null,
            null,
            new DocumentRegistry(),
        );

        $this->assertSame(200, self::responseStatus($response));

        // Server-side hodnoty — install modul doplněný z jediné nabídky,
        // lifecycle request, owner = session uživatel, server = default.
        $this->assertSame('request', $gateway->saved['lifecycle']);
        $this->assertSame(42, $gateway->saved['owner']);
        $this->assertSame(3, $gateway->saved['server']);
        $this->assertSame('install.base', $gateway->saved['install_module']);
        $this->assertSame('Nová firma', $gateway->saved['name']);

        // Response = pending karta pro vložení bez refetche.
        $item = $response->getPayload()['data']['item'];
        $this->assertSame(55, $item['id']);
        $this->assertSame('creating', $item['state']);
        $this->assertSame('owner', $item['role']);
        $this->assertNull($item['stats']);
    }

    public function testCreateDatasourceRejectsModuleOutsideOffer(): void
    {
        $db = $this->mockDb(defaultServerId: 3);
        $controller = $this->testableController(FakePortalGateway::create(DocumentResult::ok(['id' => 1])));

        $response = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'xxx', 'language' => 'cs', 'country' => 'cz', 'install_module' => 'install.hosting']),
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, null, new DocumentRegistry(),
        );

        $this->assertSame(400, self::responseStatus($response));
        $this->assertSame('INVALID_MODULE', $response->getPayload()['error']['code']);
    }

    public function testCreateDatasourceRejectsUnknownLanguageAndCountry(): void
    {
        $db = $this->mockDb(defaultServerId: 3);
        $controller = $this->testableController(FakePortalGateway::create(DocumentResult::ok(['id' => 1])));
        $tables = $this->hostingTables();
        $registry = $this->installRegistry();

        $badLang = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'xxx', 'language' => 'de', 'country' => 'cz']),
            $this->userAuth(42), $db, $tables, $registry, null, null, new DocumentRegistry(),
        );
        $this->assertSame(400, self::responseStatus($badLang));
        $this->assertSame('INVALID_LANGUAGE', $badLang->getPayload()['error']['code']);

        $badCountry = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'xxx', 'language' => 'cs', 'country' => 'xx']),
            $this->userAuth(42), $db, $tables, $registry, null, null, new DocumentRegistry(),
        );
        $this->assertSame(400, self::responseStatus($badCountry));
        $this->assertSame('INVALID_COUNTRY', $badCountry->getPayload()['error']['code']);
    }

    public function testCreateDatasourceWithOpenRequestReturns409(): void
    {
        $db = $this->mockDb(defaultServerId: 3, openRequests: 1);
        $controller = $this->testableController(FakePortalGateway::create(DocumentResult::ok(['id' => 1])));

        $response = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'xxx', 'language' => 'cs', 'country' => 'cz']),
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, null, new DocumentRegistry(),
        );

        $this->assertSame(409, self::responseStatus($response));
        $this->assertSame('OPEN_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testCreateDatasourceWithoutServerReturns409(): void
    {
        $db = $this->mockDb(defaultServerId: null);
        $controller = $this->testableController(FakePortalGateway::create(DocumentResult::ok(['id' => 1])));

        $response = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'xxx', 'language' => 'cs', 'country' => 'cz']),
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, null, new DocumentRegistry(),
        );

        $this->assertSame(409, self::responseStatus($response));
        $this->assertSame('NO_SERVER', $response->getPayload()['error']['code']);
    }

    public function testCreateDatasourceMapsValidationErrorsToFields(): void
    {
        $db = $this->mockDb(defaultServerId: 3);
        $validation = new ValidationResult();
        $validation->addError('web_id', 'Tento identifikátor je už obsazený.', 'DUPLICATE');
        $controller = $this->testableController(
            FakePortalGateway::create(DocumentResult::validationFailed($validation)),
        );

        $response = $controller->createDatasource(
            $this->makeRequest('POST', body: ['name' => 'X', 'web_id' => 'obsazene', 'language' => 'cs', 'country' => 'cz']),
            $this->userAuth(42), $db, $this->hostingTables(), $this->installRegistry(), null, null, new DocumentRegistry(),
        );

        $this->assertSame(422, self::responseStatus($response));
        $error = $response->getPayload()['error'];
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame('web_id', $error['details'][0]['field']);
        $this->assertSame('DUPLICATE', $error['details'][0]['code']);
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

    private function makeRequest(string $method, array $query = [], array $body = []): Request
    {
        return Request::fromArray($method, '/x', $query, $body !== [] ? (string) json_encode($body) : '', []);
    }

    /**
     * Mock DB s dispatchem podle SQL — myDatasources dělá dva fetchAll dotazy,
     * canCreate/checkWebId několik fetchSingle dotazů.
     *
     * @param array<string, mixed> $settings dekódované hodnoty core_system_settings
     */
    private function mockDb(
        array $linkedRows = [],
        array $pendingRows = [],
        ?int $defaultServerId = null,
        int $openRequests = 0,
        int $activeOwned = 0,
        array $settings = [],
        array $takenWebIds = [],
    ): DataSourceConnection {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$args) use ($linkedRows, $pendingRows): array {
                if (str_contains($sql, 'hosting_core_ds_users')) {
                    return $linkedRows;
                }
                if (str_contains($sql, '`owner` = %i')) {
                    return $pendingRows;
                }
                return [];
            },
        );
        $db->method('fetchSingle')->willReturnCallback(
            function (string $sql, ...$args) use ($defaultServerId, $openRequests, $activeOwned, $settings, $takenWebIds): mixed {
                if (str_contains($sql, 'core_system_settings')) {
                    $key = (string) $args[0];
                    return array_key_exists($key, $settings) ? json_encode($settings[$key]) : null;
                }
                if (str_contains($sql, 'hosting_core_servers')) {
                    return $defaultServerId;
                }
                if (str_contains($sql, 'hosting_core_ds_users')) {
                    return $activeOwned;
                }
                if (str_contains($sql, '`web_id` = %s')) {
                    return in_array((string) $args[0], $takenWebIds, true) ? 1 : null;
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return $openRequests;
                }
                return null;
            },
        );
        return $db;
    }

    /** Registry nad temp adresářem se dvěma install moduly (jen base je selfService). */
    private function installRegistry(): InstallModuleRegistry
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-portal-test-' . uniqid();
        foreach ([
            ['base', ['selfService' => true]],
            ['hosting', []],
        ] as [$name, $extra]) {
            $dir = $this->tmpDir . '/install/' . $name;
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/module.jsonc', (string) json_encode(array_merge([
                'id'          => 'install.' . $name,
                'name'        => 'Module ' . $name,
                'description' => 'Description for ' . $name,
            ], $extra)));
        }
        return new InstallModuleRegistry(new ModulePathResolver([$this->tmpDir]));
    }

    private function testableController(TableGateway $gateway): TestableHostingPortalController
    {
        $controller = new TestableHostingPortalController();
        $controller->gateway = $gateway;
        return $controller;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param bool $withStats false = hosting před ds-upgrade (bez ds_stats)
     * @return array<string, TableDefinition>
     */
    private function hostingTables(bool $withStats = true): array
    {
        $tables = [
            'hosting_core_data_sources' => $this->makeTable('hosting_core_data_sources'),
            'hosting_core_ds_users'     => $this->makeTable('hosting_core_ds_users'),
        ];
        if ($withStats) {
            $tables['hosting_core_ds_stats'] = $this->makeTable('hosting_core_ds_stats');
        }
        return $tables;
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

/**
 * Testovací subclass — podstrčí fake gateway (Dibi\Connection je final,
 * reálný TableGateway nelze v unit testu postavit).
 */
class TestableHostingPortalController extends HostingPortalController
{
    public ?TableGateway $gateway = null;

    protected function buildGateway(
        array $tables,
        DataSourceConnection $db,
        DocumentRegistry $documentRegistry,
        ?\Shipard\Core\Config\ConfigRuntime $config,
        ?\Shipard\Core\Config\DataSourceConfig $dsConfig,
    ): TableGateway {
        return $this->gateway ?? throw new \LogicException('gateway not set');
    }
}

/** Fake gateway — zachytí saveDocument payload a vrátí předpřipravený výsledek. */
class FakePortalGateway extends TableGateway
{
    public array $saved = [];
    private DocumentResult $result;

    public static function create(DocumentResult $result): self
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->result = $result;
        return $instance;
    }

    public function saveDocument(array $inputData): DocumentResult
    {
        $this->saved = $inputData;
        return $this->result;
    }
}
