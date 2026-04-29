<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Units;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Units\UnitDocument;

class UnitDocumentTest extends TestCase
{
    private function doc(): UnitDocument
    {
        return new UnitDocument();
    }

    public function testValidateMissingNameFails(): void
    {
        $data = ['shortcut' => 'kg', 'quantity' => 'weight'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testValidateMissingShortcutFails(): void
    {
        $data = ['name' => 'Kilogram', 'quantity' => 'weight'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('shortcut', $columns);
    }

    public function testValidateMissingQuantityFails(): void
    {
        $data = ['name' => 'Kilogram', 'shortcut' => 'kg'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('quantity', $columns);
    }

    public function testValidateUnknownQuantityFails(): void
    {
        $data = ['name' => 'X', 'shortcut' => 'x', 'quantity' => 'mass'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'quantity',
        );
        $this->assertNotEmpty($errors);
        $first = array_values($errors)[0];
        $this->assertSame('invalid', $first['code']);
    }

    public function testValidateNegativeCoefficientFails(): void
    {
        $data = [
            'name' => 'Kilogram',
            'shortcut' => 'kg',
            'quantity' => 'weight',
            'coefficient' => -1,
        ];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('coefficient', $columns);
    }

    public function testValidateZeroCoefficientFails(): void
    {
        $data = [
            'name' => 'X', 'shortcut' => 'x', 'quantity' => 'weight',
            'coefficient' => 0,
        ];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('coefficient', $columns);
    }

    public function testValidateNullCoefficientPasses(): void
    {
        $data = [
            'name' => 'Hodina',
            'shortcut' => 'hod',
            'quantity' => 'time',
            'coefficient' => null,
        ];
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateAllRequiredOk(): void
    {
        $data = [
            'name' => 'Kilogram',
            'shortcut' => 'kg',
            'quantity' => 'weight',
            'coefficient' => 1,
            'is_base' => true,
        ];
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }
}
