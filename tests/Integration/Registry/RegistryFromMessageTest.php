<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Registry;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\FileFromMessageService;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí ručního zařazení zprávy do Spisovny
 * (`POST /_registry/from-message/{ndx}`) — FileFromMessageService volaná
 * přímo, HTTP vrstva je tenká slupka pokrytá vzorem ostatních endpointů).
 *
 * Testy si po sobě uklízí (prefix `IT-REG:` v subjectu / názvech).
 */
class RegistryFromMessageTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-REG:';
    private const MAIL_TABLE_ID = 303;
    private const REGISTRY_TABLE_ID = 428;

    private DocumentRegistry $documentRegistry;
    private ?ConfigRuntime $config = null;
    private AttachmentService $attachments;
    private FileFromMessageService $service;
    private int $mailboxId;

    /** @var list<int> */
    private array $createdMessageIds = [];
    /** @var list<int> */
    private array $createdDocumentIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);

        try {
            $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        } catch (\Throwable) {
            $this->config = null;
        }

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes ORDER BY is_default DESC, id LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS has no mailbox — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        $this->attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $this->service = new FileFromMessageService(
            $this->db,
            $this->documentRegistry,
            $this->attachments,
            $this->config,
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdDocumentIds as $id) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', self::REGISTRY_TABLE_ID, $id)->execute();
            $dibi->delete('base_registry_documents')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdMessageIds as $id) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', self::MAIL_TABLE_ID, $id)->execute();
            $dibi->delete('core_mail_incoming_messages')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->delete('base_persons_persons')->where('id = %i', $id)->execute();
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testHappyPathCreatesDraftCopiesAttachmentsAndClosesMessage(): void
    {
        $senderEmail = 'it-reg-' . uniqid() . '@example.com';
        $personId = $this->createPerson('IT-REG Firma s.r.o.', $senderEmail);
        $messageId = $this->createMessage(['sender_email' => $senderEmail]);
        $att1 = $this->uploadAttachment($messageId, 'smlouva.pdf', 'PDF A');
        $att2 = $this->uploadAttachment($messageId, 'dodatek.pdf', 'PDF B');
        $this->attachRawEml($messageId);

        $result = $this->service->fileFromMessage($messageId, null);

        $this->assertTrue($result['ok'], $result['errorMessage'] ?? '');
        $docId = (int) $result['id'];
        $this->createdDocumentIds[] = $docId;
        $this->assertArrayNotHasKey('warning', $result);

        // Dokument: Koncept s prefilly.
        $doc = $this->db->fetchRow('SELECT * FROM base_registry_documents WHERE id = %i', $docId);
        $this->assertNotNull($doc);
        $this->assertStringStartsWith(self::PREFIX, (string) $doc['title']);
        $this->assertSame('other', $doc['doc_kind']);
        $this->assertSame('mail', $doc['source_kind']);
        $this->assertSame($messageId, (int) $doc['source_message']);
        $this->assertSame($personId, (int) $doc['partner'], 'jednoznačný e-mail match → partner prefill');
        $this->assertSame(10, (int) $doc['docState']);
        $this->assertSame(1, (int) $doc['docStateMain']);

        // Přílohy: 2 obsahové zkopírované (bez raw .eml), shodné checksumy.
        $copied = $this->db->fetchAll(
            'SELECT name, checksum FROM core_attachments_files'
            . ' WHERE table_id = %i AND record_id = %i AND is_deleted = 0 ORDER BY att_order',
            self::REGISTRY_TABLE_ID,
            $docId,
        );
        $this->assertCount(2, $copied);
        $this->assertSame([$att1['checksum'], $att2['checksum']], array_column($copied, 'checksum'));

        // Zdrojové přílohy nedotčené.
        $sourceCount = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM core_attachments_files'
            . ' WHERE table_id = %i AND record_id = %i AND is_deleted = 0',
            self::MAIL_TABLE_ID,
            $messageId,
        );
        $this->assertSame(3, (int) $sourceCount['c'], '2 obsahové + raw .eml zůstávají u zprávy');

        // Zpráva: Hotovo + polymorfní vazba.
        $msg = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $messageId);
        $this->assertSame(40, (int) $msg['docState']);
        $this->assertSame(3, (int) $msg['docStateMain']);
        $this->assertSame('base_registry_documents', $msg['target_table_id']);
        $this->assertSame($docId, (int) $msg['target_row']);
    }

    public function testTrashedMessageReturns409(): void
    {
        $messageId = $this->createMessage();
        $this->db->getDibiConnection()->update('core_mail_incoming_messages', [
            'docState' => 90, 'docStateMain' => 5,
        ])->where('id = %i', $messageId)->execute();

        $result = $this->service->fileFromMessage($messageId, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('INVALID_STATE', $result['errorCode']);
        $this->assertSame(409, $result['statusCode']);
    }

    public function testMissingMessageReturns404(): void
    {
        $result = $this->service->fileFromMessage(999999999, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('NOT_FOUND', $result['errorCode']);
        $this->assertSame(404, $result['statusCode']);
    }

    public function testSecondFilingWarnsDuplicateButCreatesNewDocument(): void
    {
        $messageId = $this->createMessage();
        $this->uploadAttachment($messageId, 'pojistka.pdf', 'unique dup content ' . uniqid());

        $first = $this->service->fileFromMessage($messageId, null);
        $this->assertTrue($first['ok']);
        $this->createdDocumentIds[] = (int) $first['id'];
        $this->assertArrayNotHasKey('warning', $first);

        $second = $this->service->fileFromMessage($messageId, null);
        $this->assertTrue($second['ok'], 'duplicita neblokuje');
        $this->createdDocumentIds[] = (int) $second['id'];

        $this->assertNotSame((int) $first['id'], (int) $second['id']);
        $this->assertSame('DUPLICATE_IN_REGISTRY', $second['warning']['code']);
        $this->assertSame((int) $first['id'], (int) $second['warning']['existing_document_ndx']);
    }

    public function testMessageWithOnlyRawEmlCreatesDocumentWithoutAttachments(): void
    {
        $messageId = $this->createMessage();
        $this->attachRawEml($messageId);

        $result = $this->service->fileFromMessage($messageId, null);

        $this->assertTrue($result['ok']);
        $docId = (int) $result['id'];
        $this->createdDocumentIds[] = $docId;

        $count = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM core_attachments_files WHERE table_id = %i AND record_id = %i',
            self::REGISTRY_TABLE_ID,
            $docId,
        );
        $this->assertSame(0, (int) $count['c']);
    }

    public function testAmbiguousSenderEmailLeavesPartnerEmpty(): void
    {
        $email = 'it-reg-ambig-' . uniqid() . '@example.com';
        $this->createPerson('IT-REG Ambig A', $email);
        $this->createPerson('IT-REG Ambig B', $email);
        $messageId = $this->createMessage(['sender_email' => $email]);

        $result = $this->service->fileFromMessage($messageId, null);

        $this->assertTrue($result['ok']);
        $this->createdDocumentIds[] = (int) $result['id'];

        $doc = $this->db->fetchRow('SELECT partner FROM base_registry_documents WHERE id = %i', (int) $result['id']);
        $this->assertNull($doc['partner'], 'nejednoznačný match → žádné hádání');
    }

    public function testMessageAlreadyDoneKeepsStateButSetsTarget(): void
    {
        $messageId = $this->createMessage();
        $this->db->getDibiConnection()->update('core_mail_incoming_messages', [
            'docState' => 80, 'docStateMain' => 4,
        ])->where('id = %i', $messageId)->execute();

        $result = $this->service->fileFromMessage($messageId, null);

        $this->assertTrue($result['ok']);
        $this->createdDocumentIds[] = (int) $result['id'];

        $msg = $this->db->fetchRow('SELECT docState, target_row FROM core_mail_incoming_messages WHERE id = %i', $messageId);
        $this->assertSame(80, (int) $msg['docState'], 'Archiv se nepřepíná');
        $this->assertSame((int) $result['id'], (int) $msg['target_row'], 'vazba se nastaví vždy');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** Vloží zprávu přes Document hooky (vzor MailController::insertIncomingMessage). */
    private function createMessage(array $overrides = []): int
    {
        $dibi = $this->db->getDibiConnection();
        $data = array_merge([
            'mailbox'      => $this->mailboxId,
            'subject'      => self::PREFIX . ' zpráva ' . uniqid(),
            'sender_email' => 'it-reg-sender@example.com',
            'sender_name'  => 'IT-REG Tester',
            'received_at'  => date('Y-m-d H:i:s'),
            'body_plain'   => 'testovací tělo',
        ], $overrides);

        $doc = $this->documentRegistry->getDocument('core_mail_incoming_messages', $data);
        $doc->setDb($dibi);
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }

        $validation = $doc->validate($data);
        $this->assertTrue($validation->isValid(), 'message fixture validation');
        $doc->beforeSave($data);

        $dibi->insert('core_mail_incoming_messages', $data)->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdMessageIds[] = $id;
        return $id;
    }

    /** Založí živou osobu (Firma) přes Document hooky. @return int person id */
    private function createPerson(string $fullName, string $email): int
    {
        $dibi = $this->db->getDibiConnection();
        $data = [
            'person_type'  => 2, // Company
            'full_name'    => $fullName,
            'email'        => $email,
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

    /**
     * Nahraje obsahovou přílohu ke zprávě.
     *
     * @return array{id: int, checksum: string}
     */
    private function uploadAttachment(int $messageId, string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_reg_');
        file_put_contents($tmp, $content);

        $result = $this->attachments->upload(self::MAIL_TABLE_ID, $messageId, $name, $tmp, null);
        $this->assertTrue($result['success'], $result['error'] ?? '');

        return [
            'id'       => (int) $result['data']['id'],
            'checksum' => (string) $result['data']['checksum'],
        ];
    }

    /** Nahraje raw .eml a nastaví raw_source_attachment (nekopíruje se). */
    private function attachRawEml(int $messageId): void
    {
        $att = $this->uploadAttachment($messageId, 'message.eml', 'raw eml ' . uniqid());
        $this->db->getDibiConnection()->update('core_mail_incoming_messages', [
            'raw_source_attachment' => $att['id'],
        ])->where('id = %i', $messageId)->execute();
    }
}
