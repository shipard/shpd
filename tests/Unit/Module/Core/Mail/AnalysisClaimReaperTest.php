<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AnalysisClaimReaper;

class AnalysisClaimReaperTest extends TestCase
{
    public function testReturnsEmptyWhenNoExpiredClaims(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->never())->method('begin');
        $db->expects($this->never())->method('updateWhere');
        $db->expects($this->never())->method('execute');

        $reaper = new AnalysisClaimReaper($db);
        $result = $reaper->reapExpired();

        $this->assertSame([], $result);
    }

    public function testReleasesClaimAndRequeuesMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            [
                'id' => 42,
                'message' => 100,
                'analyzer_id' => 'uuid-analyzer-1',
                'claimed_at' => '2026-04-26 10:00:00',
            ],
        ]);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $db->expects($this->once())
            ->method('updateWhere')
            ->with(
                'core_mail_analysis_claims',
                $this->callback(function (array $data): bool {
                    return $data['released'] === 1
                        && $data['release_reason'] === AnalysisClaimReaper::RELEASE_REASON_EXPIRED
                        && !empty($data['released_at']);
                }),
                '%n = %i',
                'id',
                42,
            );

        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE'),
                'core_mail_incoming_messages',
                'analysis_state', 10,
                'modified', $this->anything(),
                'id', 100,
                'analysis_state', 20, // jen pokud se analýza pořád "Analyzuje"
            );

        $reaper = new AnalysisClaimReaper($db);
        $now = new \DateTimeImmutable('2026-04-26 10:05:00');
        $result = $reaper->reapExpired($now);

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['claim_id']);
        $this->assertSame(100, $result[0]['message_id']);
        $this->assertSame('uuid-analyzer-1', $result[0]['analyzer_id']);
        $this->assertSame(300, $result[0]['duration_seconds']);
    }

    public function testRollsBackOnError(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'message' => 10,
                'analyzer_id' => 'a',
                'claimed_at' => '2026-04-26 10:00:00',
            ],
        ]);
        $db->expects($this->once())->method('begin');
        $db->method('updateWhere')->willThrowException(new \RuntimeException('DB down'));
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $reaper = new AnalysisClaimReaper($db);

        $this->expectException(\RuntimeException::class);
        $reaper->reapExpired();
    }

    public function testProcessesMultipleExpiredClaimsInOneTransaction(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'message' => 10, 'analyzer_id' => 'a', 'claimed_at' => '2026-04-26 10:00:00'],
            ['id' => 2, 'message' => 20, 'analyzer_id' => 'b', 'claimed_at' => '2026-04-26 10:01:00'],
            ['id' => 3, 'message' => 30, 'analyzer_id' => 'c', 'claimed_at' => '2026-04-26 10:02:00'],
        ]);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->exactly(3))->method('updateWhere');
        $db->expects($this->exactly(3))->method('execute');

        $reaper = new AnalysisClaimReaper($db);
        $result = $reaper->reapExpired(new \DateTimeImmutable('2026-04-26 10:10:00'));

        $this->assertCount(3, $result);
    }
}
