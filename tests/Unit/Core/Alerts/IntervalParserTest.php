<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Alerts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\IntervalParser;

class IntervalParserTest extends TestCase
{
    #[DataProvider('validIntervalsProvider')]
    public function testParsesValidIntervals(string $input, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, IntervalParser::parse($input));
    }

    public static function validIntervalsProvider(): array
    {
        return [
            'seconds' => ['30s', 30],
            'minutes — 5m'  => ['5m', 300],
            'minutes — 30m' => ['30m', 1800],
            'hours — 1h'    => ['1h', 3600],
            'hours — 4h'    => ['4h', 14400],
            'days — 1d'     => ['1d', 86400],
            'days — 7d'     => ['7d', 7 * 86400],
            'large value'   => ['365d', 365 * 86400],
        ];
    }

    #[DataProvider('invalidIntervalsProvider')]
    public function testRejectsInvalidIntervals(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IntervalParser::parse($input);
    }

    public static function invalidIntervalsProvider(): array
    {
        return [
            'empty'           => [''],
            'no suffix'       => ['10'],
            'unknown suffix'  => ['10w'],          // weeks not supported
            'uppercase'       => ['1H'],
            'leading sign'    => ['+1h'],
            'negative'        => ['-1h'],
            'decimal'         => ['1.5h'],
            'multi-segment'   => ['1h30m'],
            'whitespace'      => [' 1h'],
            'trailing space'  => ['1h '],
            'zero seconds'    => ['0s'],
            'zero hours'      => ['0h'],
            'iso format'      => ['PT1H'],
            'gibberish'       => ['abc'],
        ];
    }
}
