<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\DeductionCoefficientResolver;

/**
 * Testovatelná varianta — řádky z in-memory seznamu
 * {vat_registration, year, coefficient_provisional, coefficient_settled, docState}.
 */
final class TestableDeductionCoefficientResolver extends DeductionCoefficientResolver
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    protected function loadRow(int $registrationId, int $year): ?array
    {
        foreach ($this->rows as $row) {
            if ((int) $row['vat_registration'] === $registrationId && (int) $row['year'] === $year
                && (int) ($row['docState'] ?? 40) === 40) {
                return [
                    'coefficient_provisional' => $row['coefficient_provisional'],
                    'coefficient_settled'     => $row['coefficient_settled'],
                ];
            }
        }
        return null;
    }
}

final class DeductionCoefficientResolverTest extends TestCase
{
    private function resolver(array $rows): TestableDeductionCoefficientResolver
    {
        $r = new TestableDeductionCoefficientResolver();
        $r->rows = $rows;
        return $r;
    }

    private function row(int $year, ?float $prov, ?float $settled, int $state = 40, int $reg = 5): array
    {
        return [
            'vat_registration' => $reg, 'year' => $year,
            'coefficient_provisional' => $prov, 'coefficient_settled' => $settled, 'docState' => $state,
        ];
    }

    public function testExplicitProvisionalWins(): void
    {
        $r = $this->resolver([
            $this->row(2025, null, 0.70),
            $this->row(2026, 0.80, null),
        ]);

        $this->assertSame(
            ['value' => 0.8, 'source' => DeductionCoefficientResolver::SOURCE_PROVISIONAL],
            $r->provisional(5, 2026),
        );
    }

    public function testPreviousYearSettledIsImplicitProvisional(): void
    {
        $r = $this->resolver([
            $this->row(2025, 0.90, 0.70),
            $this->row(2026, null, null),
        ]);

        $this->assertSame(
            ['value' => 0.7, 'source' => DeductionCoefficientResolver::SOURCE_PREVIOUS_SETTLED],
            $r->provisional(5, 2026),
        );
    }

    public function testPreviousYearWithoutSettlementFallsToDefault(): void
    {
        $r = $this->resolver([$this->row(2025, 0.90, null)]);

        $this->assertSame(
            ['value' => 1.0, 'source' => DeductionCoefficientResolver::SOURCE_DEFAULT],
            $r->provisional(5, 2026),
        );
    }

    public function testNoRowsIsDefault(): void
    {
        $this->assertSame(
            ['value' => 1.0, 'source' => DeductionCoefficientResolver::SOURCE_DEFAULT],
            $this->resolver([])->provisional(5, 2026),
        );
    }

    public function testDraftAndDeletedRowsAreIgnored(): void
    {
        $r = $this->resolver([
            $this->row(2026, 0.50, null, 10),
            $this->row(2025, null, 0.60, 90),
        ]);

        $this->assertSame(DeductionCoefficientResolver::SOURCE_DEFAULT, $r->provisional(5, 2026)['source']);
    }

    public function testOtherRegistrationDoesNotLeak(): void
    {
        $r = $this->resolver([$this->row(2026, 0.50, null, 40, 6)]);

        $this->assertSame(DeductionCoefficientResolver::SOURCE_DEFAULT, $r->provisional(5, 2026)['source']);
    }
}
