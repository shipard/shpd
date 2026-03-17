<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DefaultDocument;

class DocumentTest extends TestCase
{
    public function testDefaultValidateReturnsValidResult(): void
    {
        $doc = new DefaultDocument();
        $data = ['name' => 'Test'];
        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function testDefaultBeforeSaveDoesNotModifyData(): void
    {
        $doc = new DefaultDocument();
        $data = ['name' => 'Test', 'value' => 42];
        $original = $data;

        $doc->beforeSave($data);

        $this->assertSame($original, $data);
    }

    public function testDefaultAfterSaveDoesNotThrow(): void
    {
        $doc = new DefaultDocument();
        $doc->afterSave(['id' => 1]);
        $this->assertTrue(true);
    }

    public function testDefaultBeforeDeleteDoesNotThrow(): void
    {
        $doc = new DefaultDocument();
        $doc->beforeDelete(['id' => 1]);
        $this->assertTrue(true);
    }

    public function testDefaultAfterDeleteDoesNotThrow(): void
    {
        $doc = new DefaultDocument();
        $doc->afterDelete(['id' => 1]);
        $this->assertTrue(true);
    }

    public function testDefaultOnLoadDoesNotModifyData(): void
    {
        $doc = new DefaultDocument();
        $data = ['id' => 1, 'name' => 'Test'];
        $original = $data;

        $doc->onLoad($data);

        $this->assertSame($original, $data);
    }
}
