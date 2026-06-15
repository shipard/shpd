<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\BankTransactionDocument;

class BankTransactionDocumentTest extends TestCase
{
    private function doc(): BankTransactionDocument
    {
        return new BankTransactionDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'bank_account'     => 1,
            'direction'        => 1,
            'amount'           => 1210.0,
            'amount_dom'       => 1210.0,
            'currency'         => 'czk',
            'date_transaction' => '2026-06-10',
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

    // --- validate -----------------------------------------------------------

    public function testValidIsValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testDirectionOutOfRangeFails(): void
    {
        foreach ([0, 3, -1] as $dir) {
            $data = $this->validData();
            $data['direction'] = $dir;
            $result = $this->doc()->validate($data);
            $this->assertFalse($result->isValid(), "direction {$dir} mělo selhat");
            $this->assertTrue($this->hasError($result->toArray(), 'direction', 'invalid'));
        }
    }

    public function testMissingDirectionFails(): void
    {
        $data = $this->validData();
        unset($data['direction']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'direction'));
    }

    public function testNonPositiveAmountFails(): void
    {
        foreach ([0, -5.0] as $amount) {
            $data = $this->validData();
            $data['amount'] = $amount;
            $result = $this->doc()->validate($data);
            $this->assertFalse($result->isValid(), "amount {$amount} mělo selhat");
            $this->assertTrue($this->hasError($result->toArray(), 'amount', 'invalid'));
        }
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

    public function testMissingDateFails(): void
    {
        $data = $this->validData();
        unset($data['date_transaction']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'date_transaction', 'required'));
    }

    public function testNegativeAmountDomFails(): void
    {
        $data = $this->validData();
        $data['amount_dom'] = -1.0;
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasError($result->toArray(), 'amount_dom'));
    }

    public function testMissingAmountDomIsValidBecauseDerived(): void
    {
        // amount_dom dopočítá beforeSave, takže jeho absence validaci nebrání.
        $data = $this->validData();
        unset($data['amount_dom']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveComputesAmountDomFromRate(): void
    {
        $data = ['amount' => 100.0, 'exchange_rate' => 1.5];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(150.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveDefaultsRateToOne(): void
    {
        $data = ['amount' => 100.0];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(100.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveKeepsProvidedAmountDom(): void
    {
        $data = ['amount' => 100.0, 'exchange_rate' => 1.5, 'amount_dom' => 142.0];
        $this->doc()->beforeSave($data);
        $this->assertEqualsWithDelta(142.0, $data['amount_dom'], 0.001);
    }

    public function testBeforeSaveLowercasesCurrencyAndTrims(): void
    {
        $data = ['amount' => 10.0, 'currency' => ' CZK ', 'message' => '  pozn  '];
        $this->doc()->beforeSave($data);
        $this->assertSame('czk', $data['currency']);
        $this->assertSame('pozn', $data['message']);
    }
}
