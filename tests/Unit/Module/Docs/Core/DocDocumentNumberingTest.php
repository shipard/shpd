<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentNumberingTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_num_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.docTypes' => [
                'invno' => ['doc_id_code' => '1', 'trade_dir' => 1],
                'invni' => ['doc_id_code' => '2', 'trade_dir' => 2],
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    // ── resolvePattern ─────────────────────────────────────────────────────

    public function testResolvePatternAllPlaceholders(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'sequence_number' => 42, 'accounting_date' => '2026-05-06'];
        $series = ['doc_number_code' => 'A'];

        $this->assertSame('1A',     $doc->resolvePatternPub('%D%C', $data, $series));
        $this->assertSame('26',     $doc->resolvePatternPub('%y', $data, $series));
        $this->assertSame('2026',   $doc->resolvePatternPub('%Y', $data, $series));
        $this->assertSame('042',    $doc->resolvePatternPub('%3', $data, $series));
        $this->assertSame('0042',   $doc->resolvePatternPub('%4', $data, $series));
        $this->assertSame('00042',  $doc->resolvePatternPub('%5', $data, $series));
        $this->assertSame('000042', $doc->resolvePatternPub('%6', $data, $series));
    }

    public function testResolvePatternComposite(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'sequence_number' => 1, 'accounting_date' => '2026-05-06'];
        $series = ['doc_number_code' => 'A', 'doc_number_pattern' => '%D%y%C%4'];

        $this->assertSame('126A0001', $doc->resolvePatternPub('%D%y%C%4', $data, $series));
    }

    public function testResolvePatternUnknownPlaceholderUntouched(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invno', 'sequence_number' => 1, 'accounting_date' => '2026-05-06'];
        $series = [];
        // %X is not in the recognized list — preserved as literal
        $this->assertSame('FX1', $doc->resolvePatternPub('FX%D', $data, $series));
    }

    public function testResolvePatternFiscalYearFromAccountingDateWhenNoFiscalYear(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setConfig($this->buildConfig());

        $data = ['doc_type' => 'invni', 'sequence_number' => 7, 'accounting_date' => '2025-03-15'];
        $series = ['doc_number_code' => ''];

        $this->assertSame('225', $doc->resolvePatternPub('%D%y', $data, $series));
        $this->assertSame('2025', $doc->resolvePatternPub('%Y', $data, $series));
    }

    // ── assignDocumentNumber ────────────────────────────────────────────────

    public function testAssignDocumentNumberAssignsSequentialNumber(): void
    {
        // Mock: number_series row, counter row at last_assigned=0, then update.
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                if ($callCount === 1) {
                    // SELECT * FROM docs_core_number_series
                    return new Row([
                        'id' => 1, 'doc_type' => 'invno', 'doc_number_code' => 'A',
                        'doc_number_pattern' => '%D%y%C%4', 'reset_scope' => 'fiscal_year',
                    ]);
                }
                if ($callCount === 2) {
                    // resolveFiscalYearId
                    return new Row(['id' => 100]);
                }
                if ($callCount === 3) {
                    // SELECT last_assigned … FOR UPDATE
                    return new Row(['last_assigned' => 0]);
                }
                if ($callCount === 4) {
                    // getFiscalYearLabel — fiscal_years lookup
                    return new Row(['doc_number_prefix' => '26', 'name' => '2026']);
                }
                return null;
            }
        );
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'number_series'   => 1,
            'doc_type'        => 'invno',
            'accounting_date' => '2026-05-06',
        ];
        $doc->assignDocumentNumberPub($data);

        $this->assertSame(1, $data['sequence_number']);
        $this->assertSame(100, $data['fiscal_year']);
        $this->assertSame('126A0001', $data['doc_number']);
    }

    public function testAssignDocumentNumberThrowsOnMissingSeriesId(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->createMock(Connection::class));
        $doc->setConfig($this->buildConfig());

        $data = ['accounting_date' => '2026-05-06'];

        $this->expectException(\LogicException::class);
        $doc->assignDocumentNumberPub($data);
    }

    public function testAssignDocumentNumberThrowsOnSeriesNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['number_series' => 99, 'accounting_date' => '2026-05-06'];

        $this->expectException(\LogicException::class);
        $doc->assignDocumentNumberPub($data);
    }

    // ── data-save bez docState — regresní testy falešného release ──────────

    public function testDataSaveWithoutDocStateNeverTouchesNumber(): void
    {
        // Release i assign čtou z DB (max_seq / series) — když se fetch nikdy
        // nezavolá, na číslo dokladu se prokazatelně nesáhlo.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        // Data-save z formuláře: docState je system sloupec, v payloadu chybí.
        // Doklad je v Potvrzeno (20) s přiděleným číslem.
        $data = ['id' => 42, 'sequence_number' => 5, 'doc_number' => '126A0005'];
        $original = [
            'docState' => 20, 'number_series' => 1, 'fiscal_year' => 100,
            'sequence_number' => 5, 'doc_number' => '126A0005',
        ];

        $doc->trackStateChangePub($data, $original);
        $doc->processStateTransitionPub($data, $original);

        $this->assertSame(5, $data['sequence_number']);
        $this->assertSame('126A0005', $data['doc_number']);
        $this->assertArrayNotHasKey('supplier_snapshot', $data);
        $this->assertSame([], $doc->executedSql); // počítadlo nedekrementováno
    }

    public function testDataSaveWithInjectedUnchangedDocStateNeverTouchesNumber(): void
    {
        // Gateway injektuje efektivní stav do payloadu — stav 20 → 20 beze
        // změny pořád nesmí být přechod.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = ['id' => 42, 'docState' => 20, 'sequence_number' => 5, 'doc_number' => '126A0005'];
        $original = [
            'docState' => 20, 'number_series' => 1, 'fiscal_year' => 100,
            'sequence_number' => 5, 'doc_number' => '126A0005',
        ];

        $doc->trackStateChangePub($data, $original);
        $doc->processStateTransitionPub($data, $original);

        $this->assertSame(5, $data['sequence_number']);
        $this->assertSame('126A0005', $data['doc_number']);
        $this->assertSame([], $doc->executedSql);
    }

    // ── releaseDocumentNumber ──────────────────────────────────────────────

    public function testReleaseDocumentNumberLastInSeries(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['max_seq' => 5]));
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $original = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $data = ['id' => 42, 'sequence_number' => 5, 'doc_number' => '126A0005'];

        $doc->releaseDocumentNumberPub($data, $original);

        $this->assertNull($data['sequence_number']);
        $this->assertNull($data['fiscal_year']);
        $this->assertSame('!0000000042', $data['doc_number']);
        $this->assertNull($data['supplier_snapshot']);
        $this->assertNull($data['customer_snapshot']);
    }

    public function testReleaseDocumentNumberRefusesNonLast(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['max_seq' => 7]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $original = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $data = ['id' => 42, 'sequence_number' => 5, 'doc_number' => '126A0005'];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('není poslední v řadě');
        $doc->releaseDocumentNumberPub($data, $original);
    }

    public function testReleaseDocumentNumberWithoutSequenceIsNoop(): void
    {
        $db = $this->createMock(Connection::class);

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $original = ['number_series' => 0, 'sequence_number' => 0];
        $data = ['id' => 42];

        $doc->releaseDocumentNumberPub($data, $original);

        $this->assertNull($data['sequence_number']);
        $this->assertSame('', $data['doc_number']);
    }
}
