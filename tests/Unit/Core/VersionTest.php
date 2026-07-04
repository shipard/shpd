<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Version;

class VersionTest extends TestCase
{
    public function testFullStartsWithVersion(): void
    {
        $this->assertStringStartsWith(Version::VERSION, Version::full());
    }

    public function testGitHashIsNullOrShortHex(): void
    {
        $hash = Version::gitHash();
        if ($hash !== null) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $hash);
        } else {
            $this->assertNull($hash);
        }
    }

    public function testFullFormatMatchesGitHashAvailability(): void
    {
        $hash = Version::gitHash();
        if ($hash === null) {
            $this->assertSame(Version::VERSION, Version::full());
        } else {
            $this->assertSame(Version::VERSION . ' (' . $hash . ')', Version::full());
        }
    }

    public function testGitHashIsStableAcrossCalls(): void
    {
        $this->assertSame(Version::gitHash(), Version::gitHash());
    }
}
