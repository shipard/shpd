<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\World\Vat\VatRateResolver;

class VatCodeResolverTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $countryConfig World.vat.{country} cfgItem stub.
     */
    private function buildResolver(?array $countryConfig, string $country = 'cz'): VatCodeResolver
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id) => ($id === "world.vat.{$country}") ? $countryConfig : null,
        );
        return new VatCodeResolver(new VatRateResolver($config));
    }

    public function testKnownCodeResolvesPctFromDate(): void
    {
        $resolver = $this->buildResolver([
            'vatCodes' => [
                'highEU' => ['reverseVatCode' => 'highEUReverse'],
                'highEUReverse' => [],
            ],
            'vatPercents' => [
                ['code' => 'highEU', 'value' => 21, 'from' => '0000-00-00', 'to' => '0000-00-00'],
            ],
        ]);

        $r = $resolver->resolve('highEU', 'CZ', '2026-04-15');

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(0, $r->matchedId);
        $this->assertSame('cfgItem', $r->matchedBy);
        $this->assertSame(21.0, $r->createPayload['pct']);
        $this->assertSame('highEUReverse', $r->createPayload['reverseVatCode']);
        $this->assertFalse($r->createPayload['noPayTax']);
    }

    public function testUnknownCodeReturnsNotFound(): void
    {
        $resolver = $this->buildResolver(['vatCodes' => ['highEU' => []], 'vatPercents' => []]);

        $r = $resolver->resolve('nope', 'CZ', '2026-04-15');
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testUnknownCountryReturnsNotFound(): void
    {
        $resolver = $this->buildResolver(countryConfig: null);

        $r = $resolver->resolve('highEU', 'XX', '2026-04-15');
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testMissingDateKeepsDeclaredPct(): void
    {
        $resolver = $this->buildResolver([
            'vatCodes' => ['noVat' => ['noPayTax' => true]],
            'vatPercents' => [],
        ]);

        $r = $resolver->resolve('noVat', 'CZ', null, declaredPct: 0.0);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(0.0, $r->createPayload['pct']);
        $this->assertTrue($r->createPayload['noPayTax']);
    }

    public function testNoValidPctForDateFallsBackToDeclared(): void
    {
        $resolver = $this->buildResolver([
            'vatCodes' => ['highEU' => []],
            'vatPercents' => [
                ['code' => 'highEU', 'value' => 21, 'from' => '2025-01-01', 'to' => '0000-00-00'],
            ],
        ]);

        $r = $resolver->resolve('highEU', 'CZ', '1990-01-01', declaredPct: 22.0);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(22.0, $r->createPayload['pct']);
    }

    public function testEmptyInputsReturnNotFound(): void
    {
        $resolver = $this->buildResolver(['vatCodes' => [], 'vatPercents' => []]);

        $this->assertSame(ResolveStatus::NotFound, $resolver->resolve(null, 'CZ', '2026-04-15')->status);
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolve('highEU', null, '2026-04-15')->status);
        $this->assertSame(ResolveStatus::NotFound, $resolver->resolve('', 'CZ', '2026-04-15')->status);
    }
}
