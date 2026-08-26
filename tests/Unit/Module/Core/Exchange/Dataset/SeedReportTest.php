<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;

class SeedReportTest extends TestCase
{
    public function testCountsErrorsAndWarnings(): void
    {
        $r = new SeedReport();
        $r->touch('setup');
        $r->ok('persons');
        $r->ok('persons');
        $r->failed('persons', 'x: boom');
        $r->skipped('docs', 'y: not active');
        $r->warning('free-form');

        $this->assertSame([
            'setup'   => ['ok' => 0, 'failed' => 0, 'skipped' => 0],
            'persons' => ['ok' => 2, 'failed' => 1, 'skipped' => 0],
            'docs'    => ['ok' => 0, 'failed' => 0, 'skipped' => 1],
        ], $r->counts());
        $this->assertSame(3, $r->processed('persons'));
        $this->assertSame(0, $r->processed('mail'));
        $this->assertSame(['persons: x: boom'], $r->errors());
        $this->assertSame(['docs: y: not active', 'free-form'], $r->warnings());
        $this->assertTrue($r->hasErrors());
        $this->assertFalse((new SeedReport())->hasErrors());
    }
}
