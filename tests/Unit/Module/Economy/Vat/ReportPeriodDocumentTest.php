<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Module\Economy\Vat\ReportPeriodDocument;

/**
 * Testovatelná varianta — DB dotazy nahrazené in-memory seznamem instancí
 * {id, vat_registration, report_type, name, date_begin, date_end, locked, docState}
 * a mapou počtů přiřazených dokladů.
 */
final class TestableReportPeriodDocument extends ReportPeriodDocument
{
    /** @var list<array<string, mixed>> */
    public array $instances = [];

    /** @var array<string, int> "column:id" → počet */
    public array $assigned = [];

    protected function findOverlapping(int $regId, string $type, string $begin, string $end, ?int $selfId): array
    {
        $out = [];
        foreach ($this->live($regId, $type, $selfId) as $row) {
            if ($row['date_begin'] <= $end && $row['date_end'] >= $begin) {
                $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        return $out;
    }

    protected function findNeighbours(int $regId, string $type, string $begin, string $end, ?int $selfId): array
    {
        $prev = null;
        $next = null;
        foreach ($this->live($regId, $type, $selfId) as $row) {
            if ($row['date_end'] < $begin && ($prev === null || $row['date_end'] > $prev)) {
                $prev = $row['date_end'];
            }
            if ($row['date_begin'] > $end && ($next === null || $row['date_begin'] < $next)) {
                $next = $row['date_begin'];
            }
        }
        return [$prev, $next];
    }

    protected function loadCurrent(int $id): ?array
    {
        foreach ($this->instances as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    protected function countAssignedDocuments(string $headColumn, int $periodId): int
    {
        return $this->assigned["{$headColumn}:{$periodId}"] ?? 0;
    }

    /** @return list<array<string, mixed>> */
    private function live(int $regId, string $type, ?int $selfId): array
    {
        return array_values(array_filter(
            $this->instances,
            static fn (array $r): bool => (int) $r['vat_registration'] === $regId
                && $r['report_type'] === $type
                && (int) ($r['docState'] ?? 40) !== 90
                && ($selfId === null || (int) $r['id'] !== $selfId),
        ));
    }
}

final class ReportPeriodDocumentTest extends TestCase
{
    private function doc(array $instances = [], array $assigned = []): TestableReportPeriodDocument
    {
        $doc = new TestableReportPeriodDocument();
        $doc->instances = $instances;
        $doc->assigned = $assigned;
        return $doc;
    }

    /** @return array<string, mixed> */
    private function q1(array $override = []): array
    {
        return array_merge([
            'id' => 1, 'vat_registration' => 5, 'report_type' => 'return', 'name' => 'Q1/2026',
            'date_begin' => '2026-01-01', 'date_end' => '2026-03-31', 'locked' => 0, 'docState' => 40,
        ], $override);
    }

    private function validData(array $override = []): array
    {
        return array_merge([
            'vat_registration' => 5, 'report_type' => 'cs', 'name' => '01/2026',
            'date_begin' => '2026-01-01', 'date_end' => '2026-01-31', 'locked' => 0, 'docState' => 10,
        ], $override);
    }

    public function testValidDataPasses(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->getWarnings());
    }

    public function testRequiredFieldsAndType(): void
    {
        $data = ['report_type' => 'oss'];
        $result = $this->doc()->validate($data);
        $columns = array_column($result->toArray(), 'column');
        foreach (['vat_registration', 'report_type', 'name', 'date_begin', 'date_end'] as $col) {
            $this->assertContains($col, $columns);
        }
        $this->assertContains('invalid_value', array_column($result->toArray(), 'code'));
    }

    public function testEndBeforeBeginFails(): void
    {
        $data = $this->validData(['date_end' => '2025-12-31']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_range', $result->toArray()[0]['code']);
    }

    public function testOverlapWithSameTypeIsHardError(): void
    {
        $doc = $this->doc([$this->q1()]);
        // return 02/2026 uvnitř Q1 téže registrace a typu → překryv
        $data = $this->validData(['report_type' => 'return', 'name' => '02/2026',
            'date_begin' => '2026-02-01', 'date_end' => '2026-02-28']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('overlap', $result->toArray()[0]['code']);
        $this->assertStringContainsString('Q1/2026', $result->toArray()[0]['message']);
    }

    public function testOverlapWithOtherTypeOrRegistrationIsFine(): void
    {
        $doc = $this->doc([$this->q1(), $this->q1(['id' => 2, 'vat_registration' => 6, 'report_type' => 'cs'])]);
        // cs měsíc pod čtvrtletním return téže registrace = partition, ne překryv
        $data = $this->validData();
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testEditingSelfDoesNotOverlapWithItself(): void
    {
        $doc = $this->doc([$this->q1()]);
        $data = $this->q1(['date_end' => '2026-04-30']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testDeletedInstanceIsIgnoredForOverlap(): void
    {
        $doc = $this->doc([$this->q1(['docState' => 90])]);
        $data = $this->validData(['report_type' => 'return']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testGapToNeighboursIsWarningOnly(): void
    {
        $doc = $this->doc([
            $this->q1(['report_type' => 'cs', 'name' => '01/2026', 'date_end' => '2026-01-31']),
            $this->q1(['id' => 2, 'report_type' => 'cs', 'name' => '04/2026',
                'date_begin' => '2026-04-01', 'date_end' => '2026-04-30']),
        ]);
        // 03/2026: mezera k 01 (chybí únor), na 04 navazuje
        $data = $this->validData(['name' => '03/2026', 'date_begin' => '2026-03-01', 'date_end' => '2026-03-31']);
        $result = $doc->validate($data);
        $this->assertTrue($result->isValid());
        $warnings = $result->warningsToArray();
        $this->assertCount(1, $warnings);
        $this->assertSame('gap', $warnings[0]['code']);
        $this->assertSame('date_begin', $warnings[0]['column']);
    }

    public function testCancellationBlockedWhenLocked(): void
    {
        $doc = $this->doc([$this->q1(['locked' => 1])]);
        $data = $this->q1(['locked' => 1, 'docState' => 90]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $error = $result->toArray()[0];
        $this->assertSame(ValidationError::FIELD_FORM, $error['column']);
        $this->assertSame('cancellation_blocked', $error['code']);
    }

    public function testCancellationBlockedWhenDocumentsAssigned(): void
    {
        $doc = $this->doc([$this->q1()], ['vat_period:1' => 3]);
        $data = $this->q1(['docState' => 90]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('3 dokladů', $result->toArray()[0]['message']);
    }

    public function testCancellationAllowedWithoutBlockers(): void
    {
        $doc = $this->doc([$this->q1()]);
        $data = $this->q1(['docState' => 90]);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testHardDeleteThrowsWhenAssigned(): void
    {
        $doc = $this->doc([$this->q1()], ['vat_period:1' => 1]);
        $this->expectException(\DomainException::class);
        $doc->beforeDelete($this->q1());
    }

    public function testCsColumnMapping(): void
    {
        $this->assertSame('cs_period', ReportPeriodDocument::HEAD_COLUMN_BY_TYPE['cs']);
        $this->assertSame('rs_period', ReportPeriodDocument::HEAD_COLUMN_BY_TYPE['rs']);
        $this->assertSame('vat_period', ReportPeriodDocument::HEAD_COLUMN_BY_TYPE['return']);
    }
}
