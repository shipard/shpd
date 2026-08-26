<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;

class DatasetWriterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_dsw_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
        @unlink($this->tmp . '.zip');
    }

    public function testSlugTransliteratesAndNormalizes(): void
    {
        $this->assertSame('faktura-prijata-2026-0001', DatasetWriter::slug('Faktura přijatá 2026/0001'));
        $this->assertSame('zluty-kun-s-r-o', DatasetWriter::slug('  Žlutý kůň, s. r. o. '));
        $this->assertSame('record', DatasetWriter::slug('***'));
        $this->assertSame('record', DatasetWriter::slug(''));
    }

    public function testSlugIsCappedAt60Chars(): void
    {
        $slug = DatasetWriter::slug(str_repeat('abc ', 40));

        $this->assertLessThanOrEqual(60, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testFileNameZeroPadsOrdinal(): void
    {
        $this->assertSame('0007-fv-2026-0007.jsonc', DatasetWriter::fileName(7, 'FV 2026/0007'));
        $this->assertSame('0123-x.pdf', DatasetWriter::fileName(123, 'x', '.pdf'));
    }

    public function testEncodeIsPrettyUnescapedWithTrailingNewline(): void
    {
        $json = DatasetWriter::encode(['a' => 'Žluť/1', 'b' => 1.0, 'c' => []]);

        $this->assertStringEndsWith("\n", $json);
        $this->assertStringContainsString('"a": "Žluť/1"', $json);
        $this->assertStringContainsString('"b": 1.0', $json);
        $this->assertStringContainsString('"c": []', $json);
    }

    public function testCreateWritesFilesAndManifest(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->writeJsonc('persons/' . DatasetWriter::fileName(1, 'Acme'), ['format' => 'shpd.persons.person']);
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['persons' => 1]));

        $this->assertFileExists($this->tmp . '/persons/0001-acme.jsonc');
        $this->assertFileExists($this->tmp . '/manifest.jsonc');

        $reader = DatasetReader::open($this->tmp);
        $this->assertSame('demo', $reader->getManifest()->name);
        $this->assertSame(['persons' => 1], $reader->getManifest()->counts);
        $this->assertSame(['persons/0001-acme.jsonc'], $reader->listFiles('persons'));
    }

    public function testCreateRefusesNonEmptyDirWithoutOverwrite(): void
    {
        mkdir($this->tmp, 0755, true);
        file_put_contents($this->tmp . '/manifest.jsonc', '{}');

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('not empty');
        DatasetWriter::create($this->tmp);
    }

    public function testOverwriteRemovesOnlyDatasetContent(): void
    {
        mkdir($this->tmp . '/persons', 0755, true);
        file_put_contents($this->tmp . '/persons/stale.jsonc', '{}');
        file_put_contents($this->tmp . '/manifest.jsonc', '{}');
        file_put_contents($this->tmp . '/README.md', 'keep me');

        DatasetWriter::create($this->tmp, overwrite: true);

        $this->assertFileDoesNotExist($this->tmp . '/persons/stale.jsonc');
        $this->assertFileDoesNotExist($this->tmp . '/manifest.jsonc');
        $this->assertFileExists($this->tmp . '/README.md');
    }

    public function testCopyFileAndPathTraversalGuard(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $src = $this->tmp . '/src.bin';
        file_put_contents($src, 'PDF');
        $w->copyFile($src, 'mail/attachments/0001-a.pdf');

        $this->assertSame('PDF', file_get_contents($this->tmp . '/mail/attachments/0001-a.pdf'));

        $this->expectException(DatasetException::class);
        $w->writeRaw('../escape.txt', 'x');
    }

    public function testZipContainsSortedEntriesReadableByReader(): void
    {
        $w = DatasetWriter::create($this->tmp);
        $w->writeJsonc('persons/0002-b.jsonc', ['n' => 2]);
        $w->writeJsonc('persons/0001-a.jsonc', ['n' => 1]);
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z'));
        $zipPath = $this->tmp . '.zip';
        $w->zip($zipPath);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertSame(['manifest.jsonc', 'persons/0001-a.jsonc', 'persons/0002-b.jsonc'], $names);

        $reader = DatasetReader::open($zipPath);
        $this->assertSame(['n' => 1], $reader->readJsonc('persons/0001-a.jsonc'));
        $reader->close();
    }
}
