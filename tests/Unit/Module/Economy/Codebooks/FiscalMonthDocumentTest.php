<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\FiscalMonthDocument;

class FiscalMonthDocumentTest extends TestCase
{
    private function doc(): FiscalMonthDocument
    {
        return new FiscalMonthDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'fiscal_year' => 1,
            'date_begin'  => '2026-03-01',
            'date_end'    => '2026-03-31',
            'period_type' => 1,
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingFiscalYearFails(): void
    {
        $data = $this->validData();
        unset($data['fiscal_year']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('fiscal_year', array_column($result->toArray(), 'column'));
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

    public function testValidateInvalidDateRangeFails(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-03-31';
        $data['date_end']   = '2026-03-01';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'date_end' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidPeriodTypeFails(): void
    {
        $data = $this->validData();
        $data['period_type'] = 5;

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('period_type', array_column($result->toArray(), 'column'));
    }

    public function testValidatePeriodTypeOpeningIsValid(): void
    {
        $data = $this->validData();
        $data['period_type'] = 0;

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidatePeriodTypeClosingIsValid(): void
    {
        $data = $this->validData();
        $data['period_type'] = 2;

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testBeforeSaveDerivesCalendarYearAndMonth(): void
    {
        $doc = $this->doc();
        $data = $this->validData();
        $data['date_begin'] = '2026-03-15';
        unset($data['calendar_year'], $data['calendar_month']);

        $doc->beforeSave($data);

        $this->assertSame(2026, $data['calendar_year']);
        $this->assertSame(3, $data['calendar_month']);
    }

    public function testBeforeSaveOverwritesUserSuppliedCalendarFields(): void
    {
        $doc = $this->doc();
        $data = $this->validData();
        $data['date_begin']     = '2026-07-01';
        $data['calendar_year']  = 1999;
        $data['calendar_month'] = 12;

        $doc->beforeSave($data);

        $this->assertSame(2026, $data['calendar_year']);
        $this->assertSame(7, $data['calendar_month']);
    }

    public function testBeforeSaveSkipsWithoutDateBegin(): void
    {
        $doc = $this->doc();
        $data = ['date_end' => '2026-03-31', 'calendar_year' => 1234];

        $doc->beforeSave($data);

        $this->assertSame(1234, $data['calendar_year']);
        $this->assertArrayNotHasKey('calendar_month', $data);
    }
}
