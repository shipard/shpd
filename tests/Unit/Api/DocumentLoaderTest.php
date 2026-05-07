<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Module\ModuleDefinition;

class DocumentLoaderTest extends TestCase
{
    public function testMergesTypeColumnRegistrationsFromMultipleModules(): void
    {
        $core = $this->module('docs.core', [
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => 'Shipard\\Module\\Docs\\Core\\DocsHeadsDocument',
            ],
            [
                'table' => 'docs_core_number_series',
                'class' => 'Shipard\\Module\\Docs\\Core\\NumberSeriesDocument',
            ],
        ]);
        $invOut = $this->module('docs.invoicesOut', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => [
                    'invno' => 'Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceDocument',
                ],
            ],
        ]);
        $invIn = $this->module('docs.invoicesIn', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => [
                    'invni' => 'Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceDocument',
                ],
            ],
        ]);

        $merged = DocumentLoader::mergeDocumentClasses([$core, $invOut, $invIn]);

        $byTable = $this->indexByTable($merged);

        $this->assertArrayHasKey('docs_core_heads', $byTable);
        $heads = $byTable['docs_core_heads'];
        $this->assertSame('doc_type', $heads['typeColumn']);
        $this->assertSame(
            'Shipard\\Module\\Docs\\Core\\DocsHeadsDocument',
            $heads['defaultClass'],
        );
        $this->assertSame(
            [
                'invno' => 'Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceDocument',
                'invni' => 'Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceDocument',
            ],
            $heads['classes'],
        );

        $this->assertArrayHasKey('docs_core_number_series', $byTable);
        $this->assertSame(
            'Shipard\\Module\\Docs\\Core\\NumberSeriesDocument',
            $byTable['docs_core_number_series']['class'],
        );
    }

    public function testSimpleClassRegistrationsPassThroughUnchanged(): void
    {
        $modA = $this->module('mod.a', [
            ['table' => 'a_table', 'class' => 'A\\ADocument'],
        ]);
        $modB = $this->module('mod.b', [
            ['table' => 'b_table', 'class' => 'B\\BDocument'],
        ]);

        $merged = DocumentLoader::mergeDocumentClasses([$modA, $modB]);
        $byTable = $this->indexByTable($merged);

        $this->assertSame('A\\ADocument', $byTable['a_table']['class']);
        $this->assertSame('B\\BDocument', $byTable['b_table']['class']);
    }

    public function testThrowsOnConflictingTypeColumn(): void
    {
        $modA = $this->module('mod.a', [
            ['table' => 'shared', 'typeColumn' => 'kind'],
        ]);
        $modB = $this->module('mod.b', [
            ['table' => 'shared', 'typeColumn' => 'doc_type'],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Conflicting typeColumn for table 'shared'");

        DocumentLoader::mergeDocumentClasses([$modA, $modB]);
    }

    public function testThrowsOnDuplicateClassesEntry(): void
    {
        $modA = $this->module('mod.a', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'A\\Doc'],
            ],
        ]);
        $modB = $this->module('mod.b', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'B\\Doc'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate class registration for table 'docs_core_heads'");

        DocumentLoader::mergeDocumentClasses([$modA, $modB]);
    }

    public function testIdenticalDuplicateClassesEntryIsAllowed(): void
    {
        $modA = $this->module('mod.a', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'X\\Doc'],
            ],
        ]);
        $modB = $this->module('mod.b', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'X\\Doc'],
            ],
        ]);

        $merged = DocumentLoader::mergeDocumentClasses([$modA, $modB]);
        $byTable = $this->indexByTable($merged);

        $this->assertSame(['invno' => 'X\\Doc'], $byTable['docs_core_heads']['classes']);
    }

    public function testRegistrationWithoutTableIsSkipped(): void
    {
        $mod = $this->module('mod.a', [
            ['class' => 'A\\Doc'], // no `table` — silently skipped
            ['table' => 'good', 'class' => 'A\\GoodDoc'],
        ]);

        $merged = DocumentLoader::mergeDocumentClasses([$mod]);
        $byTable = $this->indexByTable($merged);

        $this->assertCount(1, $byTable);
        $this->assertSame('A\\GoodDoc', $byTable['good']['class']);
    }

    /** @param list<array<string, mixed>> $documentClasses */
    private function module(string $id, array $documentClasses): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id'              => $id,
            'name'            => $id,
            'documentClasses' => $documentClasses,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $merged
     * @return array<string, array<string, mixed>>
     */
    private function indexByTable(array $merged): array
    {
        $out = [];
        foreach ($merged as $reg) {
            $out[$reg['table']] = $reg;
        }
        return $out;
    }
}
