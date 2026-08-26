<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

class DatasetReaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_dsr_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/set/persons', 0755, true);
        mkdir($this->tmp . '/set/registry/0001-smlouva.files', 0755, true);
        file_put_contents($this->tmp . '/set/manifest.jsonc', <<<'JSONC'
            {
              // komentář je v JSONC povolený
              "format": "shpd.dataset.v1",
              "name": "demo",
              "title": "Demo",
              "dateMode": "fixed",
              "created": "2026-08-26T10:00:00Z",
              "counts": { "persons": 2, },
            }
            JSONC);
        file_put_contents($this->tmp . '/set/persons/0002-b.jsonc', '{"n": 2}');
        file_put_contents($this->tmp . '/set/persons/0001-a.jsonc', '{"n": 1}');
        file_put_contents($this->tmp . '/set/persons/notes.txt', 'ignored');
        file_put_contents($this->tmp . '/set/registry/0001-smlouva.jsonc', '{}');
        file_put_contents($this->tmp . '/set/registry/0001-smlouva.files/smlouva.pdf', 'PDF');
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    public function testOpenDirectoryReadsManifestWithJsoncSyntax(): void
    {
        $r = DatasetReader::open($this->tmp . '/set');

        $this->assertSame('demo', $r->getManifest()->name);
        $this->assertSame(['persons' => 2], $r->getManifest()->counts);
        $this->assertSame($this->tmp . '/set', $r->getRootDir());
    }

    public function testListFilesIsSortedFilteredAndRelative(): void
    {
        $r = DatasetReader::open($this->tmp . '/set');

        $this->assertSame(['persons/0001-a.jsonc', 'persons/0002-b.jsonc'], $r->listFiles('persons'));
        $this->assertSame([], $r->listFiles('items'), 'missing section directory yields empty list');
        $this->assertSame(
            ['registry/0001-smlouva.files/smlouva.pdf'],
            $r->listFiles('registry/0001-smlouva.files', ''),
        );
        $this->assertSame(['registry/0001-smlouva.files'], $r->listDirs('registry'));
    }

    public function testReadJsoncAndFileExists(): void
    {
        $r = DatasetReader::open($this->tmp . '/set');

        $this->assertSame(['n' => 1], $r->readJsonc('persons/0001-a.jsonc'));
        $this->assertTrue($r->fileExists('registry/0001-smlouva.files/smlouva.pdf'));
        $this->assertFalse($r->fileExists('registry/none.pdf'));
    }

    public function testReadJsoncRejectsNonObjectTopLevel(): void
    {
        file_put_contents($this->tmp . '/set/persons/0003-c.jsonc', '"string"');
        $r = DatasetReader::open($this->tmp . '/set');

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('top level must be a JSON object');
        $r->readJsonc('persons/0003-c.jsonc');
    }

    public function testPathTraversalIsRejected(): void
    {
        $r = DatasetReader::open($this->tmp . '/set');

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('invalid relative path');
        $r->resolvePath('persons/../../etc/passwd', mustExist: false);
    }

    public function testAbsolutePathIsRejected(): void
    {
        $this->expectException(DatasetException::class);
        DatasetReader::normalizeRelative('/etc/passwd');
    }

    public function testNormalizeRelativeStripsDotSegments(): void
    {
        $this->assertSame('persons/a.jsonc', DatasetReader::normalizeRelative('./persons//a.jsonc'));
    }

    public function testMissingManifestInDirectory(): void
    {
        mkdir($this->tmp . '/empty');

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('manifest.jsonc');
        DatasetReader::open($this->tmp . '/empty');
    }

    public function testNonexistentPath(): void
    {
        $this->expectException(DatasetException::class);
        DatasetReader::open($this->tmp . '/nope');
    }

    public function testZipWithFlatRoot(): void
    {
        $zipPath = $this->tmp . '/flat.zip';
        $this->zipDir($this->tmp . '/set', $zipPath, prefix: '');

        $r = DatasetReader::open($zipPath);
        $root = $r->getRootDir();
        $this->assertDirectoryExists($root);
        $this->assertSame('demo', $r->getManifest()->name);
        $this->assertSame(['persons/0001-a.jsonc', 'persons/0002-b.jsonc'], $r->listFiles('persons'));
        $this->assertSame('PDF', file_get_contents($r->resolvePath('registry/0001-smlouva.files/smlouva.pdf')));

        $r->close();
        $this->assertDirectoryDoesNotExist($root, 'temp extraction dir is removed on close');
    }

    public function testZipWithSingleTopLevelFolder(): void
    {
        $zipPath = $this->tmp . '/nested.zip';
        $this->zipDir($this->tmp . '/set', $zipPath, prefix: 'web-demo/');

        $r = DatasetReader::open($zipPath);
        $this->assertStringEndsWith('/web-demo', $r->getRootDir());
        $this->assertSame('demo', $r->getManifest()->name);
        $r->close();
    }

    public function testZipWithoutManifestIsRejected(): void
    {
        $zipPath = $this->tmp . '/bad.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('persons/0001-a.jsonc', '{}');
        $zip->close();

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('no manifest.jsonc');
        DatasetReader::open($zipPath);
    }

    public function testZipWithTraversalEntryIsRejected(): void
    {
        $zipPath = $this->tmp . '/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.jsonc', '{}');
        $zip->addFromString('../evil.txt', 'x');
        $zip->close();

        $this->expectException(DatasetException::class);
        $this->expectExceptionMessage('unsafe entry name');
        DatasetReader::open($zipPath);
    }

    private function zipDir(string $dir, string $zipPath, string $prefix): void
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), $prefix . substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        $zip->close();
    }
}
