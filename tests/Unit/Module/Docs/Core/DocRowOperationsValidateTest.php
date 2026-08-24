<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\DocRowsDocument;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Zapojení pravidel pohybu do validate hooků:
 *   - DocRowsDocument::validate — uložení řádku přes sub-form
 *   - DocDocument::validate — záchytná síť při přechodu do stavu 40
 */
class DocRowOperationsValidateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_rowopsval_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.rowOperations' => [
                'sale.services' => ['name' => 'Prodej služeb', 'docTypes' => ['invno' => ['order' => 100]]],
                'acc.entry'     => ['name' => 'Účetní položka', 'docTypes' => [
                    'invno' => ['order' => 900], 'invni' => ['order' => 900],
                ]],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    // ── DocRowsDocument (sub-form save) ─────────────────────────────────────

    private function rowsDoc(): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['doc_type' => 'invno']));

        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    public function testRowSaveValidOperationPasses(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'sale.services'];
        $this->assertTrue($this->rowsDoc()->validate($data)->isValid());
    }

    public function testRowSaveMissingOperationFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('operation', $result->getErrors()[0]->column);
    }

    public function testRowSaveOperationNotAllowedForDocTypeFails(): void
    {
        // purchase pohyb na vydané faktuře (head je invno)
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'purchase.services'];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
    }

    public function testRowSaveAccEntryWithoutItemFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'acc.entry'];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('item', $result->getErrors()[0]->column);
    }

    public function testRowSaveTextRowWithOperationFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 0, 'operation' => 'sale.services'];
        $this->assertFalse($this->rowsDoc()->validate($data)->isValid());
    }

    public function testRowSaveWithoutConfigSkipsDegradedly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['doc_type' => 'invno']));
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        // bez setConfig — validace se přeskočí, neblokuje
        $data = ['doc_head' => 5, 'row_kind' => 1];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── DocDocument (přechod do 40) ─────────────────────────────────────────

    private function headDoc(): TestableDocsHeadsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            if (str_contains($sql, 'docs_core_number_series')) {
                return new Row(['doc_type' => 'invno']);
            }
            return new Row(['id' => 1]); // own company
        });

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    /** @return array<string, mixed> */
    private function state40Data(array $rows): array
    {
        return [
            'docState'         => 40,
            'number_series'    => 1,
            'doc_type'         => 'invno',
            'issue_date'       => '2026-06-10',
            'accounting_date'  => '2026-06-10',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'rows'             => $rows,
        ];
    }

    public function testState40AllRowsValidPasses(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100],
            ['row_kind' => 0],
        ]);
        $this->assertTrue($this->headDoc()->validate($data)->isValid());
    }

    public function testState40RowWithoutOperationFailsWithRowsIndexConvention(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100],
            ['row_kind' => 1, 'total_price' => 50],
        ]);
        $result = $this->headDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('rows.1.operation', $result->getErrors()[0]->column);
    }

    public function testState80DoesNotEnforceOperations(): void
    {
        // Pohyby řádků se vynucují až při přechodu do 40 — V opravě (80) ne.
        $data = $this->state40Data([['row_kind' => 1, 'total_price' => 100]]);
        $data['docState'] = 80;

        $this->assertTrue($this->headDoc()->validate($data)->isValid());
    }
}
