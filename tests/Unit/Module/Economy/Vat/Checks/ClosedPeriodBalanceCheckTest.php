<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\Checks\ClosedPeriodBalanceCheck;

final class TestableClosedPeriodBalanceCheck extends ClosedPeriodBalanceCheck
{
    /** @var list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}> */
    public array $data = [];

    protected function findings(): array
    {
        return $this->data;
    }
}

final class ClosedPeriodBalanceCheckTest extends TestCase
{
    private function check(array $data, string $lang = 'cs'): TestableClosedPeriodBalanceCheck
    {
        $c = new TestableClosedPeriodBalanceCheck(
            $this->createMock(DataSourceConnection::class),
            $this->createMock(ConfigRuntime::class),
            $lang,
        );
        $c->data = $data;
        return $c;
    }

    public function testFindingPerPeriodAndAccount(): void
    {
        $findings = $this->check([
            ['period_id' => 9, 'period_name' => '03/2026', 'date_end' => '2026-03-31', 'account' => '343210', 'balance' => -21000.0],
            ['period_id' => 9, 'period_name' => '03/2026', 'date_end' => '2026-03-31', 'account' => '343120', 'balance' => 1234.56],
        ])->run();

        $this->assertCount(2, $findings);
        $f = $findings[0];
        $this->assertSame('period:9:account:343210', $f->findingKey);
        $this->assertSame('warning', $f->severity);
        $this->assertSame(441, $f->subjectTableId);
        $this->assertSame(9, $f->subjectRowId);
        $this->assertSame('Nevypořádaná DPH za podané přiznání 03/2026: 343210', $f->title);
        $this->assertSame(
            'Za podané přiznání 03/2026 zůstává na 343210 -21 000,00 Kč — chybí zaúčtování přiznání, nebo se DPH po podání změnila.',
            $f->message,
        );
        $this->assertSame('open_viewer', $f->actions[0]['kind']);
        $this->assertSame('economy.vat.reportPeriods', $f->actions[0]['viewerId']);
        $this->assertSame(9, $f->actions[0]['recordId']);
        $this->assertTrue($f->actions[0]['primary']);
        $this->assertSame(['account' => '343210', 'balance' => -21000.0], $f->context);
        $this->assertSame('period:9:account:343120', $findings[1]->findingKey);
    }

    public function testEnglishTexts(): void
    {
        $f = $this->check([
            ['period_id' => 9, 'period_name' => '03/2026', 'date_end' => '2026-03-31', 'account' => '343210', 'balance' => 5.0],
        ], 'en')->run()[0];

        $this->assertSame('Unsettled VAT for filed return 03/2026: 343210', $f->title);
        $this->assertSame('Open period', $f->actions[0]['label']);
    }

    public function testSettledPeriodsAreSilent(): void
    {
        $this->assertSame([], $this->check([])->run());
    }
}
