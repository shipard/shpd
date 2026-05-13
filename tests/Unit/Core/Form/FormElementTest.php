<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormElement;

class FormElementTest extends TestCase
{
    public static function validInputTypesProvider(): array
    {
        return [
            'null'     => [null],
            'text'     => ['text'],
            'email'    => ['email'],
            'tel'      => ['tel'],
            'url'      => ['url'],
            'password' => ['password'],
            'number'   => ['number'],
            'checkbox' => ['checkbox'],
            'date'     => ['date'],
            'datetime' => ['datetime'],
            'time'     => ['time'],
            'textarea' => ['textarea'],
        ];
    }

    /**
     * @dataProvider validInputTypesProvider
     */
    public function testValidInputTypesPass(?string $inputType): void
    {
        $el = new FormElement(type: 'input', column: 'x', inputType: $inputType);
        $this->assertSame($inputType, $el->inputType);
    }

    public function testDatetimeLocalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inputType "datetime-local"');

        new FormElement(type: 'input', column: 'x', inputType: 'datetime-local');
    }

    public function testBogusInputTypeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inputType "bogus"');

        new FormElement(type: 'input', column: 'x', inputType: 'bogus');
    }

    public function testUnknownElementTypeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid element type "group"');

        new FormElement(type: 'group');
    }

    public function testSubtableTypeRejected(): void
    {
        // subtable is now a tab, no longer a field element
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid element type "subtable"');

        new FormElement(type: 'subtable');
    }

    public function testWhitelistDoesNotApplyToNonInputElements(): void
    {
        // separator, select etc. don't render inputs — inputType is ignored
        $el = new FormElement(type: 'separator', inputType: 'datetime-local');
        $this->assertSame('separator', $el->type);
    }

    public function testInlineRequiresNonEmptyElements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inline element requires non-empty elements[]');

        new FormElement(type: 'inline');
    }

    public function testInlineWithDisallowedInnerTypeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only input, select are allowed inside inline groups');

        new FormElement(type: 'inline', elements: [
            new FormElement(type: 'separator'),
        ]);
    }

    public function testInlineWithValidInnerElements(): void
    {
        $el = new FormElement(type: 'inline', elements: [
            new FormElement(type: 'input', column: 'date_tax', label: 'DUZP', inputType: 'date'),
            new FormElement(type: 'input', column: 'date_tax_duty', label: 'DPPD', inputType: 'date'),
        ]);
        $this->assertSame('inline', $el->type);
        $this->assertCount(2, $el->elements);
    }

    public function testComponentRequiresName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('component element requires componentName');

        new FormElement(type: 'component');
    }

    public function testHtmlRequiresContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('html element requires content');

        new FormElement(type: 'html');
    }

    public function testToArrayOmitsNullProperties(): void
    {
        $el = new FormElement(type: 'input', column: 'name', label: 'Name');
        $arr = $el->toArray();

        $this->assertSame('input', $arr['type']);
        $this->assertSame('name', $arr['column']);
        $this->assertSame('Name', $arr['label']);
        $this->assertArrayNotHasKey('placeholder', $arr);
        $this->assertArrayNotHasKey('triggers', $arr);
        $this->assertArrayNotHasKey('options', $arr);
        $this->assertArrayNotHasKey('content', $arr);
        $this->assertArrayNotHasKey('cols', $arr);
    }

    public function testToArrayIncludesBooleanFlags(): void
    {
        $el = new FormElement(type: 'input', column: 'email', required: true, readOnly: true, hidden: true);
        $arr = $el->toArray();

        $this->assertTrue($arr['required']);
        $this->assertTrue($arr['read_only']);
        $this->assertTrue($arr['hidden']);
    }

    public function testToArrayOmitsFalseBooleans(): void
    {
        $el = new FormElement(type: 'input', column: 'x');
        $arr = $el->toArray();

        $this->assertArrayNotHasKey('required', $arr);
        $this->assertArrayNotHasKey('read_only', $arr);
        $this->assertArrayNotHasKey('hidden', $arr);
    }

    public function testInlineSerialization(): void
    {
        $el = new FormElement(type: 'inline', elements: [
            new FormElement(type: 'input', column: 'date_tax', label: 'DUZP', inputType: 'date'),
            new FormElement(type: 'input', column: 'date_tax_duty', label: 'DPPD', inputType: 'date'),
        ]);
        $arr = $el->toArray();

        $this->assertSame('inline', $arr['type']);
        $this->assertCount(2, $arr['elements']);
        $this->assertSame('date_tax', $arr['elements'][0]['column']);
        $this->assertSame('DPPD', $arr['elements'][1]['label']);
    }

    public function testSelectWithOptions(): void
    {
        $el = new FormElement(
            type: 'select',
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
        $this->assertSame('Person', $arr['options'][1]['label']);
    }

    public function testHtmlSerialization(): void
    {
        $el = new FormElement(type: 'html', content: '<p>Hello</p>');
        $arr = $el->toArray();

        $this->assertSame('html', $arr['type']);
        $this->assertSame('<p>Hello</p>', $arr['content']);
    }

    public function testComponentSerialization(): void
    {
        $el = new FormElement(type: 'component', componentName: 'recapitulation');
        $arr = $el->toArray();

        $this->assertSame('component', $arr['type']);
        $this->assertSame('recapitulation', $arr['component_name']);
    }
}
