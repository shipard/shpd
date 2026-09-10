<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\TableForm;

/**
 * Konzumentská cesta strukturovaných polí ve formuláři (#74, I5): tab
 * s elementy schématu se kreslí jen tam, kde sloupec existuje (může ho
 * přinášet extension neaktivního modulu).
 */
class StructuredTabForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $tabs = [$this->tab('basic', 'Základní')->section()->col()->input('name')->build()];

        if ($this->hasStructuredColumn('filing_profile')) {
            $tabs[] = $this->tab('filing', 'Podací údaje')
                ->section()->col()
                ->addElements($this->structuredFieldElements('filing_profile', $data))
                ->build();
        }

        return new FormDefinition(table: $this->table, title: 'T', titleNew: 'N', tabs: $tabs);
    }
}

class TableFormStructuredFieldsTest extends TestCase
{
    private const SCHEMA = [
        'version' => '2026',
        'groups'  => [['id' => 'office', 'name' => 'Finanční úřad']],
        'fields'  => [
            ['id' => 'c_ufo', 'type' => 'enumString', 'length' => 5, 'cfgItem' => 'test.offices',
                'group' => 'office', 'name' => 'Finanční úřad'],
            ['id' => 'email', 'type' => 'varchar', 'length' => 255, 'group' => 'office', 'name' => 'E-mail'],
        ],
    ];

    private function tableDef(bool $withProfile): TableDefinition
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
            ['id' => 'name', 'name' => 'Název', 'type' => 'varchar', 'length' => 50],
        ];
        if ($withProfile) {
            $columns[] = [
                'id' => 'filing_profile', 'name' => 'Podací údaje', 'type' => 'json',
                'nullable' => true, 'schema' => 'test.profile',
            ];
        }
        return TableDefinition::fromArray([
            'tableId' => 9103,
            'name'    => 'registrations',
            'columns' => $columns,
        ]);
    }

    private function config(): ConfigRuntime
    {
        $items = ['test.profile' => self::SCHEMA, 'test.offices' => ['451' => ['name' => 'Praha']]];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return $config;
    }

    private function form(bool $withProfile = true, bool $withConfig = true): StructuredTabForm
    {
        $form = new StructuredTabForm('registrations');
        $form->setTableDef($this->tableDef($withProfile));
        if ($withConfig) {
            $form->setConfig($this->config());
        }
        return $form;
    }

    public function testTabCarriesSchemaElements(): void
    {
        $def = $this->form()->buildFormDefinition([], true);

        $this->assertCount(2, $def->tabs);
        $elements = $def->tabs[1]->sections[0]->columns[0]->elements;
        $this->assertSame(
            ['separator', 'select', 'input'],
            array_map(fn(FormElement $el) => $el->type, $elements),
        );
        $this->assertSame('filing_profile.c_ufo', $elements[1]->column);
        $this->assertSame('filing_profile.email', $elements[2]->column);
    }

    public function testTabIsSkippedWhenColumnIsNotOnTheTable(): void
    {
        $def = $this->form(withProfile: false)->buildFormDefinition([], true);

        $this->assertCount(1, $def->tabs);
    }

    public function testWithoutCompiledSchemaTheTabHasNoElements(): void
    {
        // Sloupec existuje, konfigurace není zkompilovaná → tab se postaví,
        // jen v něm nejsou žádná pole (degradace, ne výjimka).
        $def = $this->form(withConfig: false)->buildFormDefinition([], true);

        $this->assertCount(2, $def->tabs);
        $this->assertSame([], $def->tabs[1]->sections[0]->columns[0]->elements);
    }
}
