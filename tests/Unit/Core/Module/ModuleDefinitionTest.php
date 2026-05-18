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

    public function testInvalidIdFormatUppercaseGroupThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'A.b', 'name' => 'Test']);
    }

    public function testIdFormatCamelCaseModulePartAllowed(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'docs.invoicesOut', 'name' => 'Issued']);
        $this->assertSame('docs.invoicesOut', $def->id);
    }

    public function testIdFormatModulePartMustStartLowercaseThrows(): void
    {
        // First char of module part must be lowercase (PSR-4-friendly directory name).
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'docs.InvoicesOut', 'name' => 'Issued']);
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

    public function testSettingsItemsParsedCorrectly(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks', 'section' => 'accounting'],
                ['viewer' => 'economy.codebooks.warehouses', 'section' => 'warehouses', 'order' => 5],
            ],
        ]);

        $this->assertCount(2, $def->settingsItems);
        $this->assertSame('economy.codebooks.cashDesks', $def->settingsItems[0]['viewer']);
        $this->assertNull($def->settingsItems[0]['table']);
        $this->assertSame('accounting', $def->settingsItems[0]['section']);
        $this->assertNull($def->settingsItems[0]['order']);
        $this->assertSame(5, $def->settingsItems[1]['order']);
    }

    public function testSettingsItemsMissingSectionIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsBothViewerAndTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks', 'table' => 'some_table', 'section' => 'accounting'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsNeitherViewerNorTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['section' => 'accounting'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'economy.docs', 'name' => 'Documents']);

        $this->assertSame([], $def->settingsItems);
    }

    public function testLookupsParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'base.persons',
            'name' => 'Persons',
            'lookups' => [
                ['table' => 'base_persons_persons', 'class' => 'Foo\\Bar\\PersonsLookup'],
                ['table' => 'base_persons_addresses', 'class' => 'Foo\\Bar\\AddressesLookup'],
            ],
        ]);

        $this->assertCount(2, $def->lookups);
        $this->assertSame('base_persons_persons', $def->lookups[0]['table']);
        $this->assertSame('Foo\\Bar\\PersonsLookup', $def->lookups[0]['class']);
    }

    public function testLookupsMissingTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'base.persons',
            'name' => 'Persons',
            'lookups' => [
                ['class' => 'Foo\\Bar\\Lookup'],
                ['table' => 't', 'class' => 'Foo\\Bar\\Lookup'],
            ],
        ]);

        $this->assertCount(1, $def->lookups);
    }

    public function testLookupsAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->lookups);
    }

    public function testAlertChecksAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->alertChecks);
    }

    public function testAlertChecksRawPassthrough(): void
    {
        $raw = [
            [
                'id' => 'base.persons.missing_own_person',
                'name' => 'Own Person is missing',
                'name:cs' => 'Chybí vlastní Osoba',
                'class' => 'Foo\\Bar',
                'interval' => '1h',
            ],
            [
                'id' => 'base.persons.duplicate_own',
                'name' => 'Multiple own persons',
                'class' => 'Foo\\Baz',
                'interval' => '4h',
            ],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => $raw,
        ]);

        // Module-level passthrough — :cs suffix zůstává až do ConfigLocalizeru.
        $this->assertCount(2, $def->alertChecks);
        $this->assertSame('base.persons.missing_own_person', $def->alertChecks[0]['id']);
        $this->assertSame('Chybí vlastní Osoba', $def->alertChecks[0]['name:cs']);
    }

    public function testAlertChecksMissingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing 'id'");
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => [
                ['name' => 'x', 'class' => 'Y', 'interval' => '1h'],
            ],
        ]);
    }

    public function testAlertChecksDuplicateIdWithinModuleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate alertChecks id');
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => [
                ['id' => 'x.y', 'name' => 'a', 'class' => 'A', 'interval' => '1h'],
                ['id' => 'x.y', 'name' => 'b', 'class' => 'B', 'interval' => '2h'],
            ],
        ]);
    }

    public function testAlertChecksNonArrayEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an object');
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => ['not-an-array'],
        ]);
    }
}
