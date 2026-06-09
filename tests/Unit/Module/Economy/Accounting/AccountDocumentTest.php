<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accounting\AccountDocument;

class AccountDocumentTest extends TestCase
{
    private function doc(): AccountDocument
    {
        return new AccountDocument();
    }

    // --- deriveStructure ----------------------------------------------------

    public function testDeriveStructureClass(): void
    {
        $this->assertSame(
            ['account_level' => 1, 'g1' => '5', 'g2' => null, 'g3' => null],
            AccountDocument::deriveStructure('5'),
        );
    }

    public function testDeriveStructureGroup(): void
    {
        $this->assertSame(
            ['account_level' => 2, 'g1' => '5', 'g2' => '50', 'g3' => null],
            AccountDocument::deriveStructure('50'),
        );
    }

    public function testDeriveStructureSynthetic(): void
    {
        $this->assertSame(
            ['account_level' => 3, 'g1' => '5', 'g2' => '50', 'g3' => '501'],
            AccountDocument::deriveStructure('501'),
        );
    }

    public function testDeriveStructureAnalytic(): void
    {
        $this->assertSame(
            ['account_level' => 4, 'g1' => '5', 'g2' => '50', 'g3' => '501'],
            AccountDocument::deriveStructure('501100'),
        );
    }

    public function testDeriveStructureTrimsWhitespace(): void
    {
        $this->assertSame(
            ['account_level' => 3, 'g1' => '3', 'g2' => '31', 'g3' => '311'],
            AccountDocument::deriveStructure('  311 '),
        );
    }

    // --- validate -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return ['number' => '501100', 'name' => 'Spotřeba materiálu'];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingNumberFails(): void
    {
        $data = $this->validData();
        unset($data['number']);
        $this->assertHasError($this->doc()->validate($data), 'number', 'required');
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $this->assertHasError($this->doc()->validate($data), 'name', 'required');
    }

    public function testNonNumericNumberFails(): void
    {
        $data = $this->validData();
        $data['number'] = '50A1';
        $this->assertHasError($this->doc()->validate($data), 'number', 'invalid');
    }

    public function testTooLongNumberFails(): void
    {
        $data = $this->validData();
        $data['number'] = '1234567890123'; // 13 číslic
        $this->assertHasError($this->doc()->validate($data), 'number', 'invalid');
    }

    public function testValidFromAfterValidToFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to'] = '2026-01-01';
        $this->assertHasError($this->doc()->validate($data), 'valid_to', 'invalid_range');
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveDerivesStructureAndTrims(): void
    {
        $data = ['number' => ' 501100 ', 'name' => '  Spotřeba  ', 'short_name' => ' Spot '];
        $this->doc()->beforeSave($data);

        $this->assertSame('501100', $data['number']);
        $this->assertSame('Spotřeba', $data['name']);
        $this->assertSame('Spot', $data['short_name']);
        $this->assertSame(4, $data['account_level']);
        $this->assertSame('5', $data['g1']);
        $this->assertSame('50', $data['g2']);
        $this->assertSame('501', $data['g3']);
    }

    private function assertHasError(
        \Shipard\Core\Document\ValidationResult $result,
        string $column,
        string $code,
    ): void {
        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === $column && $e['code'] === $code,
        );
        $this->assertNotEmpty($errors, "Expected error {$column}/{$code}");
    }
}
