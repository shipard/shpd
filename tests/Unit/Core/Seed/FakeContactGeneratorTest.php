<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Seed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Seed\FakeContactGenerator;

class FakeContactGeneratorTest extends TestCase
{
    private FakeContactGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FakeContactGenerator();
    }

    public function testGenerateReturnsNonEmptyArray(): void
    {
        $contacts = $this->generator->generate(42);

        $this->assertNotEmpty($contacts);
        $this->assertGreaterThanOrEqual(1, count($contacts));
        $this->assertLessThanOrEqual(3, count($contacts));
    }

    public function testContactHasRequiredFields(): void
    {
        $contacts = $this->generator->generate(99);
        $contact = $contacts[0];

        $this->assertSame(99, $contact['person']);
        $this->assertNotEmpty($contact['name']);
        $this->assertNotEmpty($contact['email']);
        $this->assertStringStartsWith('+420 ', $contact['phone']);
        $this->assertSame(0, $contact['order_pos']);
    }

    public function testOrderPosIsSequential(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $contacts = $this->generator->generate(1, 3);
            for ($i = 0; $i < count($contacts); $i++) {
                $this->assertSame($i, $contacts[$i]['order_pos']);
            }
        }
    }
}
