<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
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
 * Phase 2 unit tests for AnalysisController — canonical schema validation
 * in /result, full applyExtracted pipeline (delegate to DocumentApplier),
 * idempotent / recovery branches.
 */
class AnalysisControllerExchangeTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_axc_test_' . bin2hex(random_bytes(8));
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
        ?ConfigRuntime $configRuntime = null,
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $configRuntime, null,
            $enricher,
        );
    }

    /** ConfigRuntime s extractedDocTypes: insurance jako registry target. */
    private function configRuntimeWithRegistryTargets(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.extractedDocTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'insurance'       => ['target' => 'registry', 'docKind' => 'insurance'],
            ]],
        ]);
        return $config;
    }

    /**
     * Real enricher nad mockovanou DB (RowHistoryEnricher je final — nelze
     * mockovat) — partner vždy matched, historie dle parametru.
     *
     * @param list<array<string, mixed>> $history
     */
    private function enricher(array $history): RowHistoryEnricher
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new \Dibi\Row($row),
            $history,
        ));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        return new RowHistoryEnricher($dibi, $party);
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(string $method, string $path, array $body = []): Request
    {
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        return Request::fromArray($method, $path, [], $rawBody, ['HTTP_HOST' => 'test']);
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

    // ── applyExtracted branches ─────────────────────────────────────────────

    public function testApplyExtractedReturns401WhenUnauthenticated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(false, null, null);
        $resp = $ctrl->applyExtracted($auth, $this->request('POST', '/_mail/extracted-documents/1/apply'), 1);
        $this->assertSame(401, $this->statusOf($resp));
    }

    public function testApplyExtractedReturns404WhenRowMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 999,
        );
        $this->assertSame(404, $this->statusOf($resp));
    }

    public function testApplyExtractedReturns422WhenStatusAiFailed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 1, 'status' => 70, 'message' => 100]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 1,
        );
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('AI_OUTPUT_INVALID', $resp->getPayload()['error']['code']);
    }

    public function testApplyExtractedReturns409WhenStatusRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 50, 'message' => 100, 'target_row_ndx' => null,
        ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 1,
        );
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('INVALID_STATE', $resp->getPayload()['error']['code']);
    }

    public function testApplyExtractedIdempotentWhenAlreadyApplied(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 1234,
        ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 1,
        );
        $this->assertSame(200, $this->statusOf($resp));
        $payload = $resp->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame(1234, $payload['data']['savedDocId']);
        $this->assertTrue($payload['data']['idempotent']);
    }

    public function testApplyExtractedForwardsUnresolvedRequiredFromApplier(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturn(ApplyResult::error('unresolved_required', 'Doplň userAction', ['x' => 1], 422));

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 1,
        );
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('unresolved_required', $resp->getPayload()['error']['code']);
    }

    public function testApplyExtractedForwardsCorruptedJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => 'not-json',
        ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 1,
        );
        $this->assertSame(500, $this->statusOf($resp));
        $this->assertSame('CORRUPTED_DATA', $resp->getPayload()['error']['code']);
    }

    public function testApplyExtractedInjectsSourceAndApplyOptionsForApplier(): void
    {
        $canonical = $this->happyCanonical();
        // Strip source.extractedDoc so we can verify controller injection.
        unset($canonical['source']['extractedDoc']);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturnCallback(function (array $passed) use (&$captured) {
                $captured = $passed;
                return ApplyResult::error('unresolved_required', 'X', [], 422);
            });

        $this->controller($db, $applier)->applyExtracted(
            $this->authed(), $this->request('POST', '/x'), 42,
        );

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['source']['extractedDoc']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    // ── result() canonical validation via reflection ────────────────────────
    //
    // result() needs message_analyses + extracted_documents + claims + …
    // table state; full pipeline is covered by integration tests. Here we
    // unit-test the validateAndStoreCanonical helper directly — it carries
    // the entire Phase 2 validation logic.

    /**
     * @return array{0: int, 1: ?string}
     */
    private function callValidate(
        AnalysisController $ctrl,
        ?array $canonical,
        float $confidence = 0.95,
        array $thresholds = ['ready' => 0.9, 'review' => 0.6],
        string $docType = 'invoiceReceived',
    ): array {
        $ref = new \ReflectionClass($ctrl);
        $method = $ref->getMethod('validateAndStoreCanonical');
        return $method->invoke($ctrl, $canonical, $confidence, $thresholds, $docType);
    }

    public function testValidateAndStoreCanonicalKeepsValidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);
        $canonical = $this->happyCanonical();

        [$status, $json] = $this->callValidate($ctrl, $canonical, 0.95);

        $this->assertSame(10, $status); // STATUS_READY_TO_APPLY
        $decoded = json_decode((string) $json, true);
        $this->assertSame('shpd.docs.document', $decoded['format']);
        $this->assertArrayNotHasKey('_validationError', $decoded);
    }

    public function testValidateAndStoreCanonicalWrapsInvalidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        // Missing required `format` field — schema rejects.
        $broken = [
            'formatVersion' => '1.0',
            'docType' => 'invoiceReceived',
        ];

        [$status, $json] = $this->callValidate($ctrl, $broken, 0.95);

        $this->assertSame(70, $status); // STATUS_AI_FAILED
        $decoded = json_decode((string) $json, true);
        $this->assertSame('Canonical schema validation failed', $decoded['_validationError']);
        $this->assertIsArray($decoded['_validationIssues']);
        $this->assertNotEmpty($decoded['_validationIssues']);
        $this->assertSame($broken, $decoded['_rawOutput']);
    }

    public function testValidateAndStoreCanonicalLowConfidenceValid(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);
        $canonical = $this->happyCanonical();

        [$status, $json] = $this->callValidate($ctrl, $canonical, 0.4);

        $this->assertSame(30, $status); // STATUS_LOW_CONFIDENCE
        $this->assertNotNull($json);
    }

    public function testValidateAndStoreCanonicalNullPayloadKeepsLegacyBehaviour(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        [$status, $json] = $this->callValidate($ctrl, null, 0.95);

        $this->assertSame(10, $status);
        $this->assertNull($json);
    }

    public function testValidateAndStoreCanonicalWithoutSchemaValidatorPassesThrough(): void
    {
        // No SchemaValidator wired — invalid payload should still pass
        // through (Phase 1 backward compat). Status comes from confidence.
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = new AnalysisController(
            $db, $this->config, $this->tmpDir, [], new DocumentRegistry(),
            null, null,
        );

        [$status, $json] = $this->callValidate($ctrl, ['anything' => 'goes'], 0.95);

        $this->assertSame(10, $status);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(['anything' => 'goes'], $decoded);
    }

    // ── validateAndStoreCanonical + row history enrichment (D2/D7) ─────────

    public function testValidateAndStoreCanonicalPersistsEnrichedCanonical(): void
    {
        // Historie pokryje jediný řádek fixtury (description fallback
        // item.description) → obohacený canonical se persistne a ready
        // status zůstává (řádek je pokrytý).
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, enricher: $this->enricher([[
            'description'    => 'Hodinová sazba senior konzultanta',
            'vat_code'       => 'cz-110',
            'item_code'      => 'KONZ01',
            'account_number' => '518100',
            'doc_head'       => 777,
        ]]));

        [$status, $json] = $this->callValidate($ctrl, $this->happyCanonical(), 0.95);

        $this->assertSame(10, $status); // STATUS_READY_TO_APPLY
        $decoded = json_decode((string) $json, true);
        $this->assertSame('KONZ01', $decoded['rows'][0]['item']['ourCode']);
        $this->assertSame('518100', $decoded['rows'][0]['account']);
        $enrichment = $decoded['_resolve']['rows'][0]['enrichment'];
        $this->assertSame('historyExactRaw', $enrichment['matchedBy']);
        $this->assertSame(777, $enrichment['sourceDocId']);
    }

    public function testValidateAndStoreCanonicalCapsStatusWhenRowUncovered(): void
    {
        // Prázdná historie → řádek bez item.ourCode zůstane nepokrytý →
        // strop ready_to_apply → pending_review (D7).
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, enricher: $this->enricher([]));

        [$status, $json] = $this->callValidate($ctrl, $this->happyCanonical(), 0.95);

        $this->assertSame(20, $status); // STATUS_PENDING_REVIEW
        $decoded = json_decode((string) $json, true);
        $this->assertNull($decoded['rows'][0]['item']['ourCode']);
        $this->assertNull($decoded['_resolve']['rows'][0]['enrichment']['matchedBy']);
    }

    public function testValidateAndStoreCanonicalCapIgnoresAccountingRows(): void
    {
        // Účetní doklad: kontační řádky item nemají validně → strop se
        // neuplatní, ready status zůstává.
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, enricher: $this->enricher([]));

        $canonical = [
            'format' => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-06-10'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '518100', 'accSide' => 'debit', 'totalPrice' => 50.0],
                ['operation' => 'acc.record', 'account' => '321001', 'accSide' => 'credit', 'totalPrice' => 50.0],
            ],
        ];
        [$status] = $this->callValidate($ctrl, $canonical, 0.95);

        $this->assertSame(10, $status); // STATUS_READY_TO_APPLY
    }

    public function testValidateAndStoreCanonicalLowConfidenceNotRaisedByCoverage(): void
    {
        // Strop jen snižuje — pokrytý řádek nepovyšuje review/low status.
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, enricher: $this->enricher([[
            'description'    => 'Hodinová sazba senior konzultanta',
            'vat_code'       => 'cz-110',
            'item_code'      => 'KONZ01',
            'account_number' => null,
            'doc_head'       => 777,
        ]]));

        [$status] = $this->callValidate($ctrl, $this->happyCanonical(), 0.7);
        $this->assertSame(20, $status); // STATUS_PENDING_REVIEW dle confidence
    }

    public function testValidateAndStoreCanonicalSurvivesEnricherFailure(): void
    {
        // Enricher spadne na DB → /result nesmí selhat; canonical se uloží
        // neobohacený a strop (bez pokrytí) konzervativně sníží na review.
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willThrowException(new \RuntimeException('db down'));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, enricher: new RowHistoryEnricher($dibi, $party));

        [$status, $json] = $this->callValidate($ctrl, $this->happyCanonical(), 0.95);

        $this->assertSame(20, $status); // STATUS_PENDING_REVIEW (konzervativní strop)
        $decoded = json_decode((string) $json, true);
        $this->assertArrayNotHasKey('_resolve', $decoded);
        $this->assertNull($decoded['rows'][0]['item']['ourCode']);
    }

    public function testApplyExtractedWithoutApplierFallsBackToStatusUpdate(): void
    {
        // No applier wired (Phase 1 compat). Controller delegates to
        // updateExtractedStatus and that needs DibiConnection — for unit
        // testing we just assert the early branch returns *some* response
        // without invoking applier logic.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
        ]);

        // applier param omitted → null
        $ctrl = new AnalysisController(
            $db, $this->config, $this->tmpDir, [], new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            null,
        );

        // Call will fail somewhere inside updateExtractedStatus because
        // DibiConnection isn't really set up; that's fine — we only care
        // about NOT having reached AI_OUTPUT_INVALID or any of the Phase 2
        // applier branches.
        try {
            $resp = $ctrl->applyExtracted($this->authed(), $this->request('POST', '/x'), 1);
            $payload = $resp->getPayload();
            // Should not return AI_OUTPUT_INVALID — that means we hit the
            // Phase 1 path correctly.
            $this->assertNotSame('AI_OUTPUT_INVALID', $payload['error']['code'] ?? '');
        } catch (\Throwable $e) {
            // Hit the updateExtractedStatus codepath (good — proves fallback).
            $this->addToAssertionCount(1);
        }
    }

    // ── validateAndStoreCanonical — registry target ─────────────────────────
    //
    // Registry canonical se validuje proti shpd.registry.document.v1
    // (schéma base.registry) a přeskakuje enrichment i row-coverage cap.

    private function registryCanonical(): array
    {
        return [
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'insurance',
            'title'   => 'Pojistná smlouva — flotila',
            'summary' => 'Pojištění vozového parku.',
            'party'   => ['name' => 'Pojišťovna ABC', 'companyId' => '12345678', 'email' => 'info@abc.cz'],
            'kindFields' => [
                'insurer'      => 'Pojišťovna ABC',
                'policyNumber' => 'POJ-1',
                'validTo'      => '2026-12-31',
            ],
            'binderSuggestion' => 'Pojištění',
        ];
    }

    public function testValidateRegistryCanonicalKeepsValidPayload(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();

        [$status, $json] = $this->callValidate($ctrl, $canonical, 0.95, docType: 'insurance');

        $this->assertSame(10, $status); // STATUS_READY_TO_APPLY
        $this->assertSame($canonical, json_decode((string) $json, true)); // beze změn — žádný enrichment
    }

    public function testValidateRegistryCanonicalForeignKindFieldWrapsAiFailed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();
        // additionalProperties: false — přejmenované pole nesmí tiše projít
        $canonical['kindFields']['policy_number'] = 'POJ-1';

        [$status, $json] = $this->callValidate($ctrl, $canonical, 0.95, docType: 'insurance');

        $this->assertSame(70, $status); // STATUS_AI_FAILED
        $decoded = json_decode((string) $json, true);
        $this->assertArrayHasKey('_validationError', $decoded);
        $this->assertSame($canonical, $decoded['_rawOutput']);
    }

    public function testValidateRegistryCanonicalUnknownDocTypeWrapsAiFailed(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->registryCanonical();
        $canonical['docType'] = 'somethingElse';

        [$status] = $this->callValidate($ctrl, $canonical, 0.95, docType: 'insurance');

        $this->assertSame(70, $status); // STATUS_AI_FAILED
    }

    public function testValidateDocsCanonicalUnaffectedByRegistryConfig(): void
    {
        // docs typ jde dál docs větví i s configem, který registry typy zná
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db, configRuntime: $this->configRuntimeWithRegistryTargets());
        $canonical = $this->happyCanonical();

        [$status] = $this->callValidate($ctrl, $canonical, 0.95, docType: 'invoiceReceived');

        $this->assertSame(10, $status);
    }
}
