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

    public function testWhitelistDoesNotApplyToNonInputElements(): void
    {
        // separator, group, select etc. don't render inputs — inputType is ignored
        $el = new FormElement(type: 'separator', inputType: 'datetime-local');
        $this->assertSame('separator', $el->type);
    }
}
