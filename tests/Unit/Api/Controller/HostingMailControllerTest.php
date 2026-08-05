<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\HostingMailController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Tests\Fixtures\Module\Hosting\InMemoryHostingMailDb;

class HostingMailControllerTest extends TestCase
{
    private const TOKEN = 'shpd_hk_' . 'abcdefghijkl' . 'mnopqrstuvwxyz0123456789ABCDEFG';

    private DsSecretCipher $cipher;
    private InMemoryHostingMailDb $db;
    private HostingMailController $ctrl;

    protected function setUp(): void
    {
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));
        $this->db = InMemoryHostingMailDb::create();
        $this->db->addRouter([
            'name' => 'Mail EU-1',
            'domains' => 'shipard.email,example.com',
            'api_key_prefix' => substr(substr(self::TOKEN, strlen('shpd_hk_')), 0, 12),
            'api_key_hash' => hash('sha256', self::TOKEN),
        ]);
        $this->ctrl = new HostingMailController(
            $this->createMock(DataSourceConfig::class),
            cipher: $this->cipher,
        );
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        $make = fn (string $name, int $tableId) => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name' => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);
        return [
            'hosting_core_mail_routers' => $make('hosting_core_mail_routers', 434),
            'hosting_core_data_sources' => $make('hosting_core_data_sources', 431),
        ];
    }

    private function req(string|false $token = self::TOKEN, ?string $ifNoneMatch = null): Request
    {
        $server = ['HTTP_HOST' => '127.0.0.1'];
        if ($token !== false) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($ifNoneMatch !== null) {
            $server['HTTP_IF_NONE_MATCH'] = $ifNoneMatch;
        }
        return Request::fromArray('GET', '/api/v1/_hosting/mail/lookup', [], '', $server);
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    /** @return array<string, array{api_url: string, api_token: string}> */
    private function dataSourcesOf(Response $resp): array
    {
        $payload = $resp->getPayload();
        return array_map(
            static fn ($e) => (array) $e,
            (array) $payload['data_sources'],
        );
    }

    // -------------------------------------------------------------------------
    // Gate + auth
    // -------------------------------------------------------------------------

    public function testMissingTablesReturns404(): void
    {
        $resp = $this->ctrl->lookup($this->req(), $this->db, []);
        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testMissingTokenReturns401(): void
    {
        $resp = $this->ctrl->lookup($this->req(token: false), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
        $this->assertSame('Router key required', $resp->getPayload()['error']['message']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $resp = $this->ctrl->lookup(
            $this->req(token: 'shpd_hk_' . str_repeat('X', 43)),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testRevokedKeyReturns401(): void
    {
        $this->db->routers[1]['api_key_prefix'] = null;
        $this->db->routers[1]['api_key_hash'] = null;

        $resp = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testArchivedRouterReturns401(): void
    {
        $this->db->routers[1]['docState'] = 90;

        $resp = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $this->assertSame(401, $this->getStatus($resp));
    }

    // -------------------------------------------------------------------------
    // Obsah
    // -------------------------------------------------------------------------

    public function testServesOnlyActiveDataSourcesWithToken(): void
    {
        $this->db->addDataSource([
            'ds_id' => 'a3f2-b8c1-d4e7-f9a0',
            'url_app' => 'https://one.shpd.dev',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('1', 32)),
        ]);
        $this->db->addDataSource([
            'ds_id' => 'x9y8-w7v6-u5t4-s3r2',
            'url_app' => 'https://two.shpd.dev',
            'mail_token' => null,
        ]);
        $this->db->addDataSource([
            'ds_id' => 'aaaa-bbbb-cccc-dddd',
            'url_app' => 'https://three.shpd.dev',
            'lifecycle' => 'request',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('2', 32)),
        ]);
        $this->db->addDataSource([
            'ds_id' => 'eeee-ffff-gggg-hhhh',
            'url_app' => 'https://four.shpd.dev',
            'docState' => 90,
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('3', 32)),
        ]);

        $resp = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));

        $payload = $resp->getPayload();
        $this->assertSame(['example.com', 'shipard.email'], $payload['hosts']);

        $dataSources = $this->dataSourcesOf($resp);
        $this->assertSame(['a3f2-b8c1-d4e7-f9a0'], array_keys($dataSources));
        $this->assertSame('https://one.shpd.dev', $dataSources['a3f2-b8c1-d4e7-f9a0']['api_url']);
        // Token je v odpovědi dešifrovaný.
        $this->assertSame('shpd_ak_' . str_repeat('1', 32), $dataSources['a3f2-b8c1-d4e7-f9a0']['api_token']);
    }

    public function testWebIdAliasIsIncludedWhenSet(): void
    {
        $this->db->addDataSource([
            'ds_id' => 'a3f2-b8c1-d4e7-f9a0',
            'web_id' => 'firma-jedna',
            'url_app' => 'https://firma-jedna.shpd.dev',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('1', 32)),
        ]);

        $dataSources = $this->dataSourcesOf(
            $this->ctrl->lookup($this->req(), $this->db, $this->tables()),
        );

        $this->assertSame(['a3f2-b8c1-d4e7-f9a0', 'firma-jedna'], array_keys($dataSources));
        $this->assertSame($dataSources['a3f2-b8c1-d4e7-f9a0'], $dataSources['firma-jedna']);
    }

    public function testUndecryptableTokenSkipsDataSourceOnly(): void
    {
        $this->db->addDataSource([
            'ds_id' => 'a3f2-b8c1-d4e7-f9a0',
            'url_app' => 'https://one.shpd.dev',
            'mail_token' => 'not-a-valid-ciphertext',
        ]);
        $this->db->addDataSource([
            'ds_id' => 'x9y8-w7v6-u5t4-s3r2',
            'url_app' => 'https://two.shpd.dev',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('2', 32)),
        ]);

        $resp = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame(['x9y8-w7v6-u5t4-s3r2'], array_keys($this->dataSourcesOf($resp)));
    }

    public function testEmptyDataSourcesSerializeAsObject(): void
    {
        $resp = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($resp));
        // Prázdná mapa musí být {} (object), ne [] — mail_router parsuje dict.
        $this->assertInstanceOf(\stdClass::class, $resp->getPayload()['data_sources']);
    }

    // -------------------------------------------------------------------------
    // ETag / 304
    // -------------------------------------------------------------------------

    public function testEtagIsStableAndConditionalGetReturns304(): void
    {
        $this->db->addDataSource([
            'ds_id' => 'a3f2-b8c1-d4e7-f9a0',
            'url_app' => 'https://one.shpd.dev',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('1', 32)),
        ]);

        $first = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $second = $this->ctrl->lookup($this->req(), $this->db, $this->tables());

        $etag = $first->getHeaders()['ETag'] ?? null;
        $this->assertNotNull($etag);
        $this->assertSame($etag, $second->getHeaders()['ETag'] ?? null);

        $conditional = $this->ctrl->lookup(
            $this->req(ifNoneMatch: $etag),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(304, $this->getStatus($conditional));
        $this->assertNull($conditional->getPayload());
        $this->assertSame($etag, $conditional->getHeaders()['ETag'] ?? null);
    }

    public function testEtagChangesWithContent(): void
    {
        $first = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $etagEmpty = $first->getHeaders()['ETag'];

        $this->db->addDataSource([
            'ds_id' => 'a3f2-b8c1-d4e7-f9a0',
            'url_app' => 'https://one.shpd.dev',
            'mail_token' => $this->cipher->encrypt('shpd_ak_' . str_repeat('1', 32)),
        ]);

        $second = $this->ctrl->lookup($this->req(ifNoneMatch: $etagEmpty), $this->db, $this->tables());
        $this->assertSame(200, $this->getStatus($second));
        $this->assertNotSame($etagEmpty, $second->getHeaders()['ETag']);
    }

    public function testWeakIfNoneMatchMatches(): void
    {
        $first = $this->ctrl->lookup($this->req(), $this->db, $this->tables());
        $etag = $first->getHeaders()['ETag'];

        $conditional = $this->ctrl->lookup(
            $this->req(ifNoneMatch: 'W/' . $etag),
            $this->db,
            $this->tables(),
        );
        $this->assertSame(304, $this->getStatus($conditional));
    }
}
