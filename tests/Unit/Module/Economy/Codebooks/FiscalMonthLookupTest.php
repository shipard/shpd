<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\FiscalMonthLookup;

final class FiscalMonthLookupTest extends TestCase
{
    public function testReturnsIdOfCoveringRegularMonth(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetch')
            ->with(
                $this->stringContains('[period_type] = %i'),
                '2026-05-15', '2026-05-15', FiscalMonthLookup::PERIOD_TYPE_REGULAR,
            )
            ->willReturn(new Row(['id' => 105]));

        $this->assertSame(105, FiscalMonthLookup::monthIdForDate($db, '2026-05-15'));
    }

    public function testDateTimeAndDatetimeStringAreNormalized(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->with($this->anything(), '2026-05-15', '2026-05-15', 1)->willReturn(new Row(['id' => 7]));

        $this->assertSame(7, FiscalMonthLookup::monthIdForDate($db, '2026-05-15 13:45:00'));
        $this->assertSame('2026-05-15', FiscalMonthLookup::isoDate(new \DateTimeImmutable('2026-05-15 08:00')));
        $this->assertNull(FiscalMonthLookup::isoDate(''));
        $this->assertNull(FiscalMonthLookup::isoDate(null));
    }

    public function testNoMonthReturnsNullAndEmptyDateSkipsQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $this->assertNull(FiscalMonthLookup::monthIdForDate($db, '1999-01-01'));

        $silent = $this->createMock(Connection::class);
        $silent->expects($this->never())->method('fetch');
        $this->assertNull(FiscalMonthLookup::monthIdForDate($silent, ''));
    }
}
