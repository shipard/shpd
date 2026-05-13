<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormColumn;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;

class FormSectionTest extends TestCase
{
    public function testEmptyColumnsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FormSection requires at least one column');

        new FormSection([]);
    }

    public function testNonColumnEntryRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormSection([new \stdClass()]);
    }

    public function testToArrayWithSingleColumn(): void
    {
        $section = new FormSection([
            new FormColumn([
                new FormElement(type: 'input', column: 'name', label: 'Name'),
            ]),
        ]);
        $arr = $section->toArray();

        $this->assertNull($arr['title']);
        $this->assertCount(1, $arr['columns']);
        $this->assertSame('name', $arr['columns'][0]['elements'][0]['column']);
        $this->assertArrayNotHasKey('hidden', $arr);
    }

    public function testToArrayWithTitleAndMultipleColumns(): void
    {
        $section = new FormSection(
            columns: [
                new FormColumn([new FormElement(type: 'input', column: 'company_id', label: 'IČO')]),
                new FormColumn([new FormElement(type: 'input', column: 'tax_id', label: 'DIČ')]),
            ],
            title: 'Identifikace firmy',
        );
        $arr = $section->toArray();

        $this->assertSame('Identifikace firmy', $arr['title']);
        $this->assertCount(2, $arr['columns']);
        $this->assertArrayNotHasKey('hidden', $arr);
    }

    public function testHiddenSection(): void
    {
        $section = new FormSection(
            columns: [new FormColumn([])],
            hidden: true,
        );
        $arr = $section->toArray();

        $this->assertTrue($arr['hidden']);
    }
}
