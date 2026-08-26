<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Dataset\DatasetDumper;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\ExportedFile;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Export\SetupExporter;

class DatasetDumperTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_dump_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /**
     * @param list<ExportedRecord> $records
     * @param list<string>         $warnings
     */
    private function fakeExporter(string $section, array $records, array $warnings = []): RecordExporter
    {
        return new class($section, $records, $warnings) implements RecordExporter {
            public function __construct(private string $s, private array $r, private array $w) {}
            public function section(): string { return $this->s; }
            public function exportAll(): array { return $this->r; }
            public function exportByIds(array $ids): array { return $this->r; }
            public function getWarnings(): array { return $this->w; }
        };
    }

    private function manifest(): DatasetManifest
    {
        return new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z');
    }

    public function testWritesRecordsSidecarsSetupAndManifestCounts(): void
    {
        $pdf = $this->tmp . '/src.pdf';
        file_put_contents($pdf, '%PDF');

        $writer = DatasetWriter::create($this->tmp . '/set');
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([]);
        $bindersDef = TableDefinition::fromArray(JsoncParser::parseFile(
            dirname(__DIR__, 6) . '/modules/base/registry/tables/base_registry_binders.jsonc',
        ));
        $setup = new SetupExporter($db, ['base_registry_binders' => $bindersDef]);
        $exporters = [
            $this->fakeExporter('persons', [
                new ExportedRecord(7, 'Žlutý kůň s.r.o.', ['format' => 'shpd.persons.person']),
                new ExportedRecord(8, 'Acme', ['format' => 'shpd.persons.person']),
            ]),
            $this->fakeExporter('registry', [
                new ExportedRecord(30, 'Smlouva', ['format' => 'x', 'attachments' => [['file' => 'smlouva.pdf']]], [
                    new ExportedFile($pdf, 'smlouva.pdf', 71),
                ]),
            ], ['registry Smlouva: něco se ztratilo']),
            $this->fakeExporter('mail', []),
        ];

        $result = (new DatasetDumper($writer))->dump($this->manifest(), $setup, $exporters);

        $this->assertSame(['setup' => 1, 'persons' => 2, 'registry' => 1, 'mail' => 0], $result->counts);
        $this->assertSame(['registry Smlouva: něco se ztratilo'], $result->warnings);
        $this->assertSame($result->counts, $result->manifest->counts);

        $this->assertFileExists($this->tmp . '/set/setup/binders.jsonc');
        $this->assertFileExists($this->tmp . '/set/persons/0001-zluty-kun-s-r-o.jsonc');
        $this->assertFileExists($this->tmp . '/set/persons/0002-acme.jsonc');
        $this->assertFileExists($this->tmp . '/set/registry/0001-smlouva.jsonc');
        $this->assertSame('%PDF', file_get_contents($this->tmp . '/set/registry/0001-smlouva.files/smlouva.pdf'));
        $this->assertDirectoryDoesNotExist($this->tmp . '/set/mail');

        $reader = DatasetReader::open($this->tmp . '/set');
        $this->assertSame(['setup' => 1, 'persons' => 2, 'registry' => 1, 'mail' => 0], $reader->getManifest()->counts);
        $this->assertSame('demo', $reader->getManifest()->name);
    }

    public function testMissingAttachmentSourceIsWarningNotFailure(): void
    {
        $writer = DatasetWriter::create($this->tmp . '/set');
        $exporters = [
            $this->fakeExporter('mail', [
                new ExportedRecord(1, 'MSG-1', ['format' => 'x'], [new ExportedFile($this->tmp . '/nope.pdf', 'a.pdf', 5)]),
            ]),
        ];

        $result = (new DatasetDumper($writer))->dump($this->manifest(), null, $exporters);

        $this->assertSame(['mail' => 1], $result->counts);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString("příloha 'a.pdf' chybí na disku", $result->warnings[0]);
        $this->assertFileExists($this->tmp . '/set/mail/0001-msg-1.jsonc');
        $this->assertDirectoryDoesNotExist($this->tmp . '/set/mail/0001-msg-1.files');
        $this->assertArrayNotHasKey('setup', $result->counts);
    }
}
