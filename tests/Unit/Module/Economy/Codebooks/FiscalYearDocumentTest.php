<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\FiscalYearDocument;

class FiscalYearDocumentTest extends TestCase
{
    private function doc(): FiscalYearDocument
    {
        return new FiscalYearDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'name'              => '2026',
            'doc_number_prefix' => '26',
            'date_begin'        => '2026-01-01',
            'date_end'          => '2026-12-31',
            'currency'          => 'czk',
            'locked'            => 0,
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('name', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDocNumberPrefixFails(): void
    {
        $data = $this->validData();
        unset($data['doc_number_prefix']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('doc_number_prefix', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDateBeginFails(): void
    {
        $data = $this->validData();
        unset($data['date_begin']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_begin', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDateEndFails(): void
    {
        $data = $this->validData();
        unset($data['date_end']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_end', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingCurrencyFails(): void
    {
        $data = $this->validData();
        unset($data['currency']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('currency', array_column($result->toArray(), 'column'));
    }

    public function testValidateInvalidDateRangeFails(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-12-31';
        $data['date_end']   = '2026-01-01';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'date_end' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateEqualDatesIsValid(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-06-15';
        $data['date_end']   = '2026-06-15';

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateUppercaseCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'CZK';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('currency', array_column($result->toArray(), 'column'));
    }

    public function testValidateShortCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'cz';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('currency', array_column($result->toArray(), 'column'));
    }

    public function testValidateNumericCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = '203';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('currency', array_column($result->toArray(), 'column'));
    }
}
