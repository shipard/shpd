<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\EnumOptionsHelper;

class EnumOptionsHelperTest extends TestCase
{
    public function testCurrenciesGetAlpha3Prefix(): void
    {
        $cfg = [
            'czk' => ['alpha3' => 'CZK', 'name' => 'Czech Koruna'],
            'eur' => ['alpha3' => 'EUR', 'name' => 'Euro'],
        ];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');

        $this->assertSame([
            ['value' => 'czk', 'label' => 'CZK — Czech Koruna'],
            ['value' => 'eur', 'label' => 'EUR — Euro'],
        ], $options);
    }

    public function testCountriesHaveNoPrefixDespiteAlpha3(): void
    {
        $cfg = [
            'ad' => ['alpha3' => 'and', 'name' => 'Andorra'],
            'cz' => ['alpha3' => 'cze', 'name' => 'Czechia'],
        ];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.countries');

        $this->assertSame([
            ['value' => 'ad', 'label' => 'Andorra'],
            ['value' => 'cz', 'label' => 'Czechia'],
        ], $options);
    }

    public function testIntEnumKeepsIntValueAndNoPrefix(): void
    {
        $cfg = [
            0 => ['name' => 'Plátce'],
            1 => ['name' => 'Identifikovaná osoba'],
        ];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumInt', 'economy.codebooks.vatTaxpayerKinds');

        $this->assertSame([
            ['value' => 0, 'label' => 'Plátce'],
            ['value' => 1, 'label' => 'Identifikovaná osoba'],
        ], $options);
        $this->assertIsInt($options[0]['value']);
    }

    public function testNullCfgItemIdNeverPrefixes(): void
    {
        $cfg = ['czk' => ['alpha3' => 'CZK', 'name' => 'Czech Koruna']];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumString');

        $this->assertSame([['value' => 'czk', 'label' => 'Czech Koruna']], $options);
    }

    public function testEntriesWithoutNameAreSkipped(): void
    {
        $cfg = [
            'aaa' => ['alpha3' => 'AAA'],
            'czk' => ['alpha3' => 'CZK', 'name' => 'Czech Koruna'],
            'bbb' => 'not-an-array',
        ];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');

        $this->assertSame([['value' => 'czk', 'label' => 'CZK — Czech Koruna']], $options);
    }

    public function testCurrencyWithoutAlpha3FallsBackToName(): void
    {
        $cfg = ['xxx' => ['name' => 'No alpha3 currency']];

        $options = EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');

        $this->assertSame([['value' => 'xxx', 'label' => 'No alpha3 currency']], $options);
    }
}
