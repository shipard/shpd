<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\VatRegistrationDocument;

class VatRegistrationDocumentTest extends TestCase
{
    private function doc(): VatRegistrationDocument
    {
        return new VatRegistrationDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'name'               => 'ČR DPH',
            'region'             => 'eu',
            'country'            => 'cz',
            'taxpayer_kind'      => 0,
            'tax_period_kind'    => 1,
            'cs_period_kind'     => 1,
            'rs_period_kind'     => 1,
            'valid_from'         => '2026-01-01',
            'valid_to'           => null,
            'vat_id'             => 'CZ12345678',
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateOpenEndedValidityIsValid(): void
    {
        $data = $this->validData();
        $data['valid_to'] = null;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('name', array_column($result->toArray(), 'column'));
    }

    public function testMissingRegionFails(): void
    {
        $data = $this->validData();
        unset($data['region']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('region', array_column($result->toArray(), 'column'));
    }

    public function testMissingCountryFails(): void
    {
        $data = $this->validData();
        unset($data['country']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('country', array_column($result->toArray(), 'column'));
    }

    public function testMissingTaxpayerKindFails(): void
    {
        $data = $this->validData();
        unset($data['taxpayer_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('taxpayer_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingTaxPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['tax_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('tax_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingCsPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['cs_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('cs_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingRsPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['rs_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('rs_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingValidFromFails(): void
    {
        $data = $this->validData();
        unset($data['valid_from']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('valid_from', array_column($result->toArray(), 'column'));
    }

    public function testInvalidTaxpayerKindFails(): void
    {
        $data = $this->validData();
        $data['taxpayer_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'taxpayer_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidTaxPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['tax_period_kind'] = 0;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'tax_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidCsPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['cs_period_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'cs_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidRsPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['rs_period_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'rs_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidToBeforeValidFromFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to']   = '2026-01-01';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidToEqualValidFromIsValid(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-06-15';
        $data['valid_to']   = '2026-06-15';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testEmptyVatIdIsValid(): void
    {
        $data = $this->validData();
        $data['vat_id'] = null;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }
}
