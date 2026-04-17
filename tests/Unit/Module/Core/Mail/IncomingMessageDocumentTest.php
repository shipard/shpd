<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\IncomingMessageDocument;

/**
 * Testuje validace, beforeSave transformace a beforeDelete cascade logiky
 * v IncomingMessageDocument. Testy, které nepotřebují DB, volají Document
 * přímo; pro DB-bound logiku (generateMessageId, cascade delete) používáme
 * anonymní subclass s reflection-overrided db (viz testable*).
 */
class IncomingMessageDocumentTest extends TestCase
{
    private function doc(): IncomingMessageDocument
    {
        return new IncomingMessageDocument();
    }

    // --- validate -----------------------------------------------------------

    public function testValidateMissingMailboxFails(): void
    {
        $doc = $this->doc();
        $data = [
            'subject' => 'Test',
            'sender_email' => 'sender@example.com',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('mailbox', $columns);
    }

    public function testValidateMissingSubjectFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'subject' => '   ',
            'sender_email' => 'sender@example.com',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('subject', $columns);
    }

    public function testValidateMissingSenderEmailFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => '',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('sender_email', $columns);
    }

    public function testValidateInvalidSenderEmailFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => 'not-an-email',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->toArray();
        $senderErrors = array_filter($errors, static fn(array $e): bool => $e['column'] === 'sender_email');
        $this->assertCount(1, $senderErrors);
        $first = array_values($senderErrors)[0];
        $this->assertSame('invalid_format', $first['code']);
    }

    public function testValidateMissingReceivedAtFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => 'sender@example.com',
            'received_at' => '',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('received_at', $columns);
    }

    public function testValidateCompleteDataPasses(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => 'sender@example.com',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- beforeSave (bez DB) -------------------------------------------------

    public function testBeforeSaveNormalizesSenderEmail(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'sender_email' => '  UPPER@Example.COM  ',
        ];

        $doc->beforeSave($data);

        $this->assertSame('upper@example.com', $data['sender_email']);
    }

    public function testBeforeSaveSetsSourceTypeForNewRecords(): void
    {
        $doc = $this->doc();
        $data = [
            // bez 'id' → nový záznam
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['source_type']);
    }

    public function testBeforeSaveDefaultsPrimaryTypeToOtherWithoutDb(): void
    {
        // Bez db => resolveDefaultPrimaryType vrátí 'other'
        $doc = $this->doc();
        $data = [
            'mailbox' => 42, // existuje-li bez db, fallback 'other'
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $doc->beforeSave($data);

        $this->assertSame('other', $data['primary_type']);
    }

    public function testBeforeSaveFillsAuditFieldsForNewRecord(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
        $this->assertIsString($data['created']);
        $this->assertIsString($data['modified']);
    }

    public function testBeforeSavePreservesExistingCreatedButUpdatesModified(): void
    {
        $doc = $this->doc();
        $existingCreated = '2025-01-01 00:00:00';
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'created' => $existingCreated,
        ];

        $doc->beforeSave($data);

        $this->assertSame($existingCreated, $data['created']);
        $this->assertNotSame($existingCreated, $data['modified']);
    }

    public function testBeforeSaveOnExistingRecordOnlyUpdatesModified(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'created' => '2025-01-01 00:00:00',
        ];
        $before = $data;

        $doc->beforeSave($data);

        // existing source_type není přepsán
        $this->assertArrayNotHasKey('source_type', $data);
        $this->assertArrayHasKey('modified', $data);
        $this->assertArrayNotHasKey('message_id', $data); // nová ID se generuje jen u nových
        $this->assertSame($before['created'], $data['created']);
    }

    public function testBeforeSaveRespectsExistingSourceType(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'source_type' => 2, // email, nastaveno mail-routerem
        ];

        $doc->beforeSave($data);

        $this->assertSame(2, $data['source_type']);
    }

    public function testBeforeSaveRespectsExistingPrimaryType(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'primary_type' => 'invoiceReceived',
        ];

        $doc->beforeSave($data);

        $this->assertSame('invoiceReceived', $data['primary_type']);
    }
}
