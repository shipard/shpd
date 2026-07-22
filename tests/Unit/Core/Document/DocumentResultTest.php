<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\ValidationResult;

class DocumentResultTest extends TestCase
{
    public function testOk(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $result = DocumentResult::ok($data);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($data, $result->getData());
        $this->assertNull($result->getValidation());
        $this->assertNull($result->getErrorMessage());
    }

    public function testOkCarriesValidationWarnings(): void
    {
        $validation = new ValidationResult();
        $validation->addWarning('partner_bank', 'Doplňte bankovní spojení', 'partner_bank_recommended');

        $result = DocumentResult::ok(['id' => 1], $validation);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($validation, $result->getValidation());
        $this->assertCount(1, $result->getValidation()->getWarnings());
    }

    public function testValidationFailed(): void
    {
        $validation = new ValidationResult();
        $validation->addError('name', 'Name is required', 'required');

        $result = DocumentResult::validationFailed($validation);

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getData());
        $this->assertSame($validation, $result->getValidation());
        $this->assertNull($result->getErrorMessage());
    }

    public function testError(): void
    {
        $result = DocumentResult::error('Something went wrong');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getData());
        $this->assertNull($result->getValidation());
        $this->assertSame('Something went wrong', $result->getErrorMessage());
    }

    public function testValidationFailedContainsErrors(): void
    {
        $validation = new ValidationResult();
        $validation->addError('customer_id', 'Odběratel je povinný', 'required');
        $validation->addError('rows.0.unit_price', 'Cena musí být kladná', 'positive');

        $result = DocumentResult::validationFailed($validation);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->getValidation()->isValid());
        $this->assertCount(2, $result->getValidation()->getErrors());
    }
}
