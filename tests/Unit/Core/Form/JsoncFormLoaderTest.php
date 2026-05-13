<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\JsoncFormLoader;

class JsoncFormLoaderTest extends TestCase
{
    private string $tmpDir;

    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd-jsoncformloader-' . uniqid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeJsonc(string $content): string
    {
        $path = $this->tmpDir . '/form_' . count($this->tmpFiles) . '.jsonc';
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function makeTableDef(array $columns = []): TableDefinition
    {
        $defaultCols = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
        ];
        return TableDefinition::fromArray([
            'tableId' => 100,
            'name'    => 'test_table',
            'columns' => array_merge($defaultCols, $columns),
        ]);
    }

    public function testLoadsBasicForm(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "title": "Kontakt",
            "titleNew": "Nový kontakt",
            "fullSize": false,
            "tabs": [
                {
                    "id": "basic",
                    "label": "Kontakt",
                    "sections": [
                        {
                            "columns": [
                                {"elements": [
                                    {"type": "input", "column": "name", "required": true},
                                    {"type": "input", "column": "email", "inputType": "email"}
                                ]}
                            ]
                        }
                    ]
                }
            ]
        }
        JSON);

        $def = (new JsoncFormLoader())->load(
            jsonPath: $path,
            tableDef: $this->makeTableDef([
                ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
                ['id' => 'email', 'name' => 'Email', 'type' => 'varchar', 'length' => 200],
            ]),
            tableId: 'test_table',
        );

        $this->assertSame('Kontakt', $def->title);
        $this->assertSame('Nový kontakt', $def->titleNew);
        $this->assertFalse($def->fullSize);
        $this->assertCount(1, $def->tabs);

        $tab = $def->tabs[0];
        $this->assertSame('basic', $tab->id);
        $this->assertSame('fields', $tab->type);
        $this->assertCount(1, $tab->sections);

        $col = $tab->sections[0]->columns[0];
        $this->assertCount(2, $col->elements);
        $this->assertSame('name', $col->elements[0]->column);
        $this->assertTrue($col->elements[0]->required);
        $this->assertSame('email', $col->elements[1]->inputType);
    }

    public function testAutoFillsLabelFromTableDef(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "title": "T",
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "input", "column": "name"}
                ]}]}]
            }]
        }
        JSON);

        $def = (new JsoncFormLoader())->load(
            jsonPath: $path,
            tableDef: $this->makeTableDef([
                ['id' => 'name', 'name' => 'Jméno', 'type' => 'varchar', 'length' => 100],
            ]),
        );

        $this->assertSame('Jméno', $def->tabs[0]->sections[0]->columns[0]->elements[0]->label);
    }

    public function testDerivesInputTypeFromColumnType(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "input", "column": "birth"},
                    {"type": "input", "column": "active"},
                    {"type": "input", "column": "note"}
                ]}]}]
            }]
        }
        JSON);

        $def = (new JsoncFormLoader())->load(
            jsonPath: $path,
            tableDef: $this->makeTableDef([
                ['id' => 'birth',  'name' => 'Birth',  'type' => 'date'],
                ['id' => 'active', 'name' => 'Active', 'type' => 'boolean'],
                ['id' => 'note',   'name' => 'Note',   'type' => 'text'],
            ]),
        );

        $els = $def->tabs[0]->sections[0]->columns[0]->elements;
        $this->assertSame('date', $els[0]->inputType);
        $this->assertSame('checkbox', $els[1]->inputType);
        $this->assertSame('textarea', $els[2]->inputType);
    }

    public function testAutoResolvesSelectOptionsFromCfgItem(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "select", "column": "person_type"}
                ]}]}]
            }]
        }
        JSON);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'base.persons.personTypes'
                ? ['0' => ['name' => 'Undefined'], '1' => ['name' => 'Person']]
                : null,
        );

        $def = (new JsoncFormLoader())->load(
            jsonPath: $path,
            tableDef: $this->makeTableDef([
                ['id' => 'person_type', 'name' => 'Type', 'type' => 'enumInt', 'cfgItem' => 'base.persons.personTypes'],
            ]),
            config: $config,
        );

        $el = $def->tabs[0]->sections[0]->columns[0]->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertCount(2, $el->options);
        $this->assertSame(0, $el->options[0]['value']);
    }

    public function testLocalizationSelectsLanguage(): void
    {
        $path = $this->writeJsonc(<<<'JSON'
        {
            "title": "Default",
            "title:cs": "Kontakt",
            "title:en": "Contact",
            "tabs": [{
                "id": "basic",
                "label": "Default",
                "label:cs": "Základní",
                "label:en": "Basic",
                "sections": [{"columns": [{"elements": [
                    {"type": "input", "column": "name", "label": "Name", "label:cs": "Jméno"}
                ]}]}]
            }]
        }
        JSON);

        $loader = new JsoncFormLoader();

        $defCs = $loader->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]), language: 'cs');

        $this->assertSame('Kontakt', $defCs->title);
        $this->assertSame('Základní', $defCs->tabs[0]->label);
        $this->assertSame('Jméno', $defCs->tabs[0]->sections[0]->columns[0]->elements[0]->label);

        $defEn = $loader->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]), language: 'en');

        $this->assertSame('Contact', $defEn->title);
        $this->assertSame('Basic', $defEn->tabs[0]->label);
    }

    public function testRejectsLegacyElementsAtTabLevel(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "elements": [{"type": "input", "column": "name", "cols": 2}]
            }]
        }
        JSON);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('legacy "elements[]" shape');

        (new JsoncFormLoader())->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]));
    }

    public function testRejectsLegacyColsOnElement(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "input", "column": "name", "cols": 2}
                ]}]}]
            }]
        }
        JSON);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('legacy "cols"');

        (new JsoncFormLoader())->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]));
    }

    public function testRejectsLegacyGroupType(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "group", "label": "G", "elements": []}
                ]}]}]
            }]
        }
        JSON);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('element type "group" is no longer supported');

        (new JsoncFormLoader())->load($path, $this->makeTableDef());
    }

    public function testRejectsLegacySubtableElement(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "contacts", "label": "C",
                "sections": [{"columns": [{"elements": [
                    {"type": "subtable", "table": "base_persons_contacts", "foreignKey": "person"}
                ]}]}]
            }]
        }
        JSON);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('element type "subtable"');

        (new JsoncFormLoader())->load($path, $this->makeTableDef());
    }

    public function testLoadsSubtableTab(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [
                {
                    "id": "basic", "label": "B",
                    "sections": [{"columns": [{"elements": [
                        {"type": "input", "column": "name"}
                    ]}]}]
                },
                {
                    "id": "contacts", "label": "Kontakty",
                    "type": "subtable",
                    "subtable": {"table": "base_persons_contacts", "foreignKey": "person", "formId": "base.persons.contacts"}
                }
            ]
        }
        JSON);

        $def = (new JsoncFormLoader())->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]));

        $this->assertCount(2, $def->tabs);
        $this->assertSame('subtable', $def->tabs[1]->type);
        $this->assertSame('base_persons_contacts', $def->tabs[1]->subtable['table']);
        $this->assertSame('person', $def->tabs[1]->subtable['foreignKey']);
        $this->assertSame('base.persons.contacts', $def->tabs[1]->subtable['formId']);
    }

    public function testLoadsAttachmentsTab(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [
                {
                    "id": "basic", "label": "B",
                    "sections": [{"columns": [{"elements": [
                        {"type": "input", "column": "name"}
                    ]}]}]
                },
                {"id": "att", "label": "Přílohy", "type": "attachments", "tableId": 110}
            ]
        }
        JSON);

        $def = (new JsoncFormLoader())->load($path, $this->makeTableDef([
            ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
        ]));

        $this->assertSame('attachments', $def->tabs[1]->type);
        $this->assertSame(110, $def->tabs[1]->tableId);
    }

    public function testRequiredFromNonNullableWithoutDefault(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "input", "column": "must"},
                    {"type": "input", "column": "may"}
                ]}]}]
            }]
        }
        JSON);

        $def = (new JsoncFormLoader())->load($path, $this->makeTableDef([
            ['id' => 'must', 'name' => 'M', 'type' => 'varchar', 'length' => 50, 'nullable' => false],
            ['id' => 'may',  'name' => 'May', 'type' => 'varchar', 'length' => 50, 'nullable' => true],
        ]));

        $els = $def->tabs[0]->sections[0]->columns[0]->elements;
        $this->assertTrue($els[0]->required);
        $this->assertFalse($els[1]->required);
    }

    public function testInlineGroupParsed(): void
    {
        $path = $this->writeJsonc(<<<JSON
        {
            "tabs": [{
                "id": "basic", "label": "B",
                "sections": [{"columns": [{"elements": [
                    {"type": "inline", "elements": [
                        {"type": "input", "column": "date_tax", "inputType": "date", "label": "DUZP"},
                        {"type": "input", "column": "date_tax_duty", "inputType": "date", "label": "DPPD"}
                    ]}
                ]}]}]
            }]
        }
        JSON);

        $def = (new JsoncFormLoader())->load($path, $this->makeTableDef());
        $inline = $def->tabs[0]->sections[0]->columns[0]->elements[0];

        $this->assertSame('inline', $inline->type);
        $this->assertCount(2, $inline->elements);
        $this->assertSame('DPPD', $inline->elements[1]->label);
    }
}
