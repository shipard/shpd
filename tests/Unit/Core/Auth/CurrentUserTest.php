<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Auth;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\CurrentUser;

final class CurrentUserTest extends TestCase
{
    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    public function testDefaultsToNullAndHoldsValue(): void
    {
        CurrentUser::reset();
        $this->assertNull(CurrentUser::id());

        CurrentUser::set(42);
        $this->assertSame(42, CurrentUser::id());

        CurrentUser::set(null);
        $this->assertNull(CurrentUser::id());
    }
}
