<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Base\Registry\RegistryDocumentDocument;

/**
 * Validace a promote sync RegistryDocumentDocument. Konfigurace docKinds
 * jde přes mock ConfigRuntime; DB není potřeba.
 */
class RegistryDocumentDocumentTest extends TestCase
{
    private const DOC_KINDS = [
        'contract' => [
            'name' => 'Smlouva',
            'fields' => ['contractNumber', 'validFrom', 'validTo', 'subject'],
            'promote' => [
                'contractNumber' => 'ref_number',
                'validFrom' => 'valid_from',
                'validTo' => 'valid_to',
            ],
        ],
        'other' => [
            'name' => 'Ostatní',
            'fields' => [],
            'promote' => [],
        ],
    ];

    private function doc(bool $withConfig = true): RegistryDocumentDocument
    {
        $doc = new RegistryDocumentDocument();
        if ($withConfig) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnMap([
                ['base.registry.docKinds', self::DOC_KINDS],
            ]);
            $doc->setConfig($config);
        }
        return $doc;
    }

    // --- validate -----------------------------------------------------------

    public function testValidateMissingTitleFails(): void
    {
        $doc = $this->doc();
        $data = ['doc_kind' => 'other'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('title', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDocKindFails(): void
    {
        $doc = $this->doc();
        $data = ['title' => 'Smlouva o dílo'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('doc_kind', array_column($result->toArray(), 'column'));
    }

    public function testValidateUnknownDocKindFails(): void
    {
        $doc = $this->doc();
        $data = ['title' => 'X', 'doc_kind' => 'nonsense'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'doc_kind',
        );
        $this->assertSame('unknown_kind', array_values($errors)[0]['code']);
    }

    public function testValidateUnknownKindWithoutConfigPasses(): void
    {
        // Degradace bez compiled configu — existence druhu se nekontroluje.
        $doc = $this->doc(withConfig: false);
        $data = ['title' => 'X', 'doc_kind' => 'nonsense'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateInvalidDateRangeFails(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-01-01',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'valid_to',
        );
        $this->assertSame('invalid_range', array_values($errors)[0]['code']);
    }

    public function testValidateEqualDatesPass(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-06-01',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateInvalidJsonMetadataFails(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'metadata' => '{not json',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'metadata',
        );
        $this->assertSame('invalid_json', array_values($errors)[0]['code']);
    }

    public function testValidateScalarJsonMetadataFails(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'metadata' => '"jen string"',
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
    }

    public function testValidateValidJsonMetadataPasses(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'metadata' => '{"subject": "kancelář"}',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- beforeSave: audit --------------------------------------------------

    public function testBeforeSaveFillsAuditForNewRecord(): void
    {
        $doc = $this->doc();
        $data = ['title' => 'X', 'doc_kind' => 'other'];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
    }

    public function testBeforeSaveOnUpdateKeepsCreatedUpdatesModified(): void
    {
        $doc = $this->doc();
        $created = '2026-01-01 00:00:00';
        $data = [
            'id' => 3,
            'title' => 'X',
            'doc_kind' => 'other',
            'created' => $created,
            'modified' => $created,
        ];

        $doc->beforeSave($data, ['id' => 3, 'title' => 'X', 'doc_kind' => 'other']);

        $this->assertSame($created, $data['created']);
        $this->assertNotSame($created, $data['modified']);
    }

    // --- beforeSave: promote sync -------------------------------------------

    public function testPromoteSyncDirtyFormColumnWritesToMetadata(): void
    {
        $doc = $this->doc();
        $original = [
            'id' => 1,
            'doc_kind' => 'contract',
            'ref_number' => 'SM-001',
            'metadata' => '{"contractNumber": "SM-001"}',
        ];
        $data = [
            'id' => 1,
            'title' => 'X',
            'doc_kind' => 'contract',
            'ref_number' => 'SM-002',
            'metadata' => '{"contractNumber": "SM-001"}',
        ];

        $doc->beforeSave($data, $original);

        $metadata = json_decode($data['metadata'], true);
        $this->assertSame('SM-002', $metadata['contractNumber']);
        $this->assertSame('SM-002', $data['ref_number']);
    }

    public function testPromoteSyncMetadataFillsColumnsOnImportPath(): void
    {
        // Insert bez promoted sloupců — metadata je zdroj (import/AI).
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'contract',
            'metadata' => '{"contractNumber": "SM-100", "validFrom": "2026-01-01", "validTo": "2026-12-31"}',
        ];

        $doc->beforeSave($data);

        $this->assertSame('SM-100', $data['ref_number']);
        $this->assertSame('2026-01-01', $data['valid_from']);
        $this->assertSame('2026-12-31', $data['valid_to']);
    }

    public function testPromoteSyncDirtyFormWinsOverMetadata(): void
    {
        $doc = $this->doc();
        $original = [
            'id' => 1,
            'doc_kind' => 'contract',
            'valid_to' => '2026-06-30',
            'metadata' => '{"validTo": "2026-06-30"}',
        ];
        $data = [
            'id' => 1,
            'title' => 'X',
            'doc_kind' => 'contract',
            'valid_to' => '2027-06-30',
            'metadata' => '{"validTo": "2026-06-30"}',
        ];

        $doc->beforeSave($data, $original);

        // Dirty formulářová hodnota vyhrává a propíše se do metadata.
        $this->assertSame('2027-06-30', $data['valid_to']);
        $metadata = json_decode($data['metadata'], true);
        $this->assertSame('2027-06-30', $metadata['validTo']);
    }

    public function testPromoteSyncNotDirtyColumnFilledFromMetadata(): void
    {
        // Sloupec beze změny + změněná metadata (tab Metadata) → metadata vyhrávají.
        $doc = $this->doc();
        $original = [
            'id' => 1,
            'doc_kind' => 'contract',
            'ref_number' => 'SM-001',
            'metadata' => '{"contractNumber": "SM-001"}',
        ];
        $data = [
            'id' => 1,
            'title' => 'X',
            'doc_kind' => 'contract',
            'ref_number' => 'SM-001',
            'metadata' => '{"contractNumber": "SM-999"}',
        ];

        $doc->beforeSave($data, $original);

        $this->assertSame('SM-999', $data['ref_number']);
    }

    public function testPromoteSyncKindWithoutPromoteMapIsNoOp(): void
    {
        $doc = $this->doc();
        $data = [
            'title' => 'X',
            'doc_kind' => 'other',
            'ref_number' => 'ABC',
            'metadata' => '{"whatever": "zůstane"}',
        ];

        $doc->beforeSave($data);

        $this->assertSame('ABC', $data['ref_number']);
        $metadata = json_decode($data['metadata'], true);
        $this->assertSame(['whatever' => 'zůstane'], $metadata);
    }

    public function testPromoteSyncClearedFormFieldRemovesMetadataKey(): void
    {
        $doc = $this->doc();
        $original = [
            'id' => 1,
            'doc_kind' => 'contract',
            'ref_number' => 'SM-001',
            'metadata' => '{"contractNumber": "SM-001", "subject": "dílo"}',
        ];
        $data = [
            'id' => 1,
            'title' => 'X',
            'doc_kind' => 'contract',
            'ref_number' => '',
            'metadata' => '{"contractNumber": "SM-001", "subject": "dílo"}',
        ];

        $doc->beforeSave($data, $original);

        $metadata = json_decode($data['metadata'], true);
        $this->assertArrayNotHasKey('contractNumber', $metadata);
        $this->assertSame('dílo', $metadata['subject']);
    }

    public function testPromoteSyncPartialSaveWithoutMetadataAndColumnsIsNoOp(): void
    {
        // docState-only save nesmí sahat na metadata ani promoted sloupce.
        $doc = $this->doc();
        $data = [
            'id' => 1,
            'doc_kind' => 'contract',
            'title' => 'X',
            'docState' => 40,
        ];

        $doc->beforeSave($data, [
            'id' => 1,
            'doc_kind' => 'contract',
            'metadata' => '{"contractNumber": "SM-001"}',
            'ref_number' => 'SM-001',
        ]);

        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertArrayNotHasKey('ref_number', $data);
    }

    public function testPromoteSyncDatesFromDbAsDateTimeNotFalselyDirty(): void
    {
        // DB vrací date sloupce jako DateTime — porovnání s formulářovým
        // stringem nesmí hlásit dirty.
        $doc = $this->doc();
        $original = [
            'id' => 1,
            'doc_kind' => 'contract',
            'valid_to' => new \DateTimeImmutable('2026-06-30'),
            'metadata' => '{"validTo": "2026-12-31"}',
        ];
        $data = [
            'id' => 1,
            'title' => 'X',
            'doc_kind' => 'contract',
            'valid_to' => '2026-06-30',
            'metadata' => '{"validTo": "2026-12-31"}',
        ];

        $doc->beforeSave($data, $original);

        // Sloupec není dirty → plní se z metadata (zdroj pravdy).
        $this->assertSame('2026-12-31', $data['valid_to']);
    }
}
