<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Phase 3b unit tests for AnalysisController::applyExtracted — body
 * handling of client `_resolve` overrides and `autoCreateMode` derivation.
 * The expand/merge helpers are also tested directly via reflection.
 */
class AnalysisControllerResolveBodyTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_3b_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private function controller(
        DataSourceConnection $db,
        ?DocumentApplier $applier = null,
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
        );
    }

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function requestWithBody(array $body): Request
    {
        return Request::fromArray(
            'POST',
            '/x',
            [],
            (string) json_encode($body),
            ['HTTP_HOST' => 'test'],
        );
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /**
     * Spin up the boilerplate: a fetchRow-returning DB stub + an applier
     * mock that captures the canonical it receives. The captured canonical
     * is what we assert on for body-handling tests.
     *
     * @param array<string, mixed> $canonical
     * @return array{0: DocumentApplier, 1: \Closure}
     */
    private function captureApplier(array $canonical): array
    {
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(
            function (array $passed) use (&$captured) {
                $captured = $passed;
                return ApplyResult::ok($passed, savedId: 9999);
            },
        );
        return [$applier, function () use (&$captured): ?array { return $captured; }];
    }

    public function testEmptyBodyPreservesPhase2SafeMode(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        // Status update is invoked after a successful apply; for these
        // tests we only care about the body→canonical translation, so a
        // null applier-mock for updateExtractedStatus is irrelevant — the
        // controller's existing path returns 500 from the DB stub when it
        // tries to do the dibi update, but only AFTER we've captured the
        // canonical we want to assert on.

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->requestWithBody([]), 1,
        );
        // Status update will fail (no DibiConnection), but the body→canonical
        // contract is what we care about here.
        $c = $captured();
        $this->assertNotNull($c, 'Applier was invoked');
        $this->assertSame('safe', $c['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $c['applyOptions']['targetDocState']);
    }

    public function testEmptyResolveObjectSwitchesToStrictMode(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->requestWithBody(['_resolve' => []]), 1,
        );
        $c = $captured();
        $this->assertSame('strict', $c['applyOptions']['autoCreateMode']);
    }

    public function testSupplierUserActionPropagatedToCanonical(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['supplier' => 'useExisting:42']]),
            1,
        );
        $c = $captured();
        $this->assertSame('strict', $c['applyOptions']['autoCreateMode']);
        $this->assertSame('useExisting:42', $c['_resolve']['supplier']['userAction']);
    }

    public function testRowItemSkipExpandsToNestedShape(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['rows[0].item' => 'skip']]),
            1,
        );
        $c = $captured();
        $this->assertSame('skip', $c['_resolve']['rows'][0]['item']['userAction']);
    }

    public function testExplicitAutoCreateModeOverrideWins(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody([
                'applyOptions' => ['autoCreateMode' => 'liberal'],
            ]),
            1,
        );
        $c = $captured();
        $this->assertSame('liberal', $c['applyOptions']['autoCreateMode']);
    }

    public function testTargetDocStateOverrideRespected(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody(['applyOptions' => ['targetDocState' => 20]]),
            1,
        );
        $c = $captured();
        $this->assertSame(20, $c['applyOptions']['targetDocState']);
    }

    public function testInvalidResolveValueGracefullySkipped(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody([
                '_resolve' => [
                    'supplier' => 'useExisting:42',
                    'customer' => ['not', 'a', 'string'], // garbage → ignored
                ],
            ]),
            1,
        );
        $c = $captured();
        // supplier got through, customer was silently dropped
        $this->assertSame('useExisting:42', $c['_resolve']['supplier']['userAction']);
        $this->assertArrayNotHasKey('customer', $c['_resolve']);
    }

    public function testUnknownPathInResolveIsIgnored(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyExtracted(
            $this->authed(),
            $this->requestWithBody([
                '_resolve' => [
                    'mystery.field' => 'create',
                    'rows[99].item' => 'skip',  // valid shape, but row index doesn't exist
                ],
            ]),
            1,
        );
        $c = $captured();
        $this->assertArrayNotHasKey('mystery.field', $c['_resolve'] ?? []);
        // rows[99].item gets expanded structurally — applier ignores indices
        // that don't correspond to real rows in canonical.rows
        $this->assertSame('skip', $c['_resolve']['rows'][99]['item']['userAction']);
    }

    // ── Direct helper tests via reflection ──────────────────────────────────

    private function callPrivate(string $method, array $args): mixed
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);
        $ref = new \ReflectionClass($ctrl);
        return $ref->getMethod($method)->invoke($ctrl, ...$args);
    }

    public function testExpandUserActionsTopLevelAndRows(): void
    {
        $result = $this->callPrivate('expandUserActions', [[
            'supplier' => 'useExisting:42',
            'supplierBank' => 'create',
            'customer' => 'useExisting:1',
            'rows[0].item' => 'skip',
            'rows[2].item' => 'create',
        ]]);

        $this->assertSame(['userAction' => 'useExisting:42'], $result['supplier']);
        $this->assertSame(['userAction' => 'create'], $result['supplierBank']);
        $this->assertSame(['userAction' => 'useExisting:1'], $result['customer']);
        $this->assertSame(['userAction' => 'skip'], $result['rows'][0]['item']);
        $this->assertSame(['userAction' => 'create'], $result['rows'][2]['item']);
        $this->assertArrayNotHasKey(1, $result['rows']);
    }

    public function testExpandUserActionsSkipsInvalidShapes(): void
    {
        $result = $this->callPrivate('expandUserActions', [[
            'supplier' => 'useExisting:1',
            'bogus' => 'create',           // unknown top-level
            'rows[abc].item' => 'skip',    // non-numeric index
            'rows[0].bogus' => 'create',   // unknown field
            123 => 'create',                // non-string key (becomes int)
            'customer' => null,             // null value
            'supplierBank' => 12345,        // non-string value
        ]]);

        $this->assertSame(['userAction' => 'useExisting:1'], $result['supplier']);
        $this->assertArrayNotHasKey('bogus', $result);
        $this->assertArrayNotHasKey('rows', $result);
        $this->assertArrayNotHasKey('customer', $result);
        $this->assertArrayNotHasKey('supplierBank', $result);
    }

    public function testMergeUserActionsTopLevel(): void
    {
        $result = $this->callPrivate('mergeUserActions', [
            ['supplier' => ['status' => 'canCreate', 'createPayload' => ['x' => 1]]],
            ['supplier' => ['userAction' => 'create']],
        ]);

        $this->assertSame('canCreate', $result['supplier']['status']);
        $this->assertSame('create', $result['supplier']['userAction']);
        $this->assertSame(['x' => 1], $result['supplier']['createPayload']);
    }

    public function testMergeUserActionsRows(): void
    {
        $result = $this->callPrivate('mergeUserActions', [
            ['rows' => [
                ['item' => ['status' => 'canCreate']],
                ['item' => ['status' => 'matched', 'matchedId' => 18]],
            ]],
            ['rows' => [
                0 => ['item' => ['userAction' => 'create']],
                1 => ['item' => ['userAction' => 'useExisting:18']],
            ]],
        ]);

        $this->assertSame('canCreate', $result['rows'][0]['item']['status']);
        $this->assertSame('create', $result['rows'][0]['item']['userAction']);
        $this->assertSame('matched', $result['rows'][1]['item']['status']);
        $this->assertSame('useExisting:18', $result['rows'][1]['item']['userAction']);
        $this->assertSame(18, $result['rows'][1]['item']['matchedId']);
    }
}
