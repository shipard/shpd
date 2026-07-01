<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí Fáze 2 — AI extracted document → applyExtracted →
 * canonical → DocumentApplier → docs_core_heads + partner + supplier_codes
 * mapping + bidirectional lineage. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class AiExtractedDocumentApplyTest extends IntegrationTestCase
{
    private const FIXTURE_PREFIX = 'IT-AIX';

    private AnalysisController $controller;
    private ConfigRuntime $configRuntime;
    private int $analysisRowId = 0;
    private int $messageRowId = 0;
    private int $extractedRowId = 0;

    /** @var list<int> */
    private array $createdDocIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];
    /** @var list<int> */
    private array $createdItemIds = [];

    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $invniSeries = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (%i,%i,%i) LIMIT 1',
            'invni', 10, 40, 80,
        );
        if ($invniSeries === null) {
            $this->markTestSkipped('DS missing invni number_series — run ds-upgrade.');
        }

        $applier = DocumentApplier::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );

        $this->controller = new AnalysisController(
            $this->db, $this->dsConfig, $this->realDsPath, $this->tables, $documentRegistry,
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $this->configRuntime,
        );

        $this->ensureOwnCompany();
    }

    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id' => 'F-OWN-AIX',
            'person_type' => 2,
            'full_name' => self::FIXTURE_PREFIX . ' Own',
            'last_name' => self::FIXTURE_PREFIX . ' Own',
            'first_name' => '',
            'company_id' => '00000099',
            'is_own' => 1,
            'docState' => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }

    /**
     * Provision the mailbox-message-analysis chain that an extracted_document
     * row hangs off. Returns the extracted_document id ready to be applied.
     *
     * @param array<string, mixed> $canonical  Canonical payload to be stored
     *                                          in extracted_documents.extracted_json.
     */
    private function provisionExtractedDocument(array $canonical): int
    {
        $dibi = $this->db->getDibiConnection();
        $now = date('Y-m-d H:i:s');

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing core_mail_mailboxes — run mail-router-bootstrap.');
        }
        $mailboxId = (int) $mailbox['id'];

        $dibi->insert('core_mail_incoming_messages', [
            'message_id' => self::FIXTURE_PREFIX . '-MSG-' . uniqid(),
            'mailbox' => $mailboxId,
            'primary_type' => 'invoice',
            'subject' => self::FIXTURE_PREFIX . ' Test invoice',
            'sender_email' => 'vendor@example.cz',
            'received_at' => $now,
            'source_type' => 2,
            'ai_analysis_enabled' => 1,
            'needs_reanalysis' => 0,
            'docState' => 30, // Analyzed
            'docStateMain' => 3,
            'created' => $now,
            'modified' => $now,
        ])->execute();
        $this->messageRowId = (int) $dibi->getInsertId();

        $dibi->insert('core_mail_message_analyses', [
            'message' => $this->messageRowId,
            'analyzed_at' => $now,
            'status' => 2,
            'model_name' => 'fixture',
            'prompt_version' => 'v2.0.0',
            'extracted_document_count' => 1,
            'created' => $now,
        ])->execute();
        $this->analysisRowId = (int) $dibi->getInsertId();

        $dibi->insert('core_mail_extracted_documents', [
            'message' => $this->messageRowId,
            'analysis' => $this->analysisRowId,
            'doc_type' => 'invoiceReceived',
            'source_attachments' => '[]',
            'extracted_json' => json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'confidence' => 0.95,
            'status' => ExtractedDocumentDocument::STATUS_PENDING_REVIEW,
            'created' => $now,
        ])->execute();
        $this->extractedRowId = (int) $dibi->getInsertId();

        return $this->extractedRowId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanonical(string $uniqueSuffix): array
    {
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['supplier']['name'] = self::FIXTURE_PREFIX . ' Vendor ' . $uniqueSuffix;
        $payload['supplier']['companyId'] = '88' . substr((string) (microtime(true) * 10000), -6);
        $payload['supplier']['vatId'] = 'CZ' . $payload['supplier']['companyId'];
        $payload['supplier']['taxId'] = 'CZ' . $payload['supplier']['companyId'];
        $payload['docNumber'] = self::FIXTURE_PREFIX . '-' . $uniqueSuffix;
        return $payload;
    }

    protected function tearDown(): void
    {
        $dibi = $this->db->getDibiConnection();

        if ($this->extractedRowId > 0) {
            $dibi->query('DELETE FROM core_mail_extracted_documents WHERE id = %i', $this->extractedRowId);
        }
        if ($this->analysisRowId > 0) {
            $dibi->query('DELETE FROM core_mail_message_analyses WHERE id = %i', $this->analysisRowId);
        }
        if ($this->messageRowId > 0) {
            $dibi->query('DELETE FROM core_mail_incoming_messages WHERE id = %i', $this->messageRowId);
        }
        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        foreach ($this->createdItemIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $id);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }

        parent::tearDown();
    }

    private function authed(int $userId = 1): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(): Request
    {
        return Request::fromArray('POST', '/api/v1/_mail/extracted-documents/x/apply', [], '', ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testApplyExtractedCreatesDocWithBidirectionalLineage(): void
    {
        $suffix = (string) uniqid();
        $extractedNdx = $this->provisionExtractedDocument($this->buildCanonical($suffix));

        $resp = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(
            200,
            $this->statusOf($resp),
            'Expected success: ' . json_encode($resp->getPayload()),
        );

        $payload = $resp->getPayload();
        $this->assertTrue($payload['success']);
        $savedDocId = (int) $payload['data']['savedDocId'];
        $this->createdDocIds[] = $savedDocId;

        // 1. docs_core_heads row exists with lineage stamped
        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $savedDocId);
        $this->assertNotNull($head);
        $this->assertSame('invni', (string) $head['doc_type']);
        $this->assertSame('aiExtraction', (string) $head['source_kind']);
        $this->assertSame($extractedNdx, (int) $head['source_extracted_doc']);
        $this->assertNotNull($head['source_extracted_at']);

        // 2. Partner was autocreated (companyId present → safe mode allowed)
        $partnerId = (int) $head['partner'];
        $this->createdPersonIds[] = $partnerId;
        $partner = $this->db->fetchRow('SELECT * FROM base_persons_persons WHERE id = %i', $partnerId);
        $this->assertNotNull($partner);
        $this->assertStringStartsWith(self::FIXTURE_PREFIX, (string) $partner['full_name']);

        // 3. Row + item created
        $rows = $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i', $savedDocId);
        $this->assertCount(1, $rows);
        $newItemId = (int) $rows[0]['item'];
        $this->createdItemIds[] = $newItemId;

        // 4. Per-partner supplier-code mapping written
        $mapping = $this->db->fetchRow(
            'SELECT * FROM economy_items_supplier_codes WHERE person = %i AND supplier_code = %s',
            $partnerId, 'KONZ-001',
        );
        $this->assertNotNull($mapping);

        // 5. Bidirectional lineage on extracted_document
        $extracted = $this->db->fetchRow(
            'SELECT * FROM core_mail_extracted_documents WHERE id = %i',
            $extractedNdx,
        );
        $this->assertSame('docs_core_heads', (string) $extracted['target_table_id']);
        $this->assertSame($savedDocId, (int) $extracted['target_row_ndx']);
        $this->assertSame(ExtractedDocumentDocument::STATUS_APPLIED, (int) $extracted['status']);
        $this->assertNotNull($extracted['applied_at']);
        $this->assertSame(1, (int) $extracted['applied_by']);

        // 6. Message auto-transition to 40 (Processed) — only pending child
        $message = $this->db->fetchRow(
            'SELECT docState FROM core_mail_incoming_messages WHERE id = %i',
            $this->messageRowId,
        );
        $this->assertSame(40, (int) $message['docState']);
    }

    public function testApplyExtractedIdempotentOnSecondClick(): void
    {
        $suffix = (string) uniqid();
        $extractedNdx = $this->provisionExtractedDocument($this->buildCanonical($suffix));

        $first = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($first));
        $savedDocId = (int) $first->getPayload()['data']['savedDocId'];
        $this->createdDocIds[] = $savedDocId;

        // Capture the partner + item ids for cleanup before second call
        $head = $this->db->fetchRow('SELECT partner FROM docs_core_heads WHERE id = %i', $savedDocId);
        $this->createdPersonIds[] = (int) $head['partner'];
        $rows = $this->db->fetchAll('SELECT item FROM docs_core_rows WHERE doc_head = %i', $savedDocId);
        foreach ($rows as $r) {
            $this->createdItemIds[] = (int) $r['item'];
        }

        // Second call — should idempotently return the same savedDocId
        $second = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($second));
        $secondPayload = $second->getPayload();
        $this->assertSame($savedDocId, (int) $secondPayload['data']['savedDocId']);
        $this->assertTrue($secondPayload['data']['idempotent'] ?? false);

        // No duplicate rows in docs_core_heads
        $heads = $this->db->fetchAll(
            'SELECT id FROM docs_core_heads WHERE source_extracted_doc = %i',
            $extractedNdx,
        );
        $this->assertCount(1, $heads);
    }

    public function testApplyExtractedRejectsAiFailedDocument(): void
    {
        // Force ai_failed status via direct DB
        $extractedNdx = $this->provisionExtractedDocument(['format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived']);
        $this->db->getDibiConnection()->query(
            'UPDATE core_mail_extracted_documents SET status = %i WHERE id = %i',
            ExtractedDocumentDocument::STATUS_AI_FAILED, $extractedNdx,
        );

        $resp = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('AI_OUTPUT_INVALID', $resp->getPayload()['error']['code']);
    }

    // ── unapply (undo) round-trip ────────────────────────────────────────────

    /** Zaeviduje partner/item vytvořené apply-em pro úklid v tearDown. */
    private function trackCreatedFromDoc(int $savedDocId): void
    {
        $this->createdDocIds[] = $savedDocId;
        $head = $this->db->fetchRow('SELECT partner FROM docs_core_heads WHERE id = %i', $savedDocId);
        if ($head !== null) {
            $this->createdPersonIds[] = (int) $head['partner'];
        }
        foreach ($this->db->fetchAll('SELECT item FROM docs_core_rows WHERE doc_head = %i', $savedDocId) as $r) {
            $this->createdItemIds[] = (int) $r['item'];
        }
    }

    public function testUnapplyRoundTripTrashesDocAndRestoresExtracted(): void
    {
        $suffix = (string) uniqid();
        $extractedNdx = $this->provisionExtractedDocument($this->buildCanonical($suffix));

        $apply = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($apply), 'apply: ' . json_encode($apply->getPayload()));
        $savedDocId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->trackCreatedFromDoc($savedDocId);

        // Apply posunul zprávu na 40 (jediný pending child vyřešen).
        $this->assertSame(40, (int) $this->db->fetchRow(
            'SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $this->messageRowId,
        )['docState']);

        $undo = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($undo), 'unapply: ' . json_encode($undo->getPayload()));
        $undoData = $undo->getPayload()['data'];
        $this->assertSame($savedDocId, (int) $undoData['trashedDocId']);
        $this->assertSame(ExtractedDocumentDocument::STATUS_PENDING_REVIEW, (int) $undoData['status']);

        // Doklad → Koš (90), ne hard-delete.
        $this->assertSame(90, (int) $this->db->fetchRow(
            'SELECT docState FROM docs_core_heads WHERE id = %i', $savedDocId,
        )['docState']);

        // Extracted → pending_review, target/applied_* vynulované.
        $extracted = $this->db->fetchRow('SELECT * FROM core_mail_extracted_documents WHERE id = %i', $extractedNdx);
        $this->assertSame(ExtractedDocumentDocument::STATUS_PENDING_REVIEW, (int) $extracted['status']);
        $this->assertNull($extracted['target_row_ndx']);
        $this->assertNull($extracted['applied_at']);
        $this->assertNull($extracted['applied_by']);

        // Zpráva zpět na 30 (Analyzovaná) — reverzní reconcile.
        $this->assertSame(30, (int) $this->db->fetchRow(
            'SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $this->messageRowId,
        )['docState']);
    }

    public function testUnapplyRejectsNonApplied(): void
    {
        $suffix = (string) uniqid();
        $extractedNdx = $this->provisionExtractedDocument($this->buildCanonical($suffix)); // status 20

        $resp = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('INVALID_STATE', $resp->getPayload()['error']['code']);
    }

    public function testUnapplyRejectsAdvancedDocument(): void
    {
        $suffix = (string) uniqid();
        $extractedNdx = $this->provisionExtractedDocument($this->buildCanonical($suffix));

        $apply = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $savedDocId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->trackCreatedFromDoc($savedDocId);

        // Doklad posunut z Konceptu dál (10→20) → unapply musí odmítnout.
        $this->db->getDibiConnection()->query(
            'UPDATE docs_core_heads SET docState = 20 WHERE id = %i', $savedDocId,
        );

        $resp = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('DOC_ADVANCED', $resp->getPayload()['error']['code']);
    }
}
