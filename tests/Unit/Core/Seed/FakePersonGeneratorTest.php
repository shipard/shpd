<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Seed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Seed\FakePersonGenerator;

class FakePersonGeneratorTest extends TestCase
{
    private FakePersonGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new FakePersonGenerator();
    }

    public function testGenerateNaturalPerson(): void
    {
        $data = $this->generator->generate(1, 1);

        $this->assertSame('TEST-0001', $data['person_id']);
        $this->assertSame(1, $data['person_type']);
        $this->assertNotEmpty($data['first_name']);
        $this->assertNotEmpty($data['last_name']);
        $this->assertNotEmpty($data['full_name']);
        $this->assertNotEmpty($data['email']);
        $this->assertNotEmpty($data['phone']);
        $this->assertStringStartsWith('+420 ', $data['phone']);
        $this->assertNull($data['company_id']);
        $this->assertNull($data['tax_id']);
        $this->assertSame(0, $data['is_closed']);
        $this->assertNotNull($data['birth_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['birth_date']);
    }

    public function testGenerateCompany(): void
    {
        $data = $this->generator->generate(42, 2);

        $this->assertSame('TEST-0042', $data['person_id']);
        $this->assertSame(2, $data['person_type']);
        $this->assertNotEmpty($data['full_name']);
        $this->assertSame('', $data['first_name']);
        $this->assertSame($data['full_name'], $data['last_name']);
        $this->assertNotEmpty($data['company_id']);
        $this->assertStringStartsWith('CZ', $data['tax_id']);
        $this->assertNotEmpty($data['email']);
        $this->assertNotNull($data['web']);
        $this->assertNull($data['birth_date']);
    }

    public function testPersonIdFormatting(): void
    {
        $data = $this->generator->generate(5, 1);
        $this->assertSame('TEST-0005', $data['person_id']);

        $data = $this->generator->generate(999, 2);
        $this->assertSame('TEST-0999', $data['person_id']);

        $data = $this->generator->generate(10000, 1);
        $this->assertSame('TEST-10000', $data['person_id']);
    }

    public function testCompanySuffixPresent(): void
    {
        $validSuffixes = ['s.r.o.', 'a.s.', 'SE'];
        for ($i = 0; $i < 20; $i++) {
            $data = $this->generator->generate($i + 100, 2);
            $hasValidSuffix = false;
            foreach ($validSuffixes as $suffix) {
                if (str_contains($data['full_name'], $suffix)) {
                    $hasValidSuffix = true;
                    break;
                }
            }
            $this->assertTrue($hasValidSuffix, 'Company name should contain a legal form suffix: ' . $data['full_name']);
        }
    }
}
