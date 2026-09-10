<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\Xml\FilingPeriod;

/**
 * Zdaňovací období věty D z rozsahu instance tvrzení (#55 X3). Rozsah,
 * který přesně pokrývá měsíc nebo čtvrtletí, je celé období; užší rozsah
 * (vznik nebo zánik plátcovství uprostřed) je částečné a nese `zdobd_*`.
 */
class FilingPeriodTest extends TestCase
{
    /**
     * @param ?int $month
     * @param ?int $quarter
     */
    #[DataProvider('ranges')]
    public function testRangeBecomesMonthOrQuarter(
        string $begin,
        string $end,
        ?int $month,
        ?int $quarter,
        bool $partial,
    ): void {
        $period = FilingPeriod::fromRange($begin, $end);

        $this->assertSame(2026, $period->year);
        $this->assertSame($month, $period->month);
        $this->assertSame($quarter, $period->quarter);
        $this->assertSame($partial ? $begin : null, $period->from);
        $this->assertSame($partial ? $end : null, $period->to);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?int, 3: ?int, 4: bool}> */
    public static function ranges(): array
    {
        return [
            'celý měsíc'          => ['2026-04-01', '2026-04-30', 4, null, false],
            'únor přestupný'      => ['2026-02-01', '2026-02-28', 2, null, false],
            'prosinec'            => ['2026-12-01', '2026-12-31', 12, null, false],
            'celé čtvrtletí'      => ['2026-07-01', '2026-09-30', null, 3, false],
            'první čtvrtletí'     => ['2026-01-01', '2026-03-31', null, 1, false],
            'část měsíce'         => ['2026-04-10', '2026-04-30', 4, null, true],
            'konec plátcovství'   => ['2026-04-01', '2026-04-20', 4, null, true],
            'část čtvrtletí'      => ['2026-07-15', '2026-09-30', null, 3, true],
            'dva měsíce v Q'      => ['2026-07-01', '2026-08-31', null, 3, true],
        ];
    }

    /** Rozsah přes víc čtvrtletí úřad nezpracuje — validace ho zastaví. */
    public function testRangeAcrossQuartersHasNeitherMonthNorQuarter(): void
    {
        $period = FilingPeriod::fromRange('2026-02-01', '2026-05-31');

        $this->assertNull($period->month);
        $this->assertNull($period->quarter);
        $this->assertFalse($period->isComplete());
        $this->assertSame('2026-02-01', $period->from);
    }

    public function testRangeAcrossYearsKeepsTheStartingYear(): void
    {
        $period = FilingPeriod::fromRange('2026-12-01', '2027-01-31');

        $this->assertSame(2026, $period->year);
        $this->assertFalse($period->isComplete());
    }

    public function testCompletePeriodIsTheUsualCase(): void
    {
        $this->assertTrue(FilingPeriod::fromRange('2026-04-01', '2026-04-30')->isComplete());
        $this->assertTrue(FilingPeriod::fromRange('2026-10-01', '2026-12-31')->isComplete());
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilingPeriod::fromRange('duben 2026', '2026-04-30');
    }
}
