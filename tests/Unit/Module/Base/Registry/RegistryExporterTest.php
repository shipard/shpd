<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Registry\RegistryExporter;

class RegistryExporterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function docRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 30, 'title' => 'Smlouva o dílo — web', 'doc_kind' => 'contract', 'binder' => 2, 'ref_number' => 'SML-2026-01',
            'partner' => 5, 'valid_from' => '2026-01-01', 'valid_to' => new \DateTimeImmutable('2027-12-31'),
            'ai_summary' => 'Smlouva na vývoj webu.', 'metadata' => '{"contractNumber":"SML-2026-01","subject":"web"}',
            'extracted_text' => 'dlouhý text', 'source_kind' => 'mail', 'source_message' => 55, 'notice' => null,
            'docState' => 40, 'docStateMain' => 2, 'created' => new \DateTimeImmutable('2026-02-01 09:30:00'),
            'created_by' => 1, 'modified' => null,
            'binder_name' => 'Smlouvy', 'partner_name' => 'Dodavatel a.s.', 'partner_company_id' => '87654321',
            'partner_email' => 'info@dodavatel.example',
        ], $overrides);
    }

    private function db(array $attachments): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($attachments): array {
            if (str_contains($sql, '[core_attachments_files]')) {
                $this->assertStringContainsString('[is_deleted] = 0', $sql);
                return array_map(static fn(array $r) => new Row($r), $attachments);
            }
            return [];
        });
        return $db;
    }

    public function testEnvelopeCarriesCanonicalAndDatasetFields(): void
    {
        $db = $this->db([
            ['id' => 71, 'name' => 'smlouva.pdf', 'file_name' => 'smlouva-ab12c.pdf', 'file_path' => '2026/02/01/base_registry_documents',
             'file_size' => 12345, 'mime_type' => 'application/pdf', 'checksum' => str_repeat('a', 64), 'att_order' => 0],
            ['id' => 72, 'name' => 'smlouva.pdf', 'file_name' => 'smlouva-zz99x.pdf', 'file_path' => '2026/02/01/base_registry_documents',
             'file_size' => 100, 'mime_type' => 'application/pdf', 'checksum' => str_repeat('b', 64), 'att_order' => 1],
        ]);

        $record = (new RegistryExporter($db, '/opt/ds'))->exportDocument($this->docRow());
        $d = $record->data;

        $this->assertSame(30, $record->id);
        $this->assertSame('Smlouva o dílo — web', $record->slug);
        $this->assertSame(RegistryExporter::FORMAT, $d['format']);
        $this->assertSame([
            'schema'     => 'shpd.registry.document.v1',
            'docType'    => 'contract',
            'title'      => 'Smlouva o dílo — web',
            'summary'    => 'Smlouva na vývoj webu.',
            'party'      => ['name' => 'Dodavatel a.s.', 'companyId' => '87654321', 'email' => 'info@dodavatel.example'],
            'kindFields' => ['contractNumber' => 'SML-2026-01', 'subject' => 'web'],
        ], $d['document']);
        $this->assertSame(40, $d['docState']);
        $this->assertSame('mail', $d['sourceKind']);
        $this->assertSame('Smlouvy', $d['binder']);
        $this->assertSame('SML-2026-01', $d['refNumber']);
        $this->assertSame('2026-01-01', $d['validFrom']);
        $this->assertSame('2027-12-31', $d['validTo']);
        $this->assertSame('2026-02-01T09:30:00', $d['created']);
        $this->assertArrayNotHasKey('notice', $d);

        $this->assertSame('smlouva.pdf', $d['attachments'][0]['file']);
        $this->assertSame('smlouva-2.pdf', $d['attachments'][1]['file'], 'duplicate names get a suffix');
        $this->assertSame(12345, $d['attachments'][0]['size']);
        $this->assertSame(str_repeat('a', 64), $d['attachments'][0]['sha256']);

        $this->assertCount(2, $record->files);
        $this->assertSame('/opt/ds/att/2026/02/01/base_registry_documents/smlouva-ab12c.pdf', $record->files[0]->sourcePath);
        $this->assertSame('smlouva.pdf', $record->files[0]->name);
        $this->assertSame(71, $record->files[0]->attachmentId);
        $this->assertSame('smlouva-2.pdf', $record->files[1]->name);
    }

    public function testEmptyMetadataStaysAnObjectAndPartnerlessDocHasNoParty(): void
    {
        $d = (new RegistryExporter($this->db([]), '/opt/ds'))->exportDocument($this->docRow([
            'metadata' => null, 'partner' => null, 'partner_name' => null, 'partner_company_id' => null, 'partner_email' => null,
        ]))->data;

        $this->assertInstanceOf(\stdClass::class, $d['document']['kindFields']);
        $this->assertArrayNotHasKey('party', $d['document']);
        $this->assertArrayNotHasKey('attachments', $d);
        $this->assertStringContainsString('"kindFields": {}', json_encode($d, JSON_PRETTY_PRINT));
    }

    public function testUniqueNameHandlesNoExtensionAndSlashes(): void
    {
        $this->assertSame('a_b', RegistryExporter::uniqueName('a/b', []));
        $this->assertSame('readme-2', RegistryExporter::uniqueName('readme', ['readme' => true]));
        $this->assertSame('x-3.pdf', RegistryExporter::uniqueName('x.pdf', ['x.pdf' => true, 'x-2.pdf' => true]));
    }

    public function testExportAllSkipsTrashAndOrdersByCreated(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString('[d.docState] <> 90', $sql);
            $this->assertStringContainsString('ORDER BY [d.created], [d.title], [d.id]', $sql);
            return [];
        });

        $this->assertSame([], (new RegistryExporter($db, '/opt/ds'))->exportAll());
    }
}
