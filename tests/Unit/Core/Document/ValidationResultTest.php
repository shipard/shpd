<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationResult;

class ValidationResultTest extends TestCase
{
    public function testEmptyResultIsValid(): void
    {
        $result = new ValidationResult();
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
        $this->assertSame([], $result->toArray());
    }

    public function testAddErrorMakesResultInvalid(): void
    {
        $result = new ValidationResult();
        $result->addError('name', 'Name is required', 'required');
        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getErrors());
    }

    public function testAddErrorReturnsSelf(): void
    {
        $result = new ValidationResult();
        $returned = $result->addError('name', 'required');
        $this->assertSame($result, $returned);
    }

    public function testToArrayFormat(): void
    {
        $result = new ValidationResult();
        $result->addError('customer_id', 'Odběratel je povinný', 'required');

        $expected = [
            ['column' => 'customer_id', 'message' => 'Odběratel je povinný', 'code' => 'required'],
        ];
        $this->assertSame($expected, $result->toArray());
    }

    public function testToArrayWithDefaultCode(): void
    {
        $result = new ValidationResult();
        $result->addError('name', 'Name is required');

        $array = $result->toArray();
        $this->assertSame('', $array[0]['code']);
    }

    public function testDotNotationColumnForRowErrors(): void
    {
        $result = new ValidationResult();
        $result->addError('rows.0.unit_price', 'Cena musí být kladná', 'positive');

        $errors = $result->getErrors();
        $this->assertSame('rows.0.unit_price', $errors[0]->column);
        $this->assertSame('positive', $errors[0]->code);

        $array = $result->toArray();
        $this->assertSame('rows.0.unit_price', $array[0]['column']);
    }

    public function testMultipleErrors(): void
    {
        $result = new ValidationResult();
        $result->addError('customer_id', 'Odběratel je povinný', 'required');
        $result->addError('rows.0.unit_price', 'Cena musí být kladná', 'positive');

        $this->assertFalse($result->isValid());
        $this->assertCount(2, $result->getErrors());
        $this->assertCount(2, $result->toArray());
    }
}
