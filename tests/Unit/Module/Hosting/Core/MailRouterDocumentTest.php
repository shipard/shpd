<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Hosting\Core\MailRouterDocument;

class MailRouterDocumentTest extends TestCase
{
    public function testBeforeSaveNormalizesDomains(): void
    {
        $doc = new MailRouterDocument();
        $data = ['name' => 'Mail EU-1', 'domains' => ' Shipard.Email , example.COM ,, '];

        $doc->beforeSave($data);

        $this->assertSame('shipard.email,example.com', $data['domains']);
    }

    public function testBeforeSaveSetsAuditColumns(): void
    {
        $doc = new MailRouterDocument();

        $insert = ['name' => 'Mail EU-1', 'domains' => 'shipard.email'];
        $doc->beforeSave($insert);
        $this->assertNotEmpty($insert['created']);
        $this->assertNotEmpty($insert['modified']);

        $update = ['id' => 5, 'name' => 'Mail EU-1'];
        $doc->beforeSave($update, ['id' => 5]);
        $this->assertArrayNotHasKey('created', $update);
        $this->assertNotEmpty($update['modified']);
    }

    public function testValidateRejectsEmptyDomains(): void
    {
        $doc = new MailRouterDocument();

        $data = ['name' => 'Mail EU-1', 'domains' => ' , '];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('domains', $result->getErrors()[0]->column);

        $data = ['name' => 'Mail EU-1', 'domains' => 'shipard.email'];
        $this->assertTrue($doc->validate($data)->isValid());
    }
}
