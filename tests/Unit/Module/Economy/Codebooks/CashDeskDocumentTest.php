<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\CashDeskDocument;

class CashDeskDocumentTest extends TestCase
{
    private function doc(): CashDeskDocument
    {
        return new CashDeskDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'code'       => 'HP1',
            'name'       => 'Hlavní pokladna',
            'currency'   => 'czk',
            'is_default' => 0,
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

    public function testMissingCurrencyFails(): void
    {
        $data = $this->validData();
        unset($data['currency']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testUppercaseCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'CZK';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'invalid_format',
        );
        $this->assertNotEmpty($errors);
    }

    public function testShortCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'cz';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'invalid_format',
        );
        $this->assertNotEmpty($errors);
    }

    public function testTooLongCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'czechk';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'invalid_format',
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

    public function testBeforeSaveNormalizesCurrency(): void
    {
        $doc = $this->doc();
        $data = ['currency' => '  CZK '];
        $doc->beforeSave($data);

        $this->assertSame('czk', $data['currency']);
    }

    public function testBeforeSaveTrimsTextFields(): void
    {
        $doc = $this->doc();
        $data = [
            'code'   => '  HP1  ',
            'name'   => "  Hlavní pokladna\n",
            'notice' => '  poznámka  ',
        ];
        $doc->beforeSave($data);

        $this->assertSame('HP1', $data['code']);
        $this->assertSame('Hlavní pokladna', $data['name']);
        $this->assertSame('poznámka', $data['notice']);
    }

    // --- afterPersist -------------------------------------------------------
    //
    // afterPersist deleguje DB volání do protected clearOtherDefaults();
    // testujeme přes subclass spy, protože Dibi\Connection::query() je final.

    public function testAfterPersistSkipsUpdateWhenNotDefault(): void
    {
        $doc = new TestableCashDeskDocument();
        $doc->afterPersist([
            'id'         => 1,
            'currency'   => 'czk',
            'is_default' => 0,
        ]);

        $this->assertSame(0, $doc->clearCalls);
    }

    public function testAfterPersistRunsUpdateWhenDefault(): void
    {
        $doc = new TestableCashDeskDocument();
        $doc->afterPersist([
            'id'         => 42,
            'currency'   => 'czk',
            'is_default' => 1,
        ]);

        $this->assertSame(1, $doc->clearCalls);
        $this->assertSame('czk', $doc->lastCurrency);
        $this->assertSame(42, $doc->lastId);
    }

    public function testAfterPersistSkipsWhenIdMissing(): void
    {
        $doc = new TestableCashDeskDocument();
        $doc->afterPersist([
            'currency'   => 'czk',
            'is_default' => 1,
        ]);

        $this->assertSame(0, $doc->clearCalls);
    }
}

/**
 * Testovací subclass — overriduje protected clearOtherDefaults, aby
 * šlo testovat bez reálného Dibi (final query() nelze mockovat).
 */
class TestableCashDeskDocument extends CashDeskDocument
{
    public int $clearCalls = 0;
    public ?string $lastCurrency = null;
    public ?int $lastId = null;

    protected function clearOtherDefaults(string $currency, int $id): void
    {
        $this->clearCalls++;
        $this->lastCurrency = $currency;
        $this->lastId = $id;
    }
}
