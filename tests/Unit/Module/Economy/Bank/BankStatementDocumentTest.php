<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\BankStatementDocument;

class BankStatementDocumentTest extends TestCase
{
    private function doc(): BankStatementDocument
    {
        return new BankStatementDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'bank_account' => 1,
            'currency'     => 'czk',
            'period_start' => '2026-06-01',
            'period_end'   => '2026-06-30',
        ];
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function hasError(array $errors, string $column, ?string $code = null): bool
    {
        foreach ($errors as $e) {
            if ($e['column'] === $column && ($code === null || $e['code'] === $code)) {
                return true;
            }
        }
        return false;
    }

    public function testValidIsValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingBankAccountFails(): void
    {
        $data = $this->validData();
        unset($data['bank_account']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'bank_account', 'required'));
    }

    public function testMissingCurrencyFails(): void
    {
        $data = $this->validData();
        unset($data['currency']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'currency', 'required'));
    }

    public function testReversedPeriodFails(): void
    {
        $data = $this->validData();
        $data['period_start'] = '2026-06-30';
        $data['period_end']   = '2026-06-01';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'period_end', 'invalid_range'));
    }

    public function testEqualPeriodIsValid(): void
    {
        $data = $this->validData();
        $data['period_start'] = '2026-06-15';
        $data['period_end']   = '2026-06-15';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testBeforeSaveLowercasesCurrencyAndTrimsNumber(): void
    {
        $data = ['currency' => ' EUR ', 'statement_number' => '  2026/06  '];
        $this->doc()->beforeSave($data);
        $this->assertSame('eur', $data['currency']);
        $this->assertSame('2026/06', $data['statement_number']);
    }
}
