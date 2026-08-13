<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentOrchestrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_orch_test_' . uniqid();
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
            ],
        ];
        $data = ['_meta' => ['language' => 'cs'], 'items' => $items];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode($data),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    public function testProcessStateTransitionInsertNoOp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        // No DB, no config — insert with no original data, default 10→10
        $data = ['docState' => 10];
        $doc->trackStateChangePub($data, null);
        $doc->processStateTransitionPub($data, null);

        // No exception thrown — just no-op
        $this->assertSame(10, $data['docState']);
    }

    public function testProcessStateTransition10To20InvokesAssignNumber(): void
    {
        $db = $this->createMock(Connection::class);
        $callCount = 0;
        $db->method('fetch')->willReturnCallback(
            function () use (&$callCount): ?Row {
                $callCount++;
                return match ($callCount) {
                    1 => new Row([
                        'id' => 1, 'doc_type' => 'invno', 'doc_number_code' => 'A',
                        'doc_number_pattern' => '%D%y%C%4', 'reset_scope' => 'fiscal_year',
                    ]),
                    2 => new Row(['id' => 100]),       // resolveFiscalYearId
                    3 => new Row(['last_assigned' => 5]), // SELECT last_assigned FOR UPDATE
                    4 => new Row(['doc_number_prefix' => '26', 'name' => '2026']),
                    default => null,
                };
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = [
            'docState' => 20,
            'number_series'   => 1,
            'doc_type'        => 'invno',
            'accounting_date' => '2026-05-06',
        ];
        $doc->trackStateChangePub($data, ['docState' => 10]);
        $doc->processStateTransitionPub($data, ['docState' => 10]);

        $this->assertSame(6, $data['sequence_number']);
        $this->assertSame('126A0006', $data['doc_number']);
    }

    public function testProcessStateTransition20To10ReleasesNumberWhenLast(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['max_seq' => 5]));

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = ['id' => 42, 'docState' => 10, 'sequence_number' => 5, 'doc_number' => '126A0005'];
        $original = ['docState' => 20, 'number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $doc->trackStateChangePub($data, $original);
        $doc->processStateTransitionPub($data, $original);

        $this->assertNull($data['sequence_number']);
        $this->assertSame('!0000000042', $data['doc_number']);
    }

    public function testProcessStateTransition20To40NoOp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 40, 'sequence_number' => 5, 'doc_number' => '126A0005'];
        $doc->trackStateChangePub($data, ['docState' => 20]);
        $doc->processStateTransitionPub($data, ['docState' => 20]);

        // No changes — Confirmed → Done is just a state flag
        $this->assertSame(5, $data['sequence_number']);
        $this->assertSame('126A0005', $data['doc_number']);
    }

    public function testProcessStateTransition40To30StornoNoOp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['docState' => 30, 'sequence_number' => 5];
        $doc->trackStateChangePub($data, ['docState' => 40]);
        $doc->processStateTransitionPub($data, ['docState' => 40]);

        $this->assertSame(5, $data['sequence_number']);
    }
}
