<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Seed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Seed\FakeBankAccountGenerator;

class FakeBankAccountGeneratorTest extends TestCase
{
    private FakeBankAccountGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FakeBankAccountGenerator();
    }

    public function testGenerateReturnsNonEmptyArray(): void
    {
        $accounts = $this->generator->generate(42);

        $this->assertNotEmpty($accounts);
        $this->assertGreaterThanOrEqual(1, count($accounts));
        $this->assertLessThanOrEqual(2, count($accounts));
    }

    public function testAccountHasRequiredFields(): void
    {
        $accounts = $this->generator->generate(55);
        $account = $accounts[0];

        $this->assertSame(55, $account['person']);
        $this->assertSame('Hlavní účet', $account['name']);
        $this->assertNotEmpty($account['account_number']);
        $this->assertTrue(str_contains($account['account_number'], '/'), 'Account number must contain /');
        $this->assertStringStartsWith('CZ', $account['iban']);
        $this->assertNotEmpty($account['bic']);
        $this->assertSame('CZK', $account['currency']);
        $this->assertSame(0, $account['source']);
        $this->assertSame(0, $account['order_pos']);
    }

    public function testSecondAccountHasDifferentName(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $accounts = $this->generator->generate(1, 2);
            if (count($accounts) === 2) {
                $this->assertSame('Hlavní účet', $accounts[0]['name']);
                $this->assertSame('Účet 2', $accounts[1]['name']);
                return;
            }
        }

        $this->fail('Expected to generate 2 accounts at least once in 20 attempts');
    }
}
