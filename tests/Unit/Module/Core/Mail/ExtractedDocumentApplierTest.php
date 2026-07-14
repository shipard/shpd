<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Mail\ExtractedDocTypes;
use Shipard\Module\Core\Mail\ExtractedDocumentApplier;
use Shipard\Module\Core\Mail\ExtractedTargetApplier;
use Shipard\Module\Core\Mail\TargetApplyResult;
use Shipard\Module\Core\Mail\TargetUnapplyResult;

/**
 * Unit tests for the shared apply core extracted out of
 * AnalysisController::applyExtracted. Covers the apply() branches plus the
 * expand/merge userAction helpers (moved here with the core). HTTP-level
 * regression stays in AnalysisControllerExchangeTest / *ResolveBodyTest.
 */
class ExtractedDocumentApplierTest extends TestCase
{
    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    private function service(DataSourceConnection $db, DocumentApplier $applier): ExtractedDocumentApplier
    {
        return new ExtractedDocumentApplier($db, $applier);
    }

    private function unapplyService(DataSourceConnection $db, TableGateway $gw): ExtractedDocumentApplier
    {
        return new ExtractedDocumentApplier($db, null, null, null, [], $gw);
    }

    /** ConfigRuntime mock s extractedDocTypes cfg: contract→registry, invoiceReceived→docs. */
    private function configWithTargets(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.extractedDocTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'contract'        => ['target' => 'registry', 'docKind' => 'contract'],
                'legacy'          => [],
            ]],
        ]);
        return $config;
    }

    // ── apply() branches ────────────────────────────────────────────────────

    public function testApplyHappyReturnsOkOutcome(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('apply')
            ->willReturn(ApplyResult::ok($canonical, savedId: 9999));

        // writeStatusTransition will fail on the mock DibiConnection, but apply
        // logs and still reports success (doc is saved; recovery path covers it).
        $outcome = $this->service($db, $applier)->apply(1, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);

        $this->assertTrue($outcome->ok);
        $this->assertSame(9999, $outcome->savedDocId);
        $this->assertSame(100, $outcome->messageNdx);
        $this->assertFalse($outcome->idempotent);
    }

    public function testApplyNotFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(999, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testApplyAiFailedReturnsAiOutputInvalid(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 1, 'status' => 70, 'message' => 100]);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(1, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('AI_OUTPUT_INVALID', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
    }

    public function testApplyIdempotentWhenAlreadyApplied(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 1234,
        ]);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(1, 7, null);
        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->idempotent);
        $this->assertSame(1234, $outcome->savedDocId);
    }

    public function testApplyInvalidStateWhenRejected(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 50, 'message' => 100, 'target_row_ndx' => null,
        ]);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(1, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testApplyCorruptedJson(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => 'not-json',
        ]);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $outcome = $this->service($db, $applier)->apply(1, 7, null);
        $this->assertFalse($outcome->ok);
        $this->assertSame('CORRUPTED_DATA', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testApplyForwardsUnresolvedRequired(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())->method('apply')->willReturn(
            ApplyResult::error('unresolved_required', 'Doplň userAction', ['_resolve' => ['issues' => [['x' => 1]]]], 422),
        );

        $outcome = $this->service($db, $applier)->apply(1, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);
        $this->assertFalse($outcome->ok);
        $this->assertSame('unresolved_required', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
        $this->assertNotNull($outcome->canonical);
    }

    // ── safety: safe mode + targetDocState=10 ───────────────────────────────

    public function testApplyPassesSafeModeAndDraftStateToApplier(): void
    {
        $canonical = $this->happyCanonical();
        unset($canonical['source']['extractedDoc']);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $this->service($db, $applier)->apply(42, 7, null, ['autoCreateMode' => 'safe', 'targetDocState' => 10]);

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['source']['extractedDoc']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    public function testApplyDefaultsToSafeWithoutClientResolve(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        // No override, no client _resolve → safe, targetDocState default 10.
        $this->service($db, $applier)->apply(1, 7, null);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    public function testApplySwitchesToStrictWithClientResolve(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        // Non-null client _resolve (even empty) → strict.
        $this->service($db, $applier)->apply(1, 7, ['supplier' => 'useExisting:42']);
        $this->assertSame('strict', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame('useExisting:42', $captured['_resolve']['supplier']['userAction']);
    }

    // ── row history enrichment (D2c/D3) ─────────────────────────────────────

    public function testApplyRunsEnrichmentBeforeUserActionMerge(): void
    {
        // Enrichment doplní řádek z historie; klientův pin se merguje až po
        // něm a v _resolve zůstává vedle enrichment auditu — reconcile fáze
        // DocumentApplieru mu pak dá přednost.
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

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
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::error('unresolved_required', 'X', [], 422);
        });

        $service = new ExtractedDocumentApplier($db, $applier, $enricher);
        $service->apply(1, 7, ['rows[0].item' => 'useExisting:55']);

        $this->assertNotNull($captured);
        $this->assertSame('KONZ01', $captured['rows'][0]['item']['ourCode']);
        $this->assertSame('518100', $captured['rows'][0]['account']);
        $rowResolve = $captured['_resolve']['rows'][0];
        $this->assertSame('useExisting:55', $rowResolve['item']['userAction']);
        $this->assertSame('historyExactRaw', $rowResolve['enrichment']['matchedBy']);
    }

    public function testApplyContinuesWhenEnrichmentFails(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'extracted_json' => json_encode($canonical),
        ]);

        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willThrowException(new \RuntimeException('db down'));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $enricher = new RowHistoryEnricher($dibi, $party);

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(function (array $passed) use (&$captured) {
            $captured = $passed;
            return ApplyResult::ok($passed, savedId: 9999);
        });

        $outcome = (new ExtractedDocumentApplier($db, $applier, $enricher))->apply(1, 7, null);

        $this->assertTrue($outcome->ok);
        $this->assertNull($captured['rows'][0]['item']['ourCode']); // neobohaceno
    }

    // ── target seam ─────────────────────────────────────────────────────────

    public function testTargetForFallsBackToDocs(): void
    {
        $config = $this->configWithTargets();
        $this->assertSame('docs', ExtractedDocTypes::targetFor(null, 'contract'));
        $this->assertSame('docs', ExtractedDocTypes::targetFor($config, 'unknownType'));
        $this->assertSame('docs', ExtractedDocTypes::targetFor($config, 'legacy'));
        $this->assertSame('docs', ExtractedDocTypes::targetFor($config, 'invoiceReceived'));
        $this->assertSame('registry', ExtractedDocTypes::targetFor($config, 'contract'));
        $this->assertSame('contract', ExtractedDocTypes::docKindFor($config, 'contract'));
        $this->assertNull(ExtractedDocTypes::docKindFor($config, 'invoiceReceived'));
    }

    public function testApplyRoutesRegistryTargetToTargetApplier(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'Smlouva'];
        $row = [
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'doc_type' => 'contract', 'extracted_json' => json_encode($canonical),
        ];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($row);

        $docsApplier = $this->createMock(DocumentApplier::class);
        $docsApplier->expects($this->never())->method('apply');

        $targetApplier = $this->createMock(ExtractedTargetApplier::class);
        $targetApplier->expects($this->once())->method('apply')
            ->with($canonical, $row, 7)
            ->willReturn(TargetApplyResult::ok(4242));

        // Enrichment/_resolve/applyOptions se u registry targetu přeskakují —
        // ověřeno passthrough canonicalem (beze změn) v assertu níže.
        $service = new ExtractedDocumentApplier(
            $db, $docsApplier, null, $this->configWithTargets(), ['registry' => $targetApplier],
        );
        // writeStatusTransition selže na mock dibi — apply loguje a hlásí úspěch
        // (stejné chování jako docs cesta, recovery doběhne status později).
        $outcome = $service->apply(1, 7, null);

        $this->assertTrue($outcome->ok);
        $this->assertSame(4242, $outcome->savedDocId);
        $this->assertSame($canonical, $outcome->canonical);
    }

    public function testApplyRegistryTargetErrorPassesThrough(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'X'];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'doc_type' => 'contract', 'extracted_json' => json_encode($canonical),
        ]);
        $targetApplier = $this->createMock(ExtractedTargetApplier::class);
        $targetApplier->method('apply')->willReturn(
            TargetApplyResult::error('VALIDATION_ERROR', 'title missing', 422),
        );

        $service = new ExtractedDocumentApplier(
            $db, null, null, $this->configWithTargets(), ['registry' => $targetApplier],
        );
        $outcome = $service->apply(1, 7, null);

        $this->assertFalse($outcome->ok);
        $this->assertSame('VALIDATION_ERROR', $outcome->errorCode);
        $this->assertSame(422, $outcome->statusCode);
    }

    public function testApplyRegistryTargetWithoutWiredApplierFails(): void
    {
        $canonical = ['schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => 'X'];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
            'doc_type' => 'contract', 'extracted_json' => json_encode($canonical),
        ]);

        $service = new ExtractedDocumentApplier($db, null, null, $this->configWithTargets());
        $outcome = $service->apply(1, 7, null);

        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    public function testUnapplyRoutesRegistryTargetToTargetApplier(): void
    {
        $row = [
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 555,
            'doc_type' => 'contract',
        ];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($row);

        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $targetApplier = $this->createMock(ExtractedTargetApplier::class);
        $targetApplier->expects($this->once())->method('unapply')
            ->with($row)
            ->willReturn(TargetUnapplyResult::error('DOC_ADVANCED', 'Document changed since apply', 409));

        $service = new ExtractedDocumentApplier(
            $db, null, null, $this->configWithTargets(), ['registry' => $targetApplier], $gw,
        );
        $outcome = $service->unapply(1, 7);

        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyDocsWithoutGatewayFails(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 555,
            'doc_type' => 'invoiceReceived',
        ]);

        $service = new ExtractedDocumentApplier($db, null, null, $this->configWithTargets());
        $outcome = $service->unapply(1, 7);

        $this->assertFalse($outcome->ok);
        $this->assertSame('INTERNAL_ERROR', $outcome->errorCode);
        $this->assertSame(500, $outcome->statusCode);
    }

    // ── unapply() guard branches ────────────────────────────────────────────
    //
    // Happy round-trip (trash + extracted→20 + zpráva→20) potřebuje reálné dibi
    // (writeUnapplyTransition) → pokryto v Integration/AiExtractedDocumentApplyTest.
    // Tady čistě guard cesty, které se vrací před writeUnapplyTransition.

    public function testUnapplyNotFound(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(999, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('NOT_FOUND', $outcome->errorCode);
        $this->assertSame(404, $outcome->statusCode);
    }

    public function testUnapplyRejectsNonApplied(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 20, 'message' => 100, 'target_row_ndx' => null,
        ]);
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(1, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyRejectsAppliedWithoutTarget(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 0,
        ]);
        $gw = $this->createMock(TableGateway::class);
        $gw->expects($this->never())->method('loadDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(1, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('INVALID_STATE', $outcome->errorCode);
    }

    public function testUnapplyRejectsWhenTargetDocMissing(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 555,
        ]);
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(null);
        $gw->expects($this->never())->method('saveDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(1, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
        $this->assertSame(409, $outcome->statusCode);
    }

    public function testUnapplyRejectsWhenTargetDocAdvancedBeyondDraft(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 555,
        ]);
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(['id' => 555, 'docState' => 20]); // Confirmed
        $gw->expects($this->never())->method('saveDocument');

        $outcome = $this->unapplyService($db, $gw)->unapply(1, 7);
        $this->assertFalse($outcome->ok);
        $this->assertSame('DOC_ADVANCED', $outcome->errorCode);
    }

    public function testUnapplyTrashesTargetWithDocState90(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 1, 'status' => 40, 'message' => 100, 'target_row_ndx' => 555,
        ]);

        $captured = null;
        $gw = $this->createMock(TableGateway::class);
        $gw->method('loadDocument')->willReturn(['id' => 555, 'docState' => 10]);
        $gw->method('saveDocument')->willReturnCallback(function (array $doc) use (&$captured): DocumentResult {
            $captured = $doc;
            return DocumentResult::ok($doc);
        });

        // writeUnapplyTransition pak selže na mock dibi (getDibiConnection),
        // takže výsledek je INTERNAL_ERROR — ale trash krok proběhl a to ověřujeme.
        $this->unapplyService($db, $gw)->unapply(1, 7);

        $this->assertNotNull($captured);
        $this->assertSame(90, $captured['docState']);
        $this->assertSame(5, $captured['docStateMain']);
    }

    public function testWriteUnapplyTransitionRejectsNonApplied(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 1, 'status' => 20, 'message' => 100]);

        $result = ExtractedDocumentApplier::writeUnapplyTransition($db, 1);
        $this->assertFalse($result->ok);
        $this->assertSame('INVALID_STATE', $result->errorCode);
        $this->assertSame(409, $result->statusCode);
    }

    // ── expand / merge helpers (moved from AnalysisController) ───────────────

    public function testExpandUserActionsTopLevelAndRows(): void
    {
        $result = ExtractedDocumentApplier::expandUserActions([
            'supplier' => 'useExisting:42',
            'supplierBank' => 'create',
            'customer' => 'useExisting:1',
            'rows[0].item' => 'skip',
            'rows[2].item' => 'create',
        ]);

        $this->assertSame(['userAction' => 'useExisting:42'], $result['supplier']);
        $this->assertSame(['userAction' => 'create'], $result['supplierBank']);
        $this->assertSame(['userAction' => 'useExisting:1'], $result['customer']);
        $this->assertSame(['userAction' => 'skip'], $result['rows'][0]['item']);
        $this->assertSame(['userAction' => 'create'], $result['rows'][2]['item']);
        $this->assertArrayNotHasKey(1, $result['rows']);
    }

    public function testExpandUserActionsSkipsInvalidShapes(): void
    {
        $result = ExtractedDocumentApplier::expandUserActions([
            'supplier' => 'useExisting:1',
            'bogus' => 'create',
            'rows[abc].item' => 'skip',
            'rows[0].bogus' => 'create',
            123 => 'create',
            'customer' => null,
            'supplierBank' => 12345,
        ]);

        $this->assertSame(['userAction' => 'useExisting:1'], $result['supplier']);
        $this->assertArrayNotHasKey('bogus', $result);
        $this->assertArrayNotHasKey('rows', $result);
        $this->assertArrayNotHasKey('customer', $result);
        $this->assertArrayNotHasKey('supplierBank', $result);
    }

    public function testMergeUserActionsTopLevel(): void
    {
        $result = ExtractedDocumentApplier::mergeUserActions(
            ['supplier' => ['status' => 'canCreate', 'createPayload' => ['x' => 1]]],
            ['supplier' => ['userAction' => 'create']],
        );

        $this->assertSame('canCreate', $result['supplier']['status']);
        $this->assertSame('create', $result['supplier']['userAction']);
        $this->assertSame(['x' => 1], $result['supplier']['createPayload']);
    }

    public function testMergeUserActionsRows(): void
    {
        $result = ExtractedDocumentApplier::mergeUserActions(
            ['rows' => [
                ['item' => ['status' => 'canCreate']],
                ['item' => ['status' => 'matched', 'matchedId' => 18]],
            ]],
            ['rows' => [
                0 => ['item' => ['userAction' => 'create']],
                1 => ['item' => ['userAction' => 'useExisting:18']],
            ]],
        );

        $this->assertSame('canCreate', $result['rows'][0]['item']['status']);
        $this->assertSame('create', $result['rows'][0]['item']['userAction']);
        $this->assertSame('matched', $result['rows'][1]['item']['status']);
        $this->assertSame('useExisting:18', $result['rows'][1]['item']['userAction']);
        $this->assertSame(18, $result['rows'][1]['item']['matchedId']);
    }
}
