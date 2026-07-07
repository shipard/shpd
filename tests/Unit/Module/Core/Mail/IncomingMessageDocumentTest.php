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

    // --- beforeSave: primary_type_source (spec §B2) ----------------------------

    public function testBeforeSaveMarksUserSourceWhenPrimaryTypeChanged(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'primary_type' => 'invoiceReceived',
        ];

        $doc->beforeSave($data, ['id' => 42, 'primary_type' => 'other']);

        $this->assertSame('user', $data['primary_type_source']);
    }

    public function testBeforeSaveKeepsSourceWhenPrimaryTypeUnchanged(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'primary_type' => 'other',
        ];

        $doc->beforeSave($data, ['id' => 42, 'primary_type' => 'other']);

        $this->assertArrayNotHasKey('primary_type_source', $data);
    }

    public function testBeforeSaveRespectsExplicitPrimaryTypeSource(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'primary_type' => 'invoiceReceived',
            'primary_type_source' => 'mailbox',
        ];

        $doc->beforeSave($data, ['id' => 42, 'primary_type' => 'other']);

        $this->assertSame('mailbox', $data['primary_type_source']);
    }

    // --- beforeSave: analysis_state default -----------------------------------

    public function testBeforeSaveDefaultsAnalysisStateToNoneWithoutDb(): void
    {
        // Bez db nelze ověřit dostupnost AI profilu → 0 (Bez analýzy)
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $doc->beforeSave($data);

        $this->assertSame(0, $data['analysis_state']);
    }

    public function testBeforeSaveQueuesAnalysisWhenActiveProfileExists(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        // message_id je vyplněné → jediný fetch je dotaz na aktivní AI profil
        $dibi->method('fetch')->willReturn(new \Dibi\Row(['id' => 17]));
        $doc = $this->doc();
        $doc->setDb($dibi);
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'message_id' => 'MSG-X', // přeskočí generateMessageId
        ];

        $doc->beforeSave($data);

        $this->assertSame(10, $data['analysis_state']);
    }

    public function testBeforeSaveSkipsQueueWhenAnalysisDisabled(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        // primary_type i message_id vyplněné → dotaz na profil se nesmí
        // vůbec spustit (short-circuit na ai_analysis_enabled=false)
        $dibi->expects($this->never())->method('fetch');
        $doc = $this->doc();
        $doc->setDb($dibi);
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'message_id' => 'MSG-X',
            'primary_type' => 'other',
            'ai_analysis_enabled' => false,
        ];

        $doc->beforeSave($data);

        $this->assertSame(0, $data['analysis_state']);
    }

    public function testBeforeSaveRespectsCallerProvidedAnalysisState(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox' => 1,
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
            'analysis_state' => 0, // import explicitně bez analýzy
        ];

        $doc->beforeSave($data);

        $this->assertSame(0, $data['analysis_state']);
    }

    // --- validate: read-only zámek při probíhající analýze --------------------

    public function testValidateRejectsUpdateWhileAnalyzing(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row(['analysis_state' => 20]));
        $doc = $this->doc();
        $doc->setDb($dibi);
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $first = $result->toArray()[0];
        $this->assertSame('_form', $first['column']);
        $this->assertSame('analysis_in_progress', $first['code']);
    }

    public function testValidateAllowsUpdateWhenAnalysisNotRunning(): void
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetch')->willReturn(new \Dibi\Row(['analysis_state' => 30]));
        $doc = $this->doc();
        $doc->setDb($dibi);
        $data = [
            'id' => 42,
            'mailbox' => 1,
            'subject' => 'Test',
            'sender_email' => 'a@b.cz',
            'received_at' => '2026-04-17 10:00:00',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }
}
