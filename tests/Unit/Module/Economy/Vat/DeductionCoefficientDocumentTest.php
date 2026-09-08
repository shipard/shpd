<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\DeductionCoefficientDocument;

/**
 * Testovatelná varianta — duplicita z in-memory seznamu
 * {id, vat_registration, year, docState}.
 */
final class TestableDeductionCoefficientDocument extends DeductionCoefficientDocument
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    protected function findDuplicate(int $regId, int $year, ?int $selfId): ?int
    {
        foreach ($this->rows as $row) {
            if ((int) $row['vat_registration'] === $regId && (int) $row['year'] === $year
                && (int) $row['docState'] !== 90 && (int) $row['id'] !== ($selfId ?? 0)) {
                return (int) $row['id'];
            }
        }
        return null;
    }
}

final class DeductionCoefficientDocumentTest extends TestCase
{
    private function doc(array $rows = []): TestableDeductionCoefficientDocument
    {
        $doc = new TestableDeductionCoefficientDocument();
        $doc->rows = $rows;
        return $doc;
    }

    /** @return array<string, mixed> */
    private function validData(array $override = []): array
    {
        return array_merge([
            'vat_registration' => 5, 'year' => 2026,
            'coefficient_provisional' => '0.80', 'coefficient_settled' => null,
            'note' => 'rozhodnutí FÚ', 'docState' => 10,
        ], $override);
    }

    /** @return list<string> */
    private function codes(array $errors): array
    {
        return array_map(static fn ($e): string => $e->code, $errors);
    }

    public function testValidDataPassesAndNormalizes(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertSame(0.8, $data['coefficient_provisional']);
        $this->assertNull($data['coefficient_settled']);
    }

    public function testEmptyStringBecomesNull(): void
    {
        $data = $this->validData(['coefficient_provisional' => '', 'coefficient_settled' => '']);
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertNull($data['coefficient_provisional']);
        $this->assertNull($data['coefficient_settled']);
    }

    public function testRequiredFields(): void
    {
        $data = ['coefficient_provisional' => '0.5'];
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_map(static fn ($e): string => $e->column, $result->getErrors());
        $this->assertContains('vat_registration', $columns);
        $this->assertContains('year', $columns);
    }

    public function testYearOutOfRange(): void
    {
        $data = $this->validData(['year' => 1900]);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame(['invalid_range'], $this->codes($result->getErrors()));
    }

    public function testCoefficientOutsideUnitInterval(): void
    {
        foreach (['1.01', '-0.01', '80'] as $value) {
            $data = $this->validData(['coefficient_settled' => $value]);
            $result = $this->doc()->validate($data);
            $this->assertFalse($result->isValid(), "hodnota {$value}");
            $errors = $result->getErrors();
            $this->assertSame('coefficient_settled', $errors[0]->column);
            $this->assertSame('invalid_range', $errors[0]->code);
        }
    }

    public function testBoundariesAreValid(): void
    {
        $data = $this->validData(['coefficient_provisional' => '0', 'coefficient_settled' => '1.00']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertSame(0.0, $data['coefficient_provisional']);
        $this->assertSame(1.0, $data['coefficient_settled']);
    }

    public function testPrecisionMustBeWholePercent(): void
    {
        $data = $this->validData(['coefficient_provisional' => '0.805']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame(['coefficient_precision'], $this->codes($result->getErrors()));
    }

    public function testNonNumericIsRejected(): void
    {
        $data = $this->validData(['coefficient_provisional' => '80 %']);
        $result = $this->doc()->validate($data);

        $this->assertSame(['invalid_value'], $this->codes($result->getErrors()));
    }

    public function testDuplicateRegistrationYearIsError(): void
    {
        $doc = $this->doc([['id' => 7, 'vat_registration' => 5, 'year' => 2026, 'docState' => 40]]);
        $data = $this->validData();
        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('year', $result->getErrors()[0]->column);
        $this->assertSame('duplicate', $result->getErrors()[0]->code);
    }

    public function testEditingSelfIsNotDuplicate(): void
    {
        $doc = $this->doc([['id' => 7, 'vat_registration' => 5, 'year' => 2026, 'docState' => 40]]);
        $data = $this->validData(['id' => 7]);

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testDeletedRowAndOtherRegistrationDoNotCollide(): void
    {
        $doc = $this->doc([
            ['id' => 7, 'vat_registration' => 5, 'year' => 2026, 'docState' => 90],
            ['id' => 8, 'vat_registration' => 6, 'year' => 2026, 'docState' => 40],
            ['id' => 9, 'vat_registration' => 5, 'year' => 2025, 'docState' => 40],
        ]);
        $data = $this->validData();

        $this->assertTrue($doc->validate($data)->isValid());
    }
}
