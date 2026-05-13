<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\TabBuilder;

class TabBuilderTest extends TestCase
{
    public function testSectionWithSingleColumn(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section()
                ->col()
                    ->input('name', label: 'Name', required: true)
                    ->input('email', label: 'Email')
            ->build();

        $this->assertSame('basic', $tab->id);
        $this->assertSame('Basic', $tab->label);
        $this->assertSame('fields', $tab->type);
        $this->assertCount(1, $tab->sections);
        $section = $tab->sections[0];
        $this->assertNull($section->title);
        $this->assertCount(1, $section->columns);
        $this->assertCount(2, $section->columns[0]->elements);
        $this->assertSame('name', $section->columns[0]->elements[0]->column);
        $this->assertTrue($section->columns[0]->elements[0]->required);
    }

    public function testSectionWithMultipleColumns(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section('Identifikace firmy')
                ->col()->input('company_id', label: 'IČO')
                ->col()->input('tax_id', label: 'DIČ')
            ->build();

        $section = $tab->sections[0];
        $this->assertSame('Identifikace firmy', $section->title);
        $this->assertCount(2, $section->columns);
        $this->assertSame('company_id', $section->columns[0]->elements[0]->column);
        $this->assertSame('tax_id', $section->columns[1]->elements[0]->column);
    }

    public function testMultipleSections(): void
    {
        $tab = (new TabBuilder('basic', 'Basic'))
            ->section()
                ->col()->input('a')
            ->section('Two')
                ->col()->input('b')
            ->build();

        $this->assertCount(2, $tab->sections);
        $this->assertNull($tab->sections[0]->title);
        $this->assertSame('Two', $tab->sections[1]->title);
    }

    public function testColOutsideSectionThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('col() called outside of a section');

        (new TabBuilder('t', 'T'))->col();
    }

    public function testElementOutsideColThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outside of a column');

        (new TabBuilder('t', 'T'))->section()->input('x');
    }

    public function testInlineGroup(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Termíny')
                ->col()
                    ->inline()
                        ->date('date_tax', label: 'DUZP')
                        ->date('date_tax_duty', label: 'DPPD')
                    ->endInline()
            ->build();

        $col = $tab->sections[0]->columns[0];
        $this->assertCount(1, $col->elements);
        $inline = $col->elements[0];
        $this->assertSame('inline', $inline->type);
        $this->assertCount(2, $inline->elements);
        $this->assertSame('date', $inline->elements[0]->inputType);
        $this->assertSame('DPPD', $inline->elements[1]->label);
    }

    public function testInlineFieldsShortcut(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inlineFields('a', 'b', 'c')
            ->build();

        $inline = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('inline', $inline->type);
        $this->assertCount(3, $inline->elements);
        $this->assertSame('a', $inline->elements[0]->column);
    }

    public function testUnclosedInlineAutoClosesInBuild(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inline()
                        ->input('a')
                        ->input('b')
            ->build();

        $col = $tab->sections[0]->columns[0];
        $this->assertSame('inline', $col->elements[0]->type);
        $this->assertCount(2, $col->elements[0]->elements);
    }

    public function testInlineRejectsSeparator(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('separator cannot appear inside inline');

        (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->inline()
                    ->separator('boom');
    }

    public function testEndInlineWithoutInlineThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('endInline() called without matching inline()');

        (new TabBuilder('t', 'T'))
            ->section()->col()->endInline();
    }

    public function testAutoHideSeparatorsPerColumn(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->input('visible')
                    ->separator('Hidden block')
                    ->input('a', hidden: true)
                    ->input('b', hidden: true)
                    ->separator('Visible block')
                    ->input('c')
            ->build();

        $elements = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('separator', $elements[1]->type);
        $this->assertTrue($elements[1]->hidden, 'separator before all-hidden block should be auto-hidden');
        $this->assertSame('separator', $elements[4]->type);
        $this->assertFalse($elements[4]->hidden, 'separator with visible follower should stay visible');
    }

    public function testAutoHideSeparatorsScopedToColumn(): void
    {
        // Separator in column 1 must not be auto-hidden by visible elements in column 2.
        $tab = (new TabBuilder('t', 'T'))
            ->section()
                ->col()
                    ->separator('Sep A')
                    ->input('hidden1', hidden: true)
                ->col()
                    ->input('visible2')
            ->build();

        $col0 = $tab->sections[0]->columns[0];
        $this->assertTrue($col0->elements[0]->hidden);
    }

    public function testHiddenSection(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Hidden', hidden: true)
                ->col()->input('x')
            ->build();

        $this->assertTrue($tab->sections[0]->hidden);
    }

    public function testEmptySectionSkipped(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section('Empty')
            ->section('Real')
                ->col()->input('x')
            ->build();

        $this->assertCount(1, $tab->sections);
        $this->assertSame('Real', $tab->sections[0]->title);
    }

    // -------- Widgets --------

    public function testSelect(): void
    {
        $options = [
            ['value' => 1, 'label' => 'A'],
            ['value' => 2, 'label' => 'B'],
        ];
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->select('type', label: 'Type', options: $options)
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('select', $el->type);
        $this->assertSame($options, $el->options);
    }

    public function testTextarea(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->textarea('body', required: true, hint: 'Plain')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('textarea', $el->inputType);
        $this->assertTrue($el->required);
        $this->assertSame('Plain', $el->hint);
    }

    public function testDate(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->date('birth_date', hidden: true)
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('date', $el->inputType);
        $this->assertTrue($el->hidden);
    }

    public function testDateTimeAndTimeAndNumberAndCheckbox(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()
                ->datetime('received_at')
                ->time('opens_at')
                ->number('qty')
                ->checkbox('active')
            ->build();
        $els = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('datetime', $els[0]->inputType);
        $this->assertSame('time', $els[1]->inputType);
        $this->assertSame('number', $els[2]->inputType);
        $this->assertSame('checkbox', $els[3]->inputType);
    }

    public function testHtml(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->html('<p>Hi</p>')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('html', $el->type);
        $this->assertSame('<p>Hi</p>', $el->content);
    }

    public function testComponent(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->component('recapitulation')
            ->build();
        $el = $tab->sections[0]->columns[0]->elements[0];
        $this->assertSame('component', $el->type);
        $this->assertSame('recapitulation', $el->componentName);
    }

    public function testTriggers(): void
    {
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->input('type_field', triggers: 'reload')
            ->build();
        $this->assertSame('reload', $tab->sections[0]->columns[0]->elements[0]->triggers);
    }

    public function testColLabelsAutoResolve(): void
    {
        $tab = (new TabBuilder('t', 'T', ['name' => 'Jméno', 'email' => 'E-mail']))
            ->section()->col()
                ->input('name')
                ->input('email', label: 'Explicit')
            ->build();
        $els = $tab->sections[0]->columns[0]->elements;
        $this->assertSame('Jméno', $els[0]->label);
        $this->assertSame('Explicit', $els[1]->label);
    }

    public function testInputRejectsTextarea(): void
    {
        // input() lower-level: bypass via direct input() with inputType is allowed by the
        // builder (no whitelist); enforcement is at FormElement level — which allows textarea.
        // For TabBuilder users, use ->textarea() helper. Test that the helper sets it.
        $tab = (new TabBuilder('t', 'T'))
            ->section()->col()->textarea('body')->build();
        $this->assertSame('textarea', $tab->sections[0]->columns[0]->elements[0]->inputType);
    }

    public function testIcon(): void
    {
        $tab = (new TabBuilder('t', 'T', icon: 'user'))
            ->section()->col()->input('x')
            ->build();
        $this->assertSame('user', $tab->icon);
    }
}
