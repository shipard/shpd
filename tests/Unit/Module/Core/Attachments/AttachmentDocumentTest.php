<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Attachments\AttachmentDocument;

class AttachmentDocumentTest extends TestCase
{
    private function doc(): AttachmentDocument
    {
        return new AttachmentDocument();
    }

    public function testValidateRequiresTableId(): void
    {
        $doc = $this->doc();
        $data = ['record_id' => 1, 'name' => 'test.pdf'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('table_id', $columns);
    }

    public function testValidateRequiresRecordId(): void
    {
        $doc = $this->doc();
        $data = ['table_id' => 201, 'name' => 'test.pdf'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('record_id', $columns);
    }

    public function testValidateRequiresName(): void
    {
        $doc = $this->doc();
        $data = ['table_id' => 201, 'record_id' => 1];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateEmptyNameFails(): void
    {
        $doc = $this->doc();
        $data = ['table_id' => 201, 'record_id' => 1, 'name' => ''];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
    }

    public function testValidateValidData(): void
    {
        $doc = $this->doc();
        $data = ['table_id' => 201, 'record_id' => 42, 'name' => 'Faktura.pdf'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateReportsMultipleErrors(): void
    {
        $doc = $this->doc();
        $data = [];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertCount(3, $result->toArray());
    }
}
