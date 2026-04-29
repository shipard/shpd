<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\VatPeriodDocument;

class VatPeriodDocumentTest extends TestCase
{
    private function doc(): VatPeriodDocument
    {
        return new VatPeriodDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'vat_registration' => 1,
            'name'             => '01/2026',
            'date_begin'       => '2026-01-01',
            'date_end'         => '2026-01-31',
            'locked'           => 0,
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingVatRegistrationFails(): void
    {
        $data = $this->validData();
        unset($data['vat_registration']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('vat_registration', array_column($result->toArray(), 'column'));
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('name', array_column($result->toArray(), 'column'));
    }

    public function testMissingDateBeginFails(): void
    {
        $data = $this->validData();
        unset($data['date_begin']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_begin', array_column($result->toArray(), 'column'));
    }

    public function testMissingDateEndFails(): void
    {
        $data = $this->validData();
        unset($data['date_end']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_end', array_column($result->toArray(), 'column'));
    }

    public function testInvalidDateRangeFails(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-01-31';
        $data['date_end']   = '2026-01-01';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'date_end' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($errors);
    }

    public function testEqualDatesIsValid(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-06-15';
        $data['date_end']   = '2026-06-15';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }
}
