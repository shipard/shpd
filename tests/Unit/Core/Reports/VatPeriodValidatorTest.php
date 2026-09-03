<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportParamValidator;
use Shipard\Core\Reports\VatPeriodProvider;

/**
 * periodSource 'vatPeriod': deklarace + validace parametrů
 * (vatRegistration + dateFrom/dateTo → VatPeriodRange).
 */
class VatPeriodValidatorTest extends TestCase
{
    // ── ReportDefinition::fromArray — periodSource ──────────────────────────

    public function testDefinitionVatPeriodWithoutGranularities(): void
    {
        $definition = ReportDefinition::fromArray([
            'id'           => 'economy.vat.returnLive',
            'name'         => 'Přiznání k DPH — živě',
            'builder'      => 'X',
            'periodSource' => 'vatPeriod',
        ], 'economy.vat');

        $this->assertSame('vatPeriod', $definition->periodSource);
        $this->assertSame([], $definition->periodGranularities);
    }

    public function testDefinitionDefaultsToFiscal(): void
    {
        $definition = ReportDefinition::fromArray([
            'id'                  => 'test.report',
            'name'                => 'Test',
            'builder'             => 'X',
            'periodGranularities' => ['month'],
        ], 'test.module');

        $this->assertSame('fiscal', $definition->periodSource);
    }

    public function testDefinitionVatPeriodRejectsGranularities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must not be declared for periodSource 'vatPeriod'");
        ReportDefinition::fromArray([
            'id'                  => 'test.report',
            'name'                => 'Test',
            'builder'             => 'X',
            'periodSource'        => 'vatPeriod',
            'periodGranularities' => ['month'],
        ], 'test.module');
    }

    public function testDefinitionRejectsUnknownPeriodSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'periodSource' must be one of");
        ReportDefinition::fromArray([
            'id'           => 'test.report',
            'name'         => 'Test',
            'builder'      => 'X',
            'periodSource' => 'calendar',
        ], 'test.module');
    }

    // ── ReportParamValidator — vatPeriod větev ──────────────────────────────

    /** Registrace 5 s měsíčními obdobími 01–03/2026 (03 locked). */
    private function fakeVatPeriods(): VatPeriodProvider
    {
        return new class implements VatPeriodProvider {
            public function findRegistration(int $id): ?array
            {
                return $id === 5 ? ['id' => 5, 'name' => 'CZ plátce'] : null;
            }

            public function periodsOfRegistration(int $registrationId): array
            {
                if ($registrationId !== 5) {
                    return [];
                }
                return [
                    ['id' => 51, 'name' => '01/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-01-31', 'locked' => false],
                    ['id' => 52, 'name' => '02/2026', 'dateBegin' => '2026-02-01', 'dateEnd' => '2026-02-28', 'locked' => false],
                    ['id' => 53, 'name' => '03/2026', 'dateBegin' => '2026-03-01', 'dateEnd' => '2026-03-31', 'locked' => true],
                ];
            }

            public function registrationsWithPeriods(): array
            {
                return [];
            }
        };
    }

    /** Provider s mezerou — období 02/2026 chybí. */
    private function fakeVatPeriodsWithGap(): VatPeriodProvider
    {
        return new class implements VatPeriodProvider {
            public function findRegistration(int $id): ?array
            {
                return $id === 5 ? ['id' => 5, 'name' => 'CZ plátce'] : null;
            }

            public function periodsOfRegistration(int $registrationId): array
            {
                return [
                    ['id' => 51, 'name' => '01/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-01-31', 'locked' => false],
                    ['id' => 53, 'name' => '03/2026', 'dateBegin' => '2026-03-01', 'dateEnd' => '2026-03-31', 'locked' => false],
                ];
            }

            public function registrationsWithPeriods(): array
            {
                return [];
            }
        };
    }

    private function fakeFiscalPeriods(): FiscalPeriodProvider
    {
        return new class implements FiscalPeriodProvider {
            public function findYearByName(string $name): ?array
            {
                return null;
            }

            public function monthsOfYear(int $fiscalYearId): array
            {
                return [];
            }

            public function regularYears(): array
            {
                return [];
            }
        };
    }

    private function definition(): ReportDefinition
    {
        return new ReportDefinition(
            id: 'test.vat',
            name: 'Test VAT',
            builderClass: 'X',
            periodGranularities: [],
            params: [],
            moduleId: 'test.module',
            periodSource: 'vatPeriod',
        );
    }

    private function validator(?VatPeriodProvider $vatPeriods = null): ReportParamValidator
    {
        return new ReportParamValidator(
            $this->fakeFiscalPeriods(),
            $vatPeriods ?? $this->fakeVatPeriods(),
        );
    }

    public function testValidatorResolvesSinglePeriod(): void
    {
        $result = $this->validator()->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28',
        ]);

        $this->assertNull($result['range']);
        $vatRange = $result['vatRange'];
        $this->assertSame(5, $vatRange->registrationId);
        $this->assertSame('CZ plátce', $vatRange->registrationName);
        $this->assertSame('2026-02-01', $vatRange->dateBegin);
        $this->assertSame('2026-02-28', $vatRange->dateEnd);
        $this->assertSame([52], $vatRange->periodIds);
        $this->assertSame(['02/2026'], $vatRange->periodNames);

        $this->assertSame(
            ['vatRegistration' => 5, 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'],
            $result['params']['period'],
        );
    }

    public function testValidatorResolvesMergedQuarter(): void
    {
        $result = $this->validator()->validate($this->definition(), [
            'vatRegistration' => 5, 'dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31',
        ]);

        $this->assertSame([51, 52, 53], $result['vatRange']->periodIds);
        $this->assertSame(['01/2026', '02/2026', '03/2026'], $result['vatRange']->periodNames);
    }

    public function testValidatorRejectsGapInCoverage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exactly cover VAT periods');
        $this->validator($this->fakeVatPeriodsWithGap())->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31',
        ]);
    }

    public function testValidatorRejectsMisalignedDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exactly cover VAT periods');
        $this->validator()->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-01-15', 'dateTo' => '2026-02-28',
        ]);
    }

    public function testValidatorRejectsUnknownRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("VAT registration '9' does not exist");
        $this->validator()->validate($this->definition(), [
            'vatRegistration' => '9', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31',
        ]);
    }

    public function testValidatorRejectsMissingRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid required parameter 'vatRegistration'");
        $this->validator()->validate($this->definition(), [
            'dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31',
        ]);
    }

    public function testValidatorRejectsBadDateFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter 'dateFrom' must be a date in YYYY-MM-DD format");
        $this->validator()->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '1.1.2026', 'dateTo' => '2026-01-31',
        ]);
    }

    public function testValidatorRejectsFromAfterTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'dateFrom' must not be greater than 'dateTo'");
        $this->validator()->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-02-01', 'dateTo' => '2026-01-31',
        ]);
    }

    public function testValidatorRejectsFiscalParamsOnVatReport(): void
    {
        // fiscalYear není u vatPeriod reportu rezervovaný klíč → neznámý parametr.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown parameter 'fiscalYear'");
        $this->validator()->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31',
            'fiscalYear'      => '2026',
        ]);
    }

    public function testValidatorRequiresProviderWiring(): void
    {
        $validator = new ReportParamValidator($this->fakeFiscalPeriods());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("requires a VatPeriodProvider");
        $validator->validate($this->definition(), [
            'vatRegistration' => '5', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31',
        ]);
    }
}
