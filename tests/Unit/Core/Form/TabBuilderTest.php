<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\TabBuilder;

class TabBuilderTest extends TestCase
{
    public function testBuildBasicTab(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->addInput('name', cols: 2, label: 'Name', required: true)
            ->addInput('email', cols: 2, label: 'Email')
            ->build();

        $this->assertSame('basic', $tab->id);
        $this->assertSame('Basic', $tab->label);
        $this->assertCount(2, $tab->elements);
        $this->assertSame('input', $tab->elements[0]->type);
        $this->assertSame('name', $tab->elements[0]->column);
        $this->assertTrue($tab->elements[0]->required);
        $this->assertSame(2, $tab->elements[0]->cols);
    }

    public function testAddSelect(): void
    {
        $options = [
            ['value' => 1, 'label' => 'Option A'],
            ['value' => 2, 'label' => 'Option B'],
        ];

        $tab = (new TabBuilder('t', 'T'))
            ->addSelect('type', cols: 1, label: 'Type', options: $options)
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertSame($options, $el->options);
    }

    public function testAddSeparator(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addSeparator('Divider')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('separator', $el->type);
        $this->assertSame(4, $el->cols);
        $this->assertSame('Divider', $el->label);
    }

    public function testGroupNesting(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addInput('before', label: 'Before')
            ->openGroup('Name Group', cols: 4)
                ->addInput('first_name', label: 'First')
                ->addInput('last_name', label: 'Last')
            ->closeGroup()
            ->addInput('after', label: 'After')
            ->build();

        $this->assertCount(3, $tab->elements);

        // First is regular input
        $this->assertSame('input', $tab->elements[0]->type);
        $this->assertSame('before', $tab->elements[0]->column);

        // Second is group
        $group = $tab->elements[1];
        $this->assertSame('group', $group->type);
        $this->assertSame('Name Group', $group->label);
        $this->assertSame(4, $group->cols);
        $this->assertCount(2, $group->elements);
        $this->assertSame('first_name', $group->elements[0]->column);
        $this->assertSame('last_name', $group->elements[1]->column);

        // Third is regular input
        $this->assertSame('input', $tab->elements[2]->type);
        $this->assertSame('after', $tab->elements[2]->column);
    }

    public function testAddSubtable(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addSubtable('contacts', 'person_id', formId: 'contacts_form', label: 'Contacts')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('subtable', $el->type);
        $this->assertSame('contacts', $el->table);
        $this->assertSame('person_id', $el->foreignKey);
        $this->assertSame('contacts_form', $el->formId);
    }

    public function testAddHtml(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addHtml('<p>Help text</p>', cols: 4)
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('html', $el->type);
        $this->assertSame('<p>Help text</p>', $el->content);
        $this->assertSame(4, $el->cols);
    }

    public function testUnclosedGroupThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unclosed group');

        (new TabBuilder('t', 'T'))
            ->openGroup('G')
            ->addInput('x')
            ->build();
    }

    public function testCloseGroupWithoutOpenThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('closeGroup() called without matching openGroup()');

        (new TabBuilder('t', 'T'))
            ->closeGroup();
    }

    public function testInputWithTriggers(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addInput('type_field', triggers: 'reload')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('reload', $el->triggers);
    }

    public function testAddTextArea(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addTextArea('body', cols: 4, required: true, hint: 'Plain text')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('textarea', $el->inputType);
        $this->assertSame('body', $el->column);
        $this->assertSame(4, $el->cols);
        $this->assertTrue($el->required);
        $this->assertSame('Plain text', $el->hint);
    }

    public function testAddDate(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addDate('birth_date', hidden: true)
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('date', $el->inputType);
        $this->assertTrue($el->hidden);
    }

    public function testAddDateTime(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addDateTime('received_at', required: true)
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('datetime', $el->inputType);
        $this->assertTrue($el->required);
    }

    public function testAddTime(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addTime('opens_at')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('time', $el->inputType);
    }

    public function testAddNumber(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addNumber('quantity')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('number', $el->inputType);
    }

    public function testAddCheckbox(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addCheckbox('active')
            ->build();

        $el = $tab->elements[0];
        $this->assertSame('input', $el->type);
        $this->assertSame('checkbox', $el->inputType);
    }

    public function testAddInputRejectsNonTextVariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('addInput() accepts only text variants');

        (new TabBuilder('t', 'T'))->addInput('body', inputType: 'textarea');
    }

    public function testAddInputRejectsDatetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TabBuilder('t', 'T'))->addInput('received_at', inputType: 'datetime');
    }

    public function testAddInputAcceptsEmail(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->addInput('email', inputType: 'email')
            ->build();

        $this->assertSame('email', $tab->elements[0]->inputType);
    }
}
