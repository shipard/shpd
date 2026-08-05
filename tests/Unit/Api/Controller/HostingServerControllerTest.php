<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\HostingServerController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Tests\Fixtures\Module\Hosting\InMemoryHostingServerDb;

class HostingServerControllerTest extends TestCase
{
    private const ISSUER = 'http://127.0.0.1/gggg-gggg-gggg-gggg/api/v1/_hosting/oidc';
    private const CLIENT_SECRET = 'plaintext-client-secret';

    private InMemoryHostingServerDb $db;
    private DsSecretCipher $cipher;
    private HostingServerController $controller;
    private int $serverId;
    private int $otherServerId;
    private int $ownerId;
    private string $token;

    protected function setUp(): void
    {
        $this->db = InMemoryHostingServerDb::create();
        $this->db->setSetting('hosting.oidc.issuer', self::ISSUER);
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));

        $random = str_repeat('a', 43);
        $this->token = 'shpd_hk_' . $random;
        $this->serverId = $this->db->addServer([
            'name' => 'Dev server',
            'fqdn' => 'dev.example.com',
            'api_key_prefix' => substr($random, 0, 12),
            'api_key_hash' => hash('sha256', $this->token),
            'can_provision' => 1,
        ]);
        $this->otherServerId = $this->db->addServer([
            'name' => 'Other server',
            'fqdn' => 'other.example.com',
        ]);
        $this->ownerId = $this->db->addUser([
            'login' => 'owner@example.com',
            'email' => 'owner@example.com',
            'full_name' => 'Owner User',
        ]);

        $this->controller = new HostingServerController(
            $this->createMock(DataSourceConfig::class),
            cipher: $this->cipher,
        );
    }

    // -------------------------------------------------------------------------
    // Gating + auth
    // -------------------------------------------------------------------------

    public function testMissingModuleTablesReturn404(): void
    {
        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testMissingAuthorizationHeaderIs401(): void
    {
        $resp = $this->controller->queue($this->req('GET', 'queue', token: false), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testWrongTokenIs401(): void
    {
        $resp = $this->controller->queue(
            $this->req('GET', 'queue', token: 'shpd_hk_' . str_repeat('b', 43)),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testNonHkTokenIs401(): void
    {
        $resp = $this->controller->queue(
            $this->req('GET', 'queue', token: 'shpd_ak_' . str_repeat('a', 32)),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testRevokedKeyIs401(): void
    {
        $this->db->servers[$this->serverId]['api_key_prefix'] = null;
        $this->db->servers[$this->serverId]['api_key_hash'] = null;

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testArchivedServerIs401(): void
    {
        $this->db->servers[$this->serverId]['docState'] = 90;

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testAuthenticatedCallUpdatesLastSeen(): void
    {
        $this->assertNull($this->db->servers[$this->serverId]['last_seen']);
        $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertNotNull($this->db->servers[$this->serverId]['last_seen']);
    }

    // -------------------------------------------------------------------------
    // reconcile
    // -------------------------------------------------------------------------

    public function testReconcileStoresVersionAndReturnsOk(): void
    {
        $resp = $this->controller->reconcile(
            $this->req('POST', 'reconcile', body: [
                'version' => '0.1.0',
                'dataSources' => [['ds_id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'A', 'modules' => ['install.base']]],
            ]),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('0.1.0', $this->db->servers[$this->serverId]['last_version']);
    }

    // -------------------------------------------------------------------------
    // queue
    // -------------------------------------------------------------------------

    public function testQueueServesOwnRequestsAndClaimsThem(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);
        // Požadavek jiného serveru se nesmí servírovat ani překlopit.
        $foreignId = $this->addRequest(['server' => $this->otherServerId, 'ds_id' => 'ffff-ffff-ffff-ffff']);
        // Aktivní DS do fronty nepatří.
        $this->db->addDataSource(['ds_id' => 'cccc-cccc-cccc-cccc', 'name' => 'Live', 'server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));

        $requests = $resp->getPayload()['data']['requests'];
        $this->assertCount(1, $requests);
        $item = $requests[0];
        $this->assertSame($reqId, $item['request_id']);
        $this->assertSame('bbbb-bbbb-bbbb-bbbb', $item['ds_id']);
        $this->assertSame('nova.shpd.dev', $item['host']);
        $this->assertSame('owner@example.com', $item['owner']['email']);
        $this->assertSame('Owner User', $item['owner']['name']);
        $this->assertSame((string) $this->ownerId, $item['owner']['sub']);
        $this->assertSame(self::ISSUER, $item['oidc']['issuer']);
        $this->assertSame('bbbb-bbbb-bbbb-bbbb', $item['oidc']['client_id']);
        $this->assertSame(self::CLIENT_SECRET, $item['oidc']['client_secret']);
        $this->assertSame('Shipard', $item['oidc']['label']);

        $this->assertSame('creating', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertNotNull($this->db->dataSources[$reqId]['claimed_at']);
        $this->assertSame('request', $this->db->dataSources[$foreignId]['lifecycle']);
    }

    public function testQueueLabelComesFromAppName(): void
    {
        $this->db->setSetting('app.name', 'Můj hosting');
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame('Můj hosting', $resp->getPayload()['data']['requests'][0]['oidc']['label']);
    }

    public function testQueueServesCreatingForRetry(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertCount(1, $resp->getPayload()['data']['requests']);
        $this->assertSame('creating', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testQueueEmptyWithoutCanProvision(): void
    {
        $this->db->servers[$this->serverId]['can_provision'] = 0;
        $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
    }

    public function testQueueEmptyWithoutIssuerSetting(): void
    {
        $this->db->settings = [];
        $reqId = $this->addRequest(['server' => $this->serverId]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        // Misconfigurace hostingu požadavek nezabíjí — zůstává request.
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testQueueRequestWithoutOwnerFailsTheRequest(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'owner' => null]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        $this->assertSame('failed', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertNotNull($this->db->dataSources[$reqId]['provision_error']);
    }

    public function testQueueRequestWithoutSecretFailsTheRequest(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'oidc_client_secret' => null]);

        $resp = $this->controller->queue($this->req('GET', 'queue'), $this->db, $this->tables());
        $this->assertSame([], $resp->getPayload()['data']['requests']);
        $this->assertSame('failed', $this->db->dataSources[$reqId]['lifecycle']);
    }

    // -------------------------------------------------------------------------
    // confirm
    // -------------------------------------------------------------------------

    public function testConfirmOkActivatesAndLinksOwner(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(200, $this->getStatus($resp));

        $row = $this->db->dataSources[$reqId];
        $this->assertSame('active', $row['lifecycle']);
        $this->assertNull($row['provision_error']);

        $this->assertCount(1, $this->db->dsUsers);
        $link = array_values($this->db->dsUsers)[0];
        $this->assertSame($this->ownerId, (int) $link['user']);
        $this->assertSame($reqId, (int) $link['data_source']);
        $this->assertSame('admin', $link['role']);
    }

    public function testConfirmOkIsIdempotent(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('active', $this->db->dataSources[$reqId]['lifecycle']);
        $this->assertCount(1, $this->db->dsUsers);
    }

    public function testConfirmFailedStoresError(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'failed', 'domain-add failed: exit 1');
        $this->assertSame(200, $this->getStatus($resp));

        $row = $this->db->dataSources[$reqId];
        $this->assertSame('failed', $row['lifecycle']);
        $this->assertSame('domain-add failed: exit 1', $row['provision_error']);
        $this->assertCount(0, $this->db->dsUsers);
    }

    public function testConfirmFailedAfterOkDoesNotDowngrade(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId, 'lifecycle' => 'creating']);

        $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'failed', 'stale error');

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('active', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmForeignRequestIs403(): void
    {
        $reqId = $this->addRequest(['server' => $this->otherServerId]);

        $resp = $this->confirm($reqId, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(403, $this->getStatus($resp));
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmUnknownRequestIs404(): void
    {
        $resp = $this->confirm(999, 'bbbb-bbbb-bbbb-bbbb', 'ok');
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testConfirmDsIdMismatchIs422(): void
    {
        $reqId = $this->addRequest(['server' => $this->serverId]);

        $resp = $this->confirm($reqId, 'zzzz-zzzz-zzzz-zzzz', 'ok');
        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('request', $this->db->dataSources[$reqId]['lifecycle']);
    }

    public function testConfirmInvalidBodyIs422(): void
    {
        $resp = $this->controller->confirm(
            $this->req('POST', 'confirm', body: ['request_id' => 1, 'ds_id' => 'x', 'status' => 'maybe']),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(422, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Řádek požadavku se všemi náležitostmi; overrides umí i nully. */
    private function addRequest(array $overrides = []): int
    {
        return $this->db->addDataSource(array_merge([
            'ds_id' => 'bbbb-bbbb-bbbb-bbbb',
            'name' => 'Nová firma',
            'web_id' => 'nova',
            'url_app' => 'https://nova.shpd.dev',
            'install_module' => 'install.base',
            'lifecycle' => 'request',
            'owner' => $this->ownerId,
            'oidc_client_secret' => $this->cipher->encrypt(self::CLIENT_SECRET),
            'oidc_redirect_uri' => 'https://nova.shpd.dev/api/v1/_auth/oidc/callback',
        ], $overrides));
    }

    private function confirm(int $requestId, string $dsId, string $status, ?string $error = null): Response
    {
        $body = ['request_id' => $requestId, 'ds_id' => $dsId, 'status' => $status];
        if ($error !== null) {
            $body['error'] = $error;
        }
        return $this->controller->confirm(
            $this->req('POST', 'confirm', body: $body),
            $this->db,
            $this->tables(),
        );
    }

    private function tables(): array
    {
        return [
            'hosting_core_servers' => $this->makeTable('hosting_core_servers'),
            'hosting_core_data_sources' => $this->makeTable('hosting_core_data_sources'),
            'hosting_core_ds_users' => $this->makeTable('hosting_core_ds_users'),
        ];
    }

    private function makeTable(string $name): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name' => $name,
            'adminOnly' => true,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
        ]);
    }

    /** @param array<string, mixed> $body */
    private function req(string $method, string $action, array $body = [], string|false|null $token = null): Request
    {
        $server = ['HTTP_HOST' => '127.0.0.1'];
        if ($token !== false) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . ($token ?? $this->token);
        }
        return Request::fromArray(
            $method,
            '/api/v1/_hosting/server/' . $action,
            [],
            $body === [] ? '' : (string) json_encode($body),
            $server,
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }
}
