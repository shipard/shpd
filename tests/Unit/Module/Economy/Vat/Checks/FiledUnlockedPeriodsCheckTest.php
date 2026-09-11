<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\Checks\FiledUnlockedPeriodsCheck;

final class TestableFiledUnlockedPeriodsCheck extends FiledUnlockedPeriodsCheck
{
    /** @var list<array{id: int, name: string, date_filed: string}> */
    public array $data = [];

    protected function rows(): array
    {
        return $this->data;
    }
}

final class FiledUnlockedPeriodsCheckTest extends TestCase
{
    private function check(array $data, string $lang = 'cs'): TestableFiledUnlockedPeriodsCheck
    {
        $c = new TestableFiledUnlockedPeriodsCheck(
            $this->createMock(DataSourceConnection::class),
            $this->createMock(ConfigRuntime::class),
            $lang,
        );
        $c->data = $data;
        return $c;
    }

    public function testFindingPerUnlockedFiledPeriod(): void
    {
        $findings = $this->check([
            ['id' => 12, 'name' => 'Q1/2026', 'date_filed' => '2026-04-20'],
        ])->run();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('period:12', $f->findingKey);
        $this->assertSame('Podané přiznání Q1/2026 není uzamčené', $f->title);
        $this->assertStringContainsString('2026-04-20', $f->message);
        $this->assertStringContainsString('Uzamkněte tvrzení', $f->message);
        $this->assertSame('warning', $f->severity);
        $this->assertSame(441, $f->subjectTableId);
        $this->assertSame(12, $f->subjectRowId);
        $this->assertSame('open_viewer', $f->actions[0]['kind']);
        $this->assertSame('economy.vat.reportPeriods', $f->actions[0]['viewerId']);
        $this->assertSame(12, $f->actions[0]['recordId']);
    }

    public function testEnglishTexts(): void
    {
        $f = $this->check([['id' => 12, 'name' => 'Q1/2026', 'date_filed' => '2026-04-20']], 'en')->run()[0];

        $this->assertSame('Filed return Q1/2026 is not locked', $f->title);
        $this->assertSame('Open period', $f->actions[0]['label']);
    }

    public function testNothingToReportIsSilent(): void
    {
        $this->assertSame([], $this->check([])->run());
    }

    public function testGraceIsThreeDays(): void
    {
        $this->assertSame(3, FiledUnlockedPeriodsCheck::GRACE_DAYS);
    }
}
