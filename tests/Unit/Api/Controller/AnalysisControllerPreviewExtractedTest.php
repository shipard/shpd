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
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Phase 3a unit tests for AnalysisController::previewExtracted — read-only
 * variant of applyExtracted that returns enriched canonical for UI rendering.
 * Branches: pending/applied/rejected (preview), ai_failed (wrapper),
 * corrupted, 404, no-applier-wired fallback.
 */
class AnalysisControllerPreviewExtractedTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_aprev_' . bin2hex(random_bytes(8));
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
        ?RowHistoryEnricher $enricher = null,
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            null, null,
            $enricher,
        );
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(): Request
    {
        return Request::fromArray('POST', '/x', [], '', ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    public function testPreviewExtractedReturns401WhenUnauthenticated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $resp = $ctrl->previewExtracted(
            new AuthContext(false, null, null),
            $this->request(),
            1,
        );
        $this->assertSame(401, $this->statusOf($resp));
    }

    public function testPreviewExtractedReturns404WhenRowMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('preview');

        $resp = $this->controller($db, $applier)->previewExtracted($this->authed(), $this->request(), 999);
        $this->assertSame(404, $this->statusOf($resp));
    }

    public function testPreviewExtractedReturns500OnCorruptedJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100,
            'extracted_json' => 'not-valid-json',
            'source_attachments' => '[]',
        ]);

        $resp = $this->controller($db)->previewExtracted($this->authed(), $this->request(), 1);
        $this->assertSame(500, $this->statusOf($resp));
        $this->assertSame('CORRUPTED_DATA', $resp->getPayload()['error']['code']);
    }

    public function testPreviewExtractedReturnsWrapperForAiFailed(): void
    {
        $wrapper = [
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => [
                ['severity' => 'error', 'path' => 'format', 'code' => 'required', 'message' => 'Missing'],
            ],
            '_rawOutput' => ['garbage' => true],
        ];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 5, 'status' => 70, 'message' => 200,
            'extracted_json' => json_encode($wrapper),
            'source_attachments' => '[]',
        ]);
        $db->method('fetchAll')->willReturn([]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('preview');

        $resp = $this->controller($db, $applier)->previewExtracted($this->authed(), $this->request(), 5);
        $this->assertSame(200, $this->statusOf($resp));
        $payload = $resp->getPayload();
        $this->assertTrue($payload['data']['aiFailed']);
        $this->assertSame($wrapper, $payload['data']['wrapper']);
        $this->assertSame(70, $payload['data']['status']);
    }

    public function testPreviewExtractedDelegatesToApplierForPendingState(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 3, 'status' => 20, 'message' => 100,
            'extracted_json' => json_encode($canonical),
            'source_attachments' => '[]',
        ]);
        $db->method('fetchAll')->willReturn([]);

        $enriched = $canonical;
        $enriched['_resolve'] = ['summary' => ['status' => 'ok'], 'issues' => []];

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function (array $c) use (&$captured, $enriched) {
                $captured = $c;
                return ApplyResult::ok($enriched);
            });

        $resp = $this->controller($db, $applier)->previewExtracted($this->authed(), $this->request(), 3);
        $this->assertSame(200, $this->statusOf($resp));

        // Server-controlled injection happened
        $this->assertSame(3, $captured['source']['extractedDoc']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);

        $payload = $resp->getPayload();
        $this->assertFalse($payload['data']['aiFailed']);
        $this->assertSame('ok', $payload['data']['canonical']['_resolve']['summary']['status']);
    }

    public function testPreviewExtractedRunsFreshEnrichmentBeforePreview(): void
    {
        // Fresh enrichment (D2b) běží po injection source a před preview —
        // applier už dostane canonical s doplněným řádkem z historie.
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 3, 'status' => 20, 'message' => 100,
            'extracted_json' => json_encode($canonical),
            'source_attachments' => '[]',
        ]);
        $db->method('fetchAll')->willReturn([]);

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn([new \Dibi\Row([
            'description'    => 'Hodinová sazba senior konzultanta',
            'vat_code'       => 'cz-110',
            'item_code'      => 'KONZ01',
            'account_number' => '518100',
            'doc_head'       => 777,
        ])]);
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $enricher = new RowHistoryEnricher($dibi, $party);

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturnCallback(function (array $c) use (&$captured) {
                $captured = $c;
                return ApplyResult::ok($c);
            });

        $resp = $this->controller($db, $applier, $enricher)
            ->previewExtracted($this->authed(), $this->request(), 3);
        $this->assertSame(200, $this->statusOf($resp));

        $this->assertSame('KONZ01', $captured['rows'][0]['item']['ourCode']);
        $this->assertSame(
            'historyExactRaw',
            $captured['_resolve']['rows'][0]['enrichment']['matchedBy'],
        );
    }

    public function testPreviewExtractedAlsoWorksForRejectedHistory(): void
    {
        // Even for rejected (50) the preview should run — purely informative.
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 9, 'status' => 50, 'message' => 100,
            'extracted_json' => json_encode($canonical),
            'source_attachments' => '[]',
        ]);
        $db->method('fetchAll')->willReturn([]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('preview')->willReturn(ApplyResult::ok($canonical));

        $resp = $this->controller($db, $applier)->previewExtracted($this->authed(), $this->request(), 9);
        $this->assertSame(200, $this->statusOf($resp));
        $this->assertSame(50, $resp->getPayload()['data']['status']);
    }

    public function testPreviewExtractedFallsBackWithoutApplier(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100,
            'extracted_json' => json_encode($canonical),
            'source_attachments' => '[]',
        ]);
        $db->method('fetchAll')->willReturn([]);

        // applier param omitted → null
        $ctrl = $this->controller($db, null);
        $resp = $ctrl->previewExtracted($this->authed(), $this->request(), 1);

        $this->assertSame(200, $this->statusOf($resp));
        $payload = $resp->getPayload();
        $this->assertFalse($payload['data']['aiFailed']);
        // Raw canonical without _resolve when applier is missing
        $this->assertSame($canonical['docType'], $payload['data']['canonical']['docType']);
    }

    public function testPreviewExtractedReturnsAttachmentMetadata(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100,
            'extracted_json' => json_encode($canonical),
            'source_attachments' => '[42, 43]',
        ]);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->anything(),
                $this->equalTo('core_attachments_files'),
                $this->equalTo([42, 43]),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([
                ['id' => 42, 'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'file_size' => 12345],
                ['id' => 43, 'name' => 'scan.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 67890],
            ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('preview')->willReturn(ApplyResult::ok($canonical));

        $resp = $this->controller($db, $applier)->previewExtracted($this->authed(), $this->request(), 1);
        $atts = $resp->getPayload()['data']['attachments'];
        $this->assertCount(2, $atts);
        $this->assertSame('invoice.pdf', $atts[0]['filename']);
        $this->assertSame('application/pdf', $atts[0]['mime_type']);
        $this->assertSame(67890, $atts[1]['size_bytes']);
    }
}
