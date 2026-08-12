<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Settings\LayerCParameters;

class LayerCParametersTest extends TestCase
{
    public function testKeysListsAllParameters(): void
    {
        $keys = LayerCParameters::keys();

        $this->assertContains('economy.accountChart', $keys);
        $this->assertContains('economy.fiscalYearStartMonth', $keys);
        $this->assertContains('economy.vatAgenda', $keys);
    }

    public function testAccountChartAcceptsWhitelistedVariants(): void
    {
        foreach (['default', 'npo', 'none'] as $variant) {
            $this->assertSame($variant, LayerCParameters::validate('economy.accountChart', $variant));
        }
    }

    public function testAccountChartRejectsUnknownVariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LayerCParameters::validate('economy.accountChart', 'foo');
    }

    public function testFiscalYearStartMonthAcceptsRangeAsInt(): void
    {
        $this->assertSame(1, LayerCParameters::validate('economy.fiscalYearStartMonth', '1'));
        $this->assertSame(12, LayerCParameters::validate('economy.fiscalYearStartMonth', '12'));
    }

    public function testFiscalYearStartMonthRejectsOutOfRange(): void
    {
        foreach (['0', '13', 'leden', '-1'] as $invalid) {
            try {
                LayerCParameters::validate('economy.fiscalYearStartMonth', $invalid);
                $this->fail("value '{$invalid}' should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testVatAgendaAcceptsBooleanShapes(): void
    {
        $this->assertTrue(LayerCParameters::validate('economy.vatAgenda', 'true'));
        $this->assertTrue(LayerCParameters::validate('economy.vatAgenda', '1'));
        $this->assertFalse(LayerCParameters::validate('economy.vatAgenda', 'false'));
        $this->assertFalse(LayerCParameters::validate('economy.vatAgenda', '0'));
    }

    public function testVatAgendaRejectsAnythingElse(): void
    {
        foreach (['yes', 'ano', 'TRUE', ''] as $invalid) {
            try {
                LayerCParameters::validate('economy.vatAgenda', $invalid);
                $this->fail("value '{$invalid}' should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnknownKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LayerCParameters::validate('economy.unknown', 'x');
    }
}
