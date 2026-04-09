<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormTab;

class FormDefinitionTest extends TestCase
{
    public function testToArrayProducesSnakeCaseKeys(): void
    {
        $def = new FormDefinition(
            table: 'test_table',
            title: 'Edit Record',
            titleNew: 'New Record',
            tabs: [
                new FormTab('basic', 'Basic', [
                    new FormElement(type: 'input', cols: 2, column: 'name', label: 'Name'),
                ]),
            ],
            fullSize: true,
        );

        $arr = $def->toArray();

        $this->assertSame('test_table', $arr['table']);
        $this->assertSame('Edit Record', $arr['title']);
        $this->assertSame('New Record', $arr['title_new']);
        $this->assertTrue($arr['full_size']);
        $this->assertCount(1, $arr['tabs']);
        $this->assertSame('basic', $arr['tabs'][0]['id']);
        $this->assertArrayNotHasKey('doc_states', $arr);
    }

    public function testDocStatesOmittedWhenNull(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [],
        );

        $arr = $def->toArray();

        $this->assertArrayNotHasKey('doc_states', $arr);
    }

    public function testWithDocStatesReturnsNewInstanceWithDocStates(): void
    {
        $def = new FormDefinition(
            table: 'test',
            title: 'Test',
            titleNew: 'New',
            tabs: [],
        );

        $docStates = [
            'currentState' => 10,
            'stateName'    => 'Koncept',
            'stateStyle'   => 'concept',
            'readOnly'     => false,
            'transitions'  => [],
        ];

        $enriched = $def->withDocStates($docStates);

        // New instance
        $this->assertNotSame($def, $enriched);

        // Original unchanged
        $this->assertNull($def->docStates);

        // Enriched has docStates
        $this->assertSame($docStates, $enriched->docStates);
        $this->assertSame($docStates, $enriched->toArray()['doc_states']);
    }

    public function testFormElementToArrayOmitsNullProperties(): void
    {
        $el = new FormElement(type: 'input', cols: 1, column: 'name', label: 'Name');
        $arr = $el->toArray();

        $this->assertSame('input', $arr['type']);
        $this->assertSame(1, $arr['cols']);
        $this->assertSame('name', $arr['column']);
        $this->assertSame('Name', $arr['label']);
        $this->assertArrayNotHasKey('placeholder', $arr);
        $this->assertArrayNotHasKey('triggers', $arr);
        $this->assertArrayNotHasKey('options', $arr);
        $this->assertArrayNotHasKey('content', $arr);
    }

    public function testFormElementToArrayIncludesBooleanFlags(): void
    {
        $el = new FormElement(type: 'input', cols: 1, column: 'email', required: true, readOnly: true, hidden: true);
        $arr = $el->toArray();

        $this->assertTrue($arr['required']);
        $this->assertTrue($arr['read_only']);
        $this->assertTrue($arr['hidden']);
    }

    public function testFormElementToArrayOmitsFalseBooleans(): void
    {
        $el = new FormElement(type: 'input', cols: 1);
        $arr = $el->toArray();

        $this->assertArrayNotHasKey('required', $arr);
        $this->assertArrayNotHasKey('read_only', $arr);
        $this->assertArrayNotHasKey('hidden', $arr);
    }

    public function testFormElementNestedGroupSerialization(): void
    {
        $inner = [
            new FormElement(type: 'input', cols: 1, column: 'first_name', label: 'First'),
            new FormElement(type: 'input', cols: 1, column: 'last_name', label: 'Last'),
        ];
        $group = new FormElement(type: 'group', cols: 4, label: 'Name', elements: $inner);
        $arr = $group->toArray();

        $this->assertSame('group', $arr['type']);
        $this->assertSame(4, $arr['cols']);
        $this->assertCount(2, $arr['elements']);
        $this->assertSame('first_name', $arr['elements'][0]['column']);
        $this->assertSame('last_name', $arr['elements'][1]['column']);
    }

    public function testFormTabToArray(): void
    {
        $tab = new FormTab('info', 'Info', [
            new FormElement(type: 'input', cols: 2, column: 'email'),
        ]);
        $arr = $tab->toArray();

        $this->assertSame('info', $arr['id']);
        $this->assertSame('Info', $arr['label']);
        $this->assertCount(1, $arr['elements']);
    }

    public function testSelectElementWithOptions(): void
    {
        $el = new FormElement(
            type: 'select',
            cols: 1,
            column: 'person_type',
            label: 'Type',
            options: [
                ['value' => 0, 'label' => 'Undefined'],
                ['value' => 1, 'label' => 'Person'],
            ],
        );
        $arr = $el->toArray();

        $this->assertSame('select', $arr['type']);
        $this->assertCount(2, $arr['options']);
        $this->assertSame(0, $arr['options'][0]['value']);
        $this->assertSame('Person', $arr['options'][1]['label']);
    }

    public function testSubtableElementSerialization(): void
    {
        $el = new FormElement(
            type: 'subtable',
            cols: 4,
            table: 'contacts',
            foreignKey: 'person',
            formId: 'contacts_form',
            label: 'Contacts',
        );
        $arr = $el->toArray();

        $this->assertSame('subtable', $arr['type']);
        $this->assertSame('contacts', $arr['table']);
        $this->assertSame('person', $arr['foreign_key']);
        $this->assertSame('contacts_form', $arr['form_id']);
    }
}
