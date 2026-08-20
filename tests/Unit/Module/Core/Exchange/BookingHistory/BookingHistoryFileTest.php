<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\BookingHistory;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFormatException;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryRecord;

/**
 * Parser a validátor formátu `shpd.economy.booking-history.v1`
 * (docs/booking-history-format.md).
 */
class BookingHistoryFileTest extends TestCase
{
    private function fixture(string $name): string
    {
        return dirname(__DIR__, 5) . '/Fixtures/Exchange/bookingHistory/' . $name;
    }

    public function testHeaderOfValidFile(): void
    {
        $file = BookingHistoryFile::open($this->fixture('valid.jsonl'));
        $header = $file->header;

        $this->assertSame('default', $header->chartVariant);
        $this->assertSame('CZK', $header->currency);
        $this->assertSame('shipard-e10', $header->sourceSystem['name']);
        $this->assertSame('1.2', $header->sourceSystem['version']);
        $this->assertSame('ds abcd-efgh', $header->sourceRef);
        $this->assertSame(['from' => '2019-01-01', 'to' => '2026-06-30'], $header->period);
        $this->assertSame(['invni'], $header->docTypes);
        $this->assertSame(4, $header->recordCount);
        $this->assertSame('shipard-e10 1.2 (ds abcd-efgh)', $header->sourceLabel());
        $this->assertFalse($header->chartVariantIsGuessed());
        $this->assertSame('default', $header->effectiveChartVariant());
    }

    public function testRecordsAreStreamedAndNormalized(): void
    {
        $file = BookingHistoryFile::open($this->fixture('valid.jsonl'));
        $records = iterator_to_array($file->records(), false);

        $this->assertCount(4, $records);

        // IČO se normalizuje (mezery ven), text zůstává v originále.
        $this->assertSame('26378191', $records[0]->companyId);
        $this->assertSame('Měsíční  paušál za internet 100/10', $records[0]->rowText);
        $this->assertSame('měsíční paušál za internet 100/10', $records[0]->rowTextNorm());
        $this->assertSame(74, $records[0]->docCount);
        $this->assertSame(96, $records[0]->rowCount);
        $this->assertSame(366300.0, $records[0]->totalAmount);

        // docCount jako string a totalAmount jako string jsou legální vstupy.
        $this->assertSame(2, $records[2]->docCount);
        $this->assertSame(890.0, $records[3]->totalAmount);
        $this->assertNull($records[2]->totalAmount);

        // Nullová pravidla: companyId i account smí být null.
        $this->assertNull($records[2]->companyId);
        $this->assertNull($records[2]->account);
    }

    public function testGeneratorCanBeTraversedTwice(): void
    {
        $file = BookingHistoryFile::open($this->fixture('valid.jsonl'));
        $this->assertCount(4, iterator_to_array($file->records(), false));
        $this->assertCount(4, iterator_to_array($file->records(), false));
    }

    public function testDegeneracyDetection(): void
    {
        $file = BookingHistoryFile::open($this->fixture('valid.jsonl'));
        $records = iterator_to_array($file->records(), false);

        $this->assertNull($records[0]->degeneracy());
        $this->assertTrue($records[0]->hasContentfulText());
        $this->assertSame(BookingHistoryRecord::DEGENERACY_ITEM_NAME, $records[1]->degeneracy());
        $this->assertSame(BookingHistoryRecord::DEGENERACY_EMPTY, $records[2]->degeneracy());
        $this->assertSame(BookingHistoryRecord::DEGENERACY_ACCOUNT, $records[3]->degeneracy());
        $this->assertFalse($records[3]->hasContentfulText());
    }

    public function testHeaderOnlyFileIsValid(): void
    {
        $file = BookingHistoryFile::open($this->fixture('headerOnly.jsonl'));
        $this->assertSame([], iterator_to_array($file->records(), false));
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->expectException(BookingHistoryFormatException::class);
        $this->expectExceptionMessageMatches('/řádek 1:.*neznámý formát/u');
        BookingHistoryFile::open($this->fixture('brokenHeader.jsonl'));
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $this->expectException(BookingHistoryFormatException::class);
        $this->expectExceptionMessageMatches('/verze formátu 2 není podporovaná/u');
        BookingHistoryFile::open($this->fixture('badVersion.jsonl'));
    }

    public function testBadRecordReportsItsLineNumber(): void
    {
        $file = BookingHistoryFile::open($this->fixture('badRecord.jsonl'));
        try {
            iterator_to_array($file->records(), false);
            $this->fail('Expected BookingHistoryFormatException');
        } catch (BookingHistoryFormatException $e) {
            $this->assertSame(3, $e->fileLine);
            $this->assertStringContainsString('docCount', $e->getMessage());
        }
    }

    public function testBrokenJsonReportsItsLineNumber(): void
    {
        $file = BookingHistoryFile::open($this->fixture('brokenJson.jsonl'));
        try {
            iterator_to_array($file->records(), false);
            $this->fail('Expected BookingHistoryFormatException');
        } catch (BookingHistoryFormatException $e) {
            $this->assertSame(2, $e->fileLine);
            $this->assertStringContainsString('nevalidní JSON', $e->getMessage());
        }
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(BookingHistoryFormatException::class);
        BookingHistoryFile::open($this->fixture('nope.jsonl'));
    }

    public function testEmptyFileIsRejected(): void
    {
        $path = sys_get_temp_dir() . '/bh-empty-' . uniqid() . '.jsonl';
        file_put_contents($path, "\n\n");
        try {
            $this->expectException(BookingHistoryFormatException::class);
            $this->expectExceptionMessageMatches('/chybí hlavička/u');
            BookingHistoryFile::open($path);
        } finally {
            @unlink($path);
        }
    }

    public function testUnknownChartVariantIsRejectedButUnknownLiteralIsNot(): void
    {
        $dir = sys_get_temp_dir() . '/bh-variant-' . uniqid();
        mkdir($dir);
        $bad = $dir . '/bad.jsonl';
        $ok  = $dir . '/ok.jsonl';
        file_put_contents($bad, '{"format":"shpd.economy.booking-history","version":1,"chartVariant":"farm"}' . "\n");
        file_put_contents($ok, '{"format":"shpd.economy.booking-history","version":1,"chartVariant":"unknown"}' . "\n");
        try {
            $header = BookingHistoryFile::open($ok)->header;
            $this->assertTrue($header->chartVariantIsGuessed());
            $this->assertSame('default', $header->effectiveChartVariant());
            $this->assertSame('CZK', $header->currency); // default při chybějícím poli

            $this->expectException(BookingHistoryFormatException::class);
            BookingHistoryFile::open($bad);
        } finally {
            @unlink($bad);
            @unlink($ok);
            @rmdir($dir);
        }
    }
}
