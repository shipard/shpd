<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxAlertCheck;

class MailOutboxAlertCheckTest extends TestCase
{
    public const NOW = '2026-07-13 10:00:00';

    /**
     * @param ?array $failedRow výsledek dotazu na failed za 24 h
     * @param ?array $stuckRow  výsledek dotazu na overdue pending
     */
    private function makeCheck(?array $failedRow, ?array $stuckRow, string $language = 'cs'): MailOutboxAlertCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $queries = [];
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, ...$args) use (&$queries, $failedRow, $stuckRow) {
                $queries[] = [$sql, $args];
                return str_contains($sql, "state = 'failed'") ? $failedRow : $stuckRow;
            },
        );

        return new class ($db, $this->createMock(ConfigRuntime::class), $language) extends MailOutboxAlertCheck {
            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(MailOutboxAlertCheckTest::NOW);
            }
        };
    }

    public function testHealthyQueueYieldsNoFindings(): void
    {
        $check = $this->makeCheck(
            ['cnt' => 0, 'oldest' => null],
            ['cnt' => 0, 'oldest' => null],
        );

        $this->assertSame([], $check->run());
    }

    public function testFailedMessagesYieldFinding(): void
    {
        $check = $this->makeCheck(
            ['cnt' => 3, 'oldest' => '2026-07-12 15:00:00'],
            ['cnt' => 0, 'oldest' => null],
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('failed_24h', $findings[0]->findingKey);
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertStringContainsString('3', $findings[0]->message);
        $this->assertStringContainsString('2026-07-12 15:00:00', $findings[0]->message);
    }

    public function testStuckPendingYieldsFinding(): void
    {
        $check = $this->makeCheck(
            ['cnt' => 0, 'oldest' => null],
            ['cnt' => 5, 'oldest' => '2026-07-13 09:00:00'],
            'en',
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('stuck_pending', $findings[0]->findingKey);
        $this->assertStringContainsString('mail-send-test', $findings[0]->message);
    }

    public function testBothProblemsYieldTwoFindings(): void
    {
        $check = $this->makeCheck(
            ['cnt' => 1, 'oldest' => '2026-07-12 15:00:00'],
            ['cnt' => 2, 'oldest' => '2026-07-13 09:00:00'],
        );

        $findings = $check->run();

        $this->assertCount(2, $findings);
        $this->assertSame(['failed_24h', 'stuck_pending'], array_map(
            static fn ($f) => $f->findingKey,
            $findings,
        ));
    }
}
