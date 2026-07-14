<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Attachments;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Attachments\FileStorage;

/**
 * FileStorage::copy + AttachmentService::copyTo — kopie přílohy k jinému
 * záznamu (D8): nový fyzický soubor s novým hashem, shodný checksum,
 * zdroj nedotčen, žádný DB zápis při selhání kopie.
 */
class AttachmentCopyTest extends TestCase
{
    private FileStorage $storage;
    private string $dsPath;

    protected function setUp(): void
    {
        $this->storage = new FileStorage();
        $this->dsPath = sys_get_temp_dir() . '/shpd_copy_test_' . uniqid();
        mkdir($this->dsPath . '/att', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->dsPath);
    }

    // --- FileStorage::copy ----------------------------------------------------

    public function testCopyCreatesNewPhysicalFileWithNewHash(): void
    {
        $source = $this->storeSourceFile('smlouva.pdf', 'PDF content here');

        $info = $this->storage->copy(
            $this->dsPath,
            'base_registry_documents',
            $this->storage->getFullPath($this->dsPath, $source->filePath, $source->fileName),
            $source->fileName,
        );

        // Jiná cesta (adresář cílové tabulky) a jiný název (nový hash).
        $this->assertStringContainsString('base_registry_documents', $info->filePath);
        $this->assertNotSame($source->fileName, $info->fileName);

        // Hash se nekumuluje: `smlouva-abcde.pdf` → `smlouva-xyzkq.pdf`.
        $this->assertMatchesRegularExpression('/^smlouva-[a-z0-9]{5}\.pdf$/', $info->fileName);

        $targetPath = $this->storage->getFullPath($this->dsPath, $info->filePath, $info->fileName);
        $this->assertFileExists($targetPath);
        $this->assertSame('PDF content here', file_get_contents($targetPath));
    }

    public function testCopyKeepsChecksumAndSource(): void
    {
        $source = $this->storeSourceFile('revize.pdf', 'same bytes');
        $sourcePath = $this->storage->getFullPath($this->dsPath, $source->filePath, $source->fileName);

        $info = $this->storage->copy($this->dsPath, 'base_registry_documents', $sourcePath, $source->fileName);

        $this->assertSame($source->checksum, $info->checksum);
        $this->assertSame($source->fileSize, $info->fileSize);

        // Zdroj nedotčen (D8 — kopie, ne přesun).
        $this->assertFileExists($sourcePath);
        $this->assertSame('same bytes', file_get_contents($sourcePath));
    }

    public function testCopyMissingSourceThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source attachment file not found');

        $this->storage->copy(
            $this->dsPath,
            'base_registry_documents',
            $this->dsPath . '/att/nonexistent.pdf',
            'nonexistent.pdf',
        );
    }

    // --- AttachmentService::copyTo ---------------------------------------------

    public function testCopyToInsertsNewRowAndKeepsSourceRow(): void
    {
        $source = $this->storeSourceFile('faktura.pdf', 'invoice bytes');
        $sourceRow = $this->sourceRow($source);

        $inserted = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($sourceRow) {
                if (str_contains($sql, 'WHERE id = %i') && !str_contains($sql, 'checksum')) {
                    // getAttachment / recordExists
                    return str_contains($sql, 'SELECT * FROM')
                        ? $sourceRow
                        : ['id' => $params[1] ?? 1];
                }
                return null; // findDuplicate → žádný duplikát
            },
        );
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$inserted): int {
                $inserted = $data;
                return 555;
            },
        );

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());
        $result = $service->copyTo(10, 428, 77, userId: 3);

        $this->assertTrue($result['success']);
        $this->assertSame(555, $result['data']['id']);
        $this->assertArrayNotHasKey('warning', $result);

        // Nový řádek: cílová vazba, zachované name/att_order/metadata/mime,
        // shodný checksum, nový fyzický soubor.
        $this->assertSame(428, $inserted['table_id']);
        $this->assertSame(77, $inserted['record_id']);
        $this->assertSame('faktura.pdf', $inserted['name']);
        $this->assertSame(2, $inserted['att_order']);
        $this->assertSame('{"pages":3}', $inserted['metadata']);
        $this->assertSame('application/pdf', $inserted['mime_type']);
        $this->assertSame($source->checksum, $inserted['checksum']);
        $this->assertSame(3, $inserted['created_by']);
        $this->assertNotSame($source->fileName, $inserted['file_name']);
        $this->assertFileExists(
            $this->storage->getFullPath($this->dsPath, $inserted['file_path'], $inserted['file_name']),
        );

        // Zdrojový soubor nedotčen.
        $this->assertFileExists(
            $this->storage->getFullPath($this->dsPath, $source->filePath, $source->fileName),
        );
    }

    public function testCopyToDuplicateChecksumWarnsButSucceeds(): void
    {
        $source = $this->storeSourceFile('duplikat.pdf', 'dup bytes');
        $sourceRow = $this->sourceRow($source);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($sourceRow) {
                if (str_contains($sql, 'checksum')) {
                    return ['id' => 42]; // duplikát u cílového záznamu
                }
                return str_contains($sql, 'SELECT * FROM')
                    ? $sourceRow
                    : ['id' => $params[1] ?? 1];
            },
        );
        $db->method('insertRow')->willReturn(556);

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());
        $result = $service->copyTo(10, 428, 77);

        $this->assertTrue($result['success']);
        $this->assertSame('DUPLICATE_CHECKSUM', $result['warning']['code']);
        $this->assertSame(42, $result['warning']['existing_attachment_id']);
    }

    public function testCopyToMissingSourceAttachmentFails(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());
        $result = $service->copyTo(999, 428, 77);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('999', $result['error']);
    }

    public function testCopyToSoftDeletedSourceFails(): void
    {
        $source = $this->storeSourceFile('smazany.pdf', 'x');
        $sourceRow = $this->sourceRow($source);
        $sourceRow['is_deleted'] = 1;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($sourceRow);

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());
        $result = $service->copyTo(10, 428, 77);

        $this->assertFalse($result['success']);
    }

    public function testCopyToMissingPhysicalFileThrowsWithoutDbWrite(): void
    {
        $source = $this->storeSourceFile('ztraceny.pdf', 'bytes');
        $sourceRow = $this->sourceRow($source);
        // Fyzický soubor zmizel (např. poškozený DS)
        unlink($this->storage->getFullPath($this->dsPath, $source->filePath, $source->fileName));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            fn(string $sql, ...$params) => str_contains($sql, 'SELECT * FROM')
                ? $sourceRow
                : ['id' => $params[1] ?? 1],
        );
        $db->expects($this->never())->method('insertRow');

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());

        $this->expectException(\RuntimeException::class);
        $service->copyTo(10, 428, 77);
    }

    public function testCopyToInsertFailureUnlinksCopiedFile(): void
    {
        $source = $this->storeSourceFile('rollback.pdf', 'rollback bytes');
        $sourceRow = $this->sourceRow($source);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$params) use ($sourceRow) {
                if (str_contains($sql, 'checksum')) {
                    return null;
                }
                return str_contains($sql, 'SELECT * FROM')
                    ? $sourceRow
                    : ['id' => $params[1] ?? 1];
            },
        );
        $db->method('insertRow')->willThrowException(new \RuntimeException('DB down'));

        $service = new AttachmentService($db, $this->dsPath, $this->tableDefinitions());

        try {
            $service->copyTo(10, 428, 77);
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
            // expected
        }

        // Zkopírovaný soubor nesmí zůstat jako orphan — cílový adresář je
        // prázdný (kromě zdroje v jiném adresáři).
        $targetDir = $this->dsPath . '/att/' . date('Y/m/d') . '/base_registry_documents';
        $leftover = is_dir($targetDir) ? array_diff(scandir($targetDir), ['.', '..']) : [];
        $this->assertSame([], array_values($leftover));
    }

    // --- helpers ----------------------------------------------------------------

    private function storeSourceFile(string $name, string $content): \Shipard\Module\Core\Attachments\FileInfo
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd_src_');
        file_put_contents($tmp, $content);
        return $this->storage->store($this->dsPath, 'core_mail_incoming_messages', $name, $tmp);
    }

    /** @return array<string, mixed> */
    private function sourceRow(\Shipard\Module\Core\Attachments\FileInfo $info): array
    {
        return [
            'id'         => 10,
            'table_id'   => 303,
            'record_id'  => 5,
            'name'       => preg_replace('/-[a-z0-9]{5}(\.[a-z0-9]+)$/', '$1', $info->fileName),
            'file_name'  => $info->fileName,
            'file_path'  => $info->filePath,
            'file_size'  => $info->fileSize,
            'mime_type'  => 'application/pdf',
            'checksum'   => $info->checksum,
            'metadata'   => '{"pages":3}',
            'att_order'  => 2,
            'is_deleted' => 0,
        ];
    }

    /** @return array<string, TableDefinition> */
    private function tableDefinitions(): array
    {
        $minimal = static fn(int $tableId, string $name): TableDefinition => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name'    => $name,
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ],
        ]);

        return [
            'core_mail_incoming_messages' => $minimal(303, 'Incoming messages'),
            'base_registry_documents'     => $minimal(428, 'Registry documents'),
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
