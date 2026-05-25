<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\FormLoader;
use Shipard\Core\Module\ModuleDefinition;

class FormLoaderTest extends TestCase
{
    public function testMergesTypeColumnRegistrationsFromMultipleModules(): void
    {
        $core = $this->module('docs.core', [
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => 'Shipard\\Module\\Docs\\Core\\DocsHeadsForm',
            ],
            [
                'table' => 'docs_core_number_series',
                'class' => 'Shipard\\Module\\Docs\\Core\\NumberSeriesForm',
            ],
        ]);
        $invOut = $this->module('docs.invoicesOut', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => [
                    'invno' => 'Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm',
                ],
            ],
        ]);
        $invIn = $this->module('docs.invoicesIn', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => [
                    'invni' => 'Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceForm',
                ],
            ],
        ]);

        $merged = FormLoader::mergeForms([$core, $invOut, $invIn]);
        $byTable = $this->indexByTable($merged);

        $this->assertArrayHasKey('docs_core_heads', $byTable);
        $heads = $byTable['docs_core_heads'];
        $this->assertSame('doc_type', $heads['typeColumn']);
        $this->assertSame(
            'Shipard\\Module\\Docs\\Core\\DocsHeadsForm',
            $heads['defaultClass'],
        );
        $this->assertSame(
            [
                'invno' => 'Shipard\\Module\\Docs\\InvoicesOut\\IssuedInvoiceForm',
                'invni' => 'Shipard\\Module\\Docs\\InvoicesIn\\ReceivedInvoiceForm',
            ],
            $heads['classes'],
        );

        $this->assertArrayHasKey('docs_core_number_series', $byTable);
        $this->assertSame(
            'Shipard\\Module\\Docs\\Core\\NumberSeriesForm',
            $byTable['docs_core_number_series']['class'],
        );
    }

    public function testSimpleClassRegistrationsPassThroughUnchanged(): void
    {
        $modA = $this->module('mod.a', [
            ['table' => 'a_table', 'class' => 'A\\AForm'],
        ]);
        $modB = $this->module('mod.b', [
            ['table' => 'b_table', 'class' => 'B\\BForm'],
        ]);

        $merged = FormLoader::mergeForms([$modA, $modB]);
        $byTable = $this->indexByTable($merged);

        $this->assertSame('A\\AForm', $byTable['a_table']['class']);
        $this->assertSame('B\\BForm', $byTable['b_table']['class']);
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

        FormLoader::mergeForms([$modA, $modB]);
    }

    public function testThrowsOnDuplicateClassesEntry(): void
    {
        $modA = $this->module('mod.a', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'A\\Form'],
            ],
        ]);
        $modB = $this->module('mod.b', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'B\\Form'],
            ],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate form class registration for table 'docs_core_heads'");

        FormLoader::mergeForms([$modA, $modB]);
    }

    public function testIdenticalDuplicateClassesEntryIsAllowed(): void
    {
        $modA = $this->module('mod.a', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'X\\Form'],
            ],
        ]);
        $modB = $this->module('mod.b', [
            [
                'table'      => 'docs_core_heads',
                'typeColumn' => 'doc_type',
                'classes'    => ['invno' => 'X\\Form'],
            ],
        ]);

        $merged = FormLoader::mergeForms([$modA, $modB]);
        $byTable = $this->indexByTable($merged);

        $this->assertSame(['invno' => 'X\\Form'], $byTable['docs_core_heads']['classes']);
    }

    public function testRegistrationWithoutTableIsSkipped(): void
    {
        $mod = $this->module('mod.a', [
            ['class' => 'A\\Form'], // no `table` — silently skipped
            ['table' => 'good', 'class' => 'A\\GoodForm'],
        ]);

        $merged = FormLoader::mergeForms([$mod]);
        $byTable = $this->indexByTable($merged);

        $this->assertCount(1, $byTable);
        $this->assertSame('A\\GoodForm', $byTable['good']['class']);
    }

    public function testIdFieldIsPreserved(): void
    {
        $mod = $this->module('mod.a', [
            ['table' => 'sub_t', 'id' => 'mod.a.sub', 'class' => 'A\\SubForm'],
        ]);

        $merged = FormLoader::mergeForms([$mod]);
        $byTable = $this->indexByTable($merged);

        $this->assertSame('mod.a.sub', $byTable['sub_t']['id']);
        $this->assertSame('A\\SubForm', $byTable['sub_t']['class']);
    }

    /** @param list<array<string, mixed>> $forms */
    private function module(string $id, array $forms): ModuleDefinition
    {
        return ModuleDefinition::fromArray([
            'id'    => $id,
            'name'  => $id,
            'forms' => $forms,
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
