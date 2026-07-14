<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Registry;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\ExtractedDocumentDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí AI cesty Spisovny (Fáze 2, target `registry`):
 * extracted document s registry canonicalem → applyExtracted →
 * RegistryApplier → dokument v base_registry_documents (rovnou 40) +
 * kopie příloh + extracted_text + lineage; unapply → Koš + reverz +
 * guard DOC_ADVANCED. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class AiRegistryApplyTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-REGAI';
    private const MAIL_TABLE_ID = 303;
    private const REGISTRY_TABLE_ID = 428;

    private AnalysisController $controller;
    private DocumentRegistry $documentRegistry;
    private ConfigRuntime $configRuntime;
    private AttachmentService $attachments;
    private int $mailboxId = 0;

    private int $messageRowId = 0;
    private int $analysisRowId = 0;
    private int $extractedRowId = 0;

    /** @var list<int> */
    private array $createdDocumentIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];
    /** @var list<int> */
    private array $createdBinderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes ORDER BY is_default DESC, id LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS has no mailbox — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        if (($this->configRuntime->cfgItem('core.mail.extractedDocTypes')['insurance']['target'] ?? null) !== 'registry') {
            $this->markTestSkipped('compiled config missing registry targets — run ds-upgrade.');
        }

        // DocumentApplier je pro registry cestu nevyužitý, ale controller bez
        // něj degraduje na plain status update (Phase 1 fallback) — wiring
        // musí odpovídat produkci.
        $applier = DocumentApplier::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
            $this->dsConfig,
            $this->documentRegistry,
            $this->tables,
        );

        // dsPath = testovací sandbox příloh (IntegrationTestCase) — controller
        // z něj staví AttachmentService pro RegistryApplier, musí se shodovat
        // s instancí, přes kterou testy uploadují fixtures.
        $this->controller = new AnalysisController(
            $this->db, $this->dsConfig, $this->dsPath, $this->tables, $this->documentRegistry,
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $this->configRuntime,
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();

        if ($this->extractedRowId > 0) {
            $dibi->delete('core_mail_extracted_documents')->where('id = %i', $this->extractedRowId)->execute();
        }
        if ($this->analysisRowId > 0) {
            $dibi->delete('core_mail_message_analyses')->where('id = %i', $this->analysisRowId)->execute();
        }
        if ($this->messageRowId > 0) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', self::MAIL_TABLE_ID, $this->messageRowId)->execute();
            $dibi->delete('core_mail_incoming_messages')->where('id = %i', $this->messageRowId)->execute();
        }
        foreach ($this->createdDocumentIds as $id) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', self::REGISTRY_TABLE_ID, $id)->execute();
            $dibi->delete('base_registry_documents')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdBinderIds as $id) {
            $dibi->delete('base_registry_binders')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->delete('base_persons_persons')->where('id = %i', $id)->execute();
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** Zpráva ve 20 (K řešení) — apply ji přes afterPersist posune na 40. */
    private function createMessage(string $senderEmail): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', [
            'message_id'          => self::PREFIX . '-MSG-' . uniqid(),
            'mailbox'             => $this->mailboxId,
            'primary_type'        => 'insurance',
            'subject'             => self::PREFIX . ' Pojistná smlouva',
            'sender_email'        => $senderEmail,
            'received_at'         => $now,
            'source_type'         => 2,
            'ai_analysis_enabled' => 1,
            'needs_reanalysis'    => 0,
            'analysis_state'      => 30,
            'docState'            => 20,
            'docStateMain'        => 2,
            'created'             => $now,
            'modified'            => $now,
        ])->execute();
        $this->messageRowId = (int) $dibi->getInsertId();
        return $this->messageRowId;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param list<int> $sourceAttachments
     */
    private function provisionExtracted(array $canonical, array $sourceAttachments = []): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();

        $dibi->insert('core_mail_message_analyses', [
            'message'                  => $this->messageRowId,
            'analyzed_at'              => $now,
            'status'                   => 2,
            'model_name'               => 'fixture',
            'prompt_version'           => 'v3.0.0',
            'extracted_document_count' => 1,
            'created'                  => $now,
        ])->execute();
        $this->analysisRowId = (int) $dibi->getInsertId();

        $dibi->insert('core_mail_extracted_documents', [
            'message'            => $this->messageRowId,
            'analysis'           => $this->analysisRowId,
            'doc_type'           => 'insurance',
            'source_attachments' => json_encode($sourceAttachments),
            'extracted_json'     => json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'confidence'         => 0.93,
            'status'             => ExtractedDocumentDocument::STATUS_READY_TO_APPLY,
            'created'            => $now,
        ])->execute();
        $this->extractedRowId = (int) $dibi->getInsertId();
        return $this->extractedRowId;
    }

    /** @return array<string, mixed> */
    private function insuranceCanonical(string $companyId): array
    {
        return [
            'schema'  => 'shpd.registry.document.v1',
            'docType' => 'insurance',
            'title'   => self::PREFIX . ' Pojistná smlouva — flotila',
            'summary' => 'Pojištění vozového parku. Pozor na automatickou prolongaci.',
            'party'   => ['name' => self::PREFIX . ' Pojišťovna', 'companyId' => $companyId, 'email' => 'info@example.com'],
            'kindFields' => [
                'insurer'       => self::PREFIX . ' Pojišťovna',
                'policyNumber'  => self::PREFIX . '-POJ-42',
                'validFrom'     => '2026-01-01',
                'validTo'       => '2026-12-31',
                'annualPremium' => 45000.0,
                'currency'      => 'CZK',
            ],
            'binderSuggestion' => self::PREFIX . ' pojištění',
        ];
    }

    private function createPerson(string $fullName, string $email, string $companyId): int
    {
        $dibi = $this->db->getDibiConnection();
        $data = [
            'person_type'  => 2,
            'full_name'    => $fullName,
            'email'        => $email,
            'company_id'   => $companyId,
            'docState'     => 40,
            'docStateMain' => 3,
        ];
        $doc = $this->documentRegistry->getDocument('base_persons_persons', $data);
        $doc->setDb($dibi);
        $validation = $doc->validate($data);
        $this->assertTrue($validation->isValid(), 'person fixture validation');
        $doc->beforeSave($data);
        $dibi->insert('base_persons_persons', $data)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdPersonIds[] = $id;
        return $id;
    }

    private function createBinder(string $name): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_registry_binders', [
            'name'         => $name,
            'docState'     => 40,
            'docStateMain' => 3,
            'created'      => date('Y-m-d H:i:s'),
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdBinderIds[] = $id;
        return $id;
    }

    /** @return array{id: int, checksum: string} */
    private function uploadAttachment(int $messageId, string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_regai_');
        file_put_contents($tmp, $content);
        $result = $this->attachments->upload(self::MAIL_TABLE_ID, $messageId, $name, $tmp, null);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        return [
            'id'       => (int) $result['data']['id'],
            'checksum' => (string) $result['data']['checksum'],
        ];
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

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testApplyCreatesFiledDocumentWithAttachmentsAndLineage(): void
    {
        $companyId = '99' . substr((string) (microtime(true) * 10000), -6);
        $senderEmail = 'it-regai-' . uniqid() . '@example.com';
        $personId = $this->createPerson(self::PREFIX . ' Pojišťovna', $senderEmail, $companyId);
        $binderId = $this->createBinder(self::PREFIX . ' Pojištění');

        $messageId = $this->createMessage($senderEmail);
        $att = $this->uploadAttachment($messageId, 'pojistka.txt', self::PREFIX . ' fulltext pojistné smlouvy');
        $extractedNdx = $this->provisionExtracted($this->insuranceCanonical($companyId), [$att['id']]);

        $resp = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($resp), 'apply: ' . json_encode($resp->getPayload()));
        $docId = (int) $resp->getPayload()['data']['savedDocId'];
        $this->createdDocumentIds[] = $docId;

        // 1. Dokument rovnou v 40 (Zařazeno) s mappingem canonicalu.
        $doc = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $docId);
        $this->assertNotNull($doc);
        $this->assertSame(40, (int) $doc['docState']);
        $this->assertSame(3, (int) $doc['docStateMain']);
        $this->assertSame('insurance', (string) $doc['doc_kind']);
        $this->assertSame(self::PREFIX . ' Pojistná smlouva — flotila', (string) $doc['title']);
        $this->assertStringContainsString('prolongaci', (string) $doc['ai_summary']);
        $this->assertSame('mail', (string) $doc['source_kind']);
        $this->assertSame($messageId, (int) $doc['source_message']);
        $this->assertSame($extractedNdx, (int) $doc['extracted_doc']);
        $this->assertSame(1, (int) $doc['created_by']);

        // 2. metadata = kindFields 1:1, promoted sloupce doplněné beforeSave.
        $metadata = json_decode((string) $doc['metadata'], true);
        $this->assertSame(self::PREFIX . '-POJ-42', $metadata['policyNumber']);
        $this->assertSame(45000.0, (float) $metadata['annualPremium']);
        $this->assertSame(self::PREFIX . '-POJ-42', (string) $doc['ref_number']);
        $this->assertSame('2026-01-01', $this->dateOf($doc['valid_from']));
        $this->assertSame('2026-12-31', $this->dateOf($doc['valid_to']));

        // 3. Partner: PartyResolver match dle companyId.
        $this->assertSame($personId, (int) $doc['partner']);

        // 4. Binder: bez historie padá na case-insensitive match jména.
        $this->assertSame($binderId, (int) $doc['binder']);

        // 5. Kopie přílohy (D8) — shodný checksum na dokumentu.
        $copied = $this->db->fetchAll(
            'SELECT checksum FROM core_attachments_files'
            . ' WHERE table_id = %i AND record_id = %i AND is_deleted = 0',
            self::REGISTRY_TABLE_ID, $docId,
        );
        $this->assertCount(1, $copied);
        $this->assertSame($att['checksum'], (string) $copied[0]['checksum']);

        // 6. extracted_text z kopie (text/plain → přímé čtení, bez pdftotext).
        $this->assertStringContainsString('fulltext pojistné smlouvy', (string) $doc['extracted_text']);

        // 7. Lineage + status na extracted řádku.
        $extracted = $this->db->fetchRow('SELECT * FROM core_mail_extracted_documents WHERE id = %i', $extractedNdx);
        $this->assertSame('base_registry_documents', (string) $extracted['target_table_id']);
        $this->assertSame($docId, (int) $extracted['target_row_ndx']);
        $this->assertSame(ExtractedDocumentDocument::STATUS_APPLIED, (int) $extracted['status']);
        $this->assertNotNull($extracted['applied_at']);

        // 8. Zpráva auto-transition 20 → 40 (jediný pending child vyřešen).
        $message = $this->db->fetchRow('SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $messageId);
        $this->assertSame(40, (int) $message['docState']);

        // 9. Druhý apply je idempotentní (recovery cesta completeApplied).
        $again = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($again));
        $this->assertTrue((bool) ($again->getPayload()['data']['idempotent'] ?? false));
        $this->assertSame($docId, (int) $again->getPayload()['data']['savedDocId']);
    }

    public function testUnapplyRoundTripTrashesDocumentAndRestoresExtracted(): void
    {
        $companyId = '98' . substr((string) (microtime(true) * 10000), -6);
        $messageId = $this->createMessage('it-regai-' . uniqid() . '@example.com');
        $extractedNdx = $this->provisionExtracted($this->insuranceCanonical($companyId));

        $apply = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($apply), 'apply: ' . json_encode($apply->getPayload()));
        $docId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->createdDocumentIds[] = $docId;

        $undo = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($undo), 'unapply: ' . json_encode($undo->getPayload()));

        // Dokument v Koši, extracted zpět na 20 s vynulovanými target_*.
        $doc = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $docId);
        $this->assertSame(90, (int) $doc['docState']);
        $this->assertSame(5, (int) $doc['docStateMain']);

        $extracted = $this->db->fetchRow('SELECT * FROM core_mail_extracted_documents WHERE id = %i', $extractedNdx);
        $this->assertSame(ExtractedDocumentDocument::STATUS_PENDING_REVIEW, (int) $extracted['status']);
        $this->assertNull($extracted['target_row_ndx']);
        $this->assertNull($extracted['applied_at']);

        // Zpráva reverz 40 → 20 (znovu má pending child).
        $message = $this->db->fetchRow('SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $messageId);
        $this->assertSame(20, (int) $message['docState']);

        // Opakovaný unapply → 409 INVALID_STATE.
        $again = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(409, $this->statusOf($again));
    }

    public function testUnapplyGuardsAgainstDocumentModifiedSinceApply(): void
    {
        $companyId = '97' . substr((string) (microtime(true) * 10000), -6);
        $this->createMessage('it-regai-' . uniqid() . '@example.com');
        $extractedNdx = $this->provisionExtracted($this->insuranceCanonical($companyId));

        $apply = $this->controller->applyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(200, $this->statusOf($apply), 'apply: ' . json_encode($apply->getPayload()));
        $docId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->createdDocumentIds[] = $docId;

        // Mezitímní editace dokumentu (bump modified za applied_at).
        $this->db->getDibiConnection()->update('base_registry_documents', [
            'modified' => date('Y-m-d H:i:s', time() + 3600),
        ])->where('id = %i', $docId)->execute();

        $undo = $this->controller->unapplyExtracted($this->authed(), $this->request(), $extractedNdx);
        $this->assertSame(409, $this->statusOf($undo));
        $this->assertSame('DOC_ADVANCED', (string) ($undo->getPayload()['error']['code'] ?? ''));

        // Dokument zůstal ve 40, extracted zůstal applied.
        $doc = $this->db->fetchRow('SELECT docState FROM base_registry_documents WHERE id = %i', $docId);
        $this->assertSame(40, (int) $doc['docState']);
        $extracted = $this->db->fetchRow('SELECT status FROM core_mail_extracted_documents WHERE id = %i', $extractedNdx);
        $this->assertSame(ExtractedDocumentDocument::STATUS_APPLIED, (int) $extracted['status']);
    }

    private function dateOf(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
