<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\WarehouseDocument;

class WarehouseDocumentTest extends TestCase
{
    private function doc(): WarehouseDocument
    {
        return new WarehouseDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'code' => 'HQ',
            'name' => 'Hlavní sklad',
        ];
    }

    // --- validate -----------------------------------------------------------

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingCodeFails(): void
    {
        $data = $this->validData();
        unset($data['code']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'code' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'name' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidValidityRangeFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to']   = '2026-01-01';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($errors);
    }

    public function testEqualValidityDatesIsValid(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-06-15';
        $data['valid_to']   = '2026-06-15';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveTrimsCodeAndName(): void
    {
        $doc  = $this->doc();
        $data = [
            'code' => '  HQ  ',
            'name' => "  Hlavní sklad\n",
        ];
        $doc->beforeSave($data);

        $this->assertSame('HQ', $data['code']);
        $this->assertSame('Hlavní sklad', $data['name']);
    }
}
