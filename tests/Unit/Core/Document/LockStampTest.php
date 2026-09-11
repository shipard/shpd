<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\LockStamp;

final class LockStampTest extends TestCase
{
    public function testLockingWithUserStampsTimeAndUser(): void
    {
        $data = ['id' => 1, 'locked' => 1];
        LockStamp::apply($data, ['id' => 1, 'locked' => 0], 7, '2026-09-11 10:00:00');

        $this->assertSame('2026-09-11 10:00:00', $data['locked_at']);
        $this->assertSame(7, $data['locked_by']);
    }

    public function testLockingWithoutUserLeavesFieldsAlone(): void
    {
        $data = ['id' => 1, 'locked' => 1];
        LockStamp::apply($data, ['id' => 1, 'locked' => 0], null, '2026-09-11 10:00:00');

        $this->assertArrayNotHasKey('locked_at', $data);
        $this->assertArrayNotHasKey('locked_by', $data);
    }

    public function testProvidedStampIsRespected(): void
    {
        $data = ['locked' => true, 'locked_at' => '2020-01-01 00:00:00', 'locked_by' => 3];
        LockStamp::apply($data, null, 7, '2026-09-11 10:00:00');

        $this->assertSame('2020-01-01 00:00:00', $data['locked_at']);
        $this->assertSame(3, $data['locked_by']);
    }

    public function testUnlockingClearsStamp(): void
    {
        $data = ['id' => 1, 'locked' => 0, 'locked_at' => '2020-01-01 00:00:00', 'locked_by' => 3];
        LockStamp::apply($data, ['id' => 1, 'locked' => 1], 7);

        $this->assertNull($data['locked_at']);
        $this->assertNull($data['locked_by']);
    }

    public function testNoLockedKeyIsNoop(): void
    {
        $data = ['id' => 1, 'name' => 'x'];
        LockStamp::apply($data, ['id' => 1, 'locked' => 1], 7);

        $this->assertSame(['id' => 1, 'name' => 'x'], $data);
    }

    public function testAlreadyLockedStaysUntouched(): void
    {
        $data = ['id' => 1, 'locked' => 1, 'name' => 'renamed'];
        LockStamp::apply($data, ['id' => 1, 'locked' => 1, 'locked_at' => '2020-01-01 00:00:00'], 7);

        $this->assertArrayNotHasKey('locked_at', $data);
    }

    public function testDefaultNowIsCurrentTimestampFormat(): void
    {
        $data = ['locked' => 1];
        LockStamp::apply($data, null, 7);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['locked_at']);
    }
}
