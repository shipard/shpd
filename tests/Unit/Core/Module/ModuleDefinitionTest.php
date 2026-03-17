<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;

class ModuleDefinitionTest extends TestCase
{
    public function testFromArrayValid(): void
    {
        $data = [
            'id' => 'economy.docs',
            'name' => 'Documents',
            'description' => 'Invoices and orders',
            'dependencies' => ['core.system', 'base.persons'],
            'tables' => ['economy_docs_heads', 'economy_docs_rows'],
            'extensions' => [['table' => 'base_persons_contacts', 'file' => 'extensions/ext.jsonc']],
            'config' => [['id' => 'economy.docs.vatRates', 'file' => 'config/vatRates.jsonc']],
        ];

        $def = ModuleDefinition::fromArray($data);

        $this->assertSame('economy.docs', $def->id);
        $this->assertSame('Documents', $def->name);
        $this->assertSame('Invoices and orders', $def->description);
        $this->assertSame(['core.system', 'base.persons'], $def->dependencies);
        $this->assertSame(['economy_docs_heads', 'economy_docs_rows'], $def->tables);
        $this->assertSame([['table' => 'base_persons_contacts', 'file' => 'extensions/ext.jsonc']], $def->extensions);
        $this->assertSame([['id' => 'economy.docs.vatRates', 'file' => 'config/vatRates.jsonc']], $def->config);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['name' => 'Test']);
    }

    public function testEmptyIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => '', 'name' => 'Test']);
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'core.system']);
    }

    public function testInvalidIdFormatNoDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'nodot', 'name' => 'Test']);
    }

    public function testInvalidIdFormatUppercaseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'A.b', 'name' => 'Test']);
    }

    public function testOptionalFieldsDefault(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'core.system', 'name' => 'System']);

        $this->assertSame('', $def->description);
        $this->assertSame([], $def->dependencies);
        $this->assertSame([], $def->tables);
        $this->assertSame([], $def->extensions);
        $this->assertSame([], $def->config);
        $this->assertSame([], $def->documentClasses);
    }

    public function testDocumentClassesSingleClass(): void
    {
        $documentClasses = [
            ['table' => 'base_persons_persons', 'class' => 'Shipard\\Module\\Base\\Persons\\PersonDocument'],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'documentClasses' => $documentClasses,
        ]);

        $this->assertSame($documentClasses, $def->documentClasses);
    }

    public function testDocumentClassesWithTypeColumn(): void
    {
        $documentClasses = [
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => 'Shipard\\Module\\Economy\\Docs\\IssuedInvoiceDocument',
                    'inv_received' => 'Shipard\\Module\\Economy\\Docs\\ReceivedInvoiceDocument',
                ],
                'defaultClass' => 'Shipard\\Module\\Economy\\Docs\\GenericDocDocument',
            ],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'economy.docs',
            'name' => 'Documents',
            'documentClasses' => $documentClasses,
        ]);

        $this->assertSame($documentClasses, $def->documentClasses);
    }

    public function testWithoutDocumentClassesDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'economy.docs', 'name' => 'Documents']);

        $this->assertSame([], $def->documentClasses);
    }
}
