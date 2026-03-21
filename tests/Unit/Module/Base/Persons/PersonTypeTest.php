<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonType;

class PersonTypeTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame(0, PersonType::Undefined->value);
        $this->assertSame(1, PersonType::Person->value);
        $this->assertSame(2, PersonType::Company->value);
    }

    public function testFromInt(): void
    {
        $this->assertSame(PersonType::Person, PersonType::from(1));
        $this->assertSame(PersonType::Company, PersonType::from(2));
        $this->assertSame(PersonType::Undefined, PersonType::from(0));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        $this->assertNull(PersonType::tryFrom(99));
    }
}
