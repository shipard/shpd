<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\MailboxDocument;

/**
 * Validace a beforeSave logika MailboxDocument. DB-bound validace
 * (is_default uniqueness) používá mock přes anonymní subclass.
 */
class MailboxDocumentTest extends TestCase
{
    private function doc(): MailboxDocument
    {
        return new MailboxDocument();
    }

    // --- validate -----------------------------------------------------------

    public function testValidateMissingMailboxIdFails(): void
    {
        $doc = $this->doc();
        $data = [
            'name' => 'Default',
            'email_address' => 'a@example.com',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('mailbox_id', $columns);
    }

    public function testValidateMissingNameFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'email_address' => 'a@example.com',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateMissingEmailFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('email_address', $columns);
    }

    public function testValidateInvalidEmailFails(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'not-an-email',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'email_address',
        );
        $this->assertCount(1, $errors);
        $first = array_values($errors)[0];
        $this->assertSame('invalid_format', $first['code']);
    }

    public function testValidateCompleteDataPasses(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsDefaultWithoutDbSkipsUniquenessCheck(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
            'is_default' => true,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsDefaultWithExistingDefaultFails(): void
    {
        $row = new \Dibi\Row(['mailbox_id' => 'invoices', 'name' => 'Faktury']);

        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn($row);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
            'is_default' => true,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'is_default',
        );
        $this->assertCount(1, $errors);
        $first = array_values($errors)[0];
        $this->assertSame('duplicate_default', $first['code']);
        $this->assertStringContainsString('Faktury', $first['message']);
    }

    public function testValidateIsDefaultWhenNoPriorDefaultPasses(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
            'is_default' => true,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveNormalizesEmail(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => '  UPPER@Example.COM  ',
        ];

        $doc->beforeSave($data);

        $this->assertSame('upper@example.com', $data['email_address']);
    }

    public function testBeforeSaveCoercesIsDefaultToInt(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'is_default' => true,
        ];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['is_default']);
    }

    public function testBeforeSaveCoercesFalseyIsDefaultToZero(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'is_default' => false,
        ];

        $doc->beforeSave($data);

        $this->assertSame(0, $data['is_default']);
    }

    public function testBeforeSaveCoercesAiAnalysisDisabledToInt(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'ai_analysis_disabled' => true,
        ];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['ai_analysis_disabled']);
    }

    public function testBeforeSaveCoercesFalseyAiAnalysisDisabledToZero(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'ai_analysis_disabled' => false,
        ];

        $doc->beforeSave($data);

        $this->assertSame(0, $data['ai_analysis_disabled']);
    }

    public function testBeforeSaveLeavesAiAnalysisDisabledUnsetWhenAbsent(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
        ];

        $doc->beforeSave($data);

        $this->assertArrayNotHasKey('ai_analysis_disabled', $data);
    }

    public function testBeforeSaveFillsAuditFieldsForNewRecord(): void
    {
        $doc = $this->doc();
        $data = [
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
        ];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
    }

    public function testBeforeSaveOnExistingRecordOnlyUpdatesModified(): void
    {
        $doc = $this->doc();
        $existingCreated = '2025-01-01 00:00:00';
        $data = [
            'id' => 5,
            'mailbox_id' => 'default',
            'name' => 'Default',
            'email_address' => 'a@example.com',
            'created' => $existingCreated,
        ];

        $doc->beforeSave($data);

        $this->assertSame($existingCreated, $data['created']);
        $this->assertNotSame($existingCreated, $data['modified']);
    }
}
