<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Module\Economy\Codebooks\FiscalMonthDocument;

/** DB a kontext nahrazené in-memory stavem. */
final class TestableFiscalMonthDocument extends FiscalMonthDocument
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public ?int $userId = null;
    public string $nowValue = '2026-09-11 10:00:00';

    protected function loadCurrent(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    protected function currentUserId(): ?int
    {
        return $this->userId;
    }

    protected function now(): string
    {
        return $this->nowValue;
    }
}

class FiscalMonthDocumentTest extends TestCase
{
    private function doc(): TestableFiscalMonthDocument
    {
        return new TestableFiscalMonthDocument();
    }

    /** @return array<string, mixed> Uložený zamčený březen 2026 (id 3). */
    private function lockedMarch(array $override = []): array
    {
        return array_merge([
            'id' => 3, 'fiscal_year' => 1, 'date_begin' => '2026-03-01', 'date_end' => '2026-03-31',
            'period_type' => 1, 'calendar_year' => 2026, 'calendar_month' => 3, 'locked' => 1,
        ], $override);
    }

    /** @return array<string, mixed> */
    private function validData(array $override = []): array
    {
        return array_merge([
            'fiscal_year' => 1,
            'date_begin'  => '2026-03-01',
            'date_end'    => '2026-03-31',
            'period_type' => 1,
        ], $override);
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingFiscalYearFails(): void
    {
        $data = $this->validData();
        unset($data['fiscal_year']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('fiscal_year', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDateBeginFails(): void
    {
        $data = $this->validData();
        unset($data['date_begin']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_begin', array_column($result->toArray(), 'column'));
    }

    public function testValidateMissingDateEndFails(): void
    {
        $data = $this->validData();
        unset($data['date_end']);

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('date_end', array_column($result->toArray(), 'column'));
    }

    public function testValidateInvalidDateRangeFails(): void
    {
        $data = $this->validData();
        $data['date_begin'] = '2026-03-31';
        $data['date_end']   = '2026-03-01';

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'date_end' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidPeriodTypeFails(): void
    {
        $data = $this->validData();
        $data['period_type'] = 5;

        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('period_type', array_column($result->toArray(), 'column'));
    }

    public function testValidatePeriodTypeOpeningIsValid(): void
    {
        $data = $this->validData();
        $data['period_type'] = 0;

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidatePeriodTypeClosingIsValid(): void
    {
        $data = $this->validData();
        $data['period_type'] = 2;

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testBeforeSaveDerivesCalendarYearAndMonth(): void
    {
        $doc = $this->doc();
        $data = $this->validData();
        $data['date_begin'] = '2026-03-15';
        unset($data['calendar_year'], $data['calendar_month']);

        $doc->beforeSave($data);

        $this->assertSame(2026, $data['calendar_year']);
        $this->assertSame(3, $data['calendar_month']);
    }

    public function testBeforeSaveOverwritesUserSuppliedCalendarFields(): void
    {
        $doc = $this->doc();
        $data = $this->validData();
        $data['date_begin']     = '2026-07-01';
        $data['calendar_year']  = 1999;
        $data['calendar_month'] = 12;

        $doc->beforeSave($data);

        $this->assertSame(2026, $data['calendar_year']);
        $this->assertSame(7, $data['calendar_month']);
    }

    public function testBeforeSaveSkipsWithoutDateBegin(): void
    {
        $doc = $this->doc();
        $data = ['date_end' => '2026-03-31', 'calendar_year' => 1234];

        $doc->beforeSave($data);

        $this->assertSame(1234, $data['calendar_year']);
        $this->assertArrayNotHasKey('calendar_month', $data);
    }

    // ── Zámek měsíce (#55 D27) ──────────────────────────────────────────

    public function testOnlyRegularMonthCanBeLocked(): void
    {
        $data = $this->validData(['period_type' => 0, 'locked' => 1]);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('locked', $result->toArray()[0]['column']);

        $data = $this->validData(['period_type' => 1, 'locked' => 1]);
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testLockedMonthRejectsRangeTypeAndYearChange(): void
    {
        $doc = $this->doc();
        $doc->rows[3] = $this->lockedMarch();

        foreach ([['date_end' => '2026-04-15'], ['date_begin' => '2026-02-28'], ['fiscal_year' => 2]] as $change) {
            $data = $this->lockedMarch($change);
            $result = $doc->validate($data);
            $this->assertFalse($result->isValid(), json_encode($change));
            $error = $result->toArray()[0];
            $this->assertSame(ValidationError::FIELD_FORM, $error['column']);
            $this->assertSame('locked', $error['code']);
        }
    }

    public function testLockedMonthAllowsUnlockAndUnchangedSave(): void
    {
        $doc = $this->doc();
        $doc->rows[3] = $this->lockedMarch();

        $data = $this->lockedMarch();
        $this->assertTrue($doc->validate($data)->isValid());

        $data = $this->lockedMarch(['locked' => 0]);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testUnlockCombinedWithRangeChangeIsRejected(): void
    {
        $doc = $this->doc();
        $doc->rows[3] = $this->lockedMarch();
        $data = $this->lockedMarch(['locked' => 0, 'date_end' => '2026-04-15']);

        $this->assertSame('locked', $doc->validate($data)->toArray()[0]['code']);
    }

    public function testUnlockedMonthChangesFreely(): void
    {
        $doc = $this->doc();
        $doc->rows[3] = $this->lockedMarch(['locked' => 0]);
        $data = $this->lockedMarch(['locked' => 0, 'date_end' => '2026-04-15']);

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testLockingStampsUserAndTime(): void
    {
        $doc = $this->doc();
        $doc->userId = 5;
        $data = $this->lockedMarch();

        $doc->beforeSave($data, $this->lockedMarch(['locked' => 0]));

        $this->assertSame(5, $data['locked_by']);
        $this->assertSame('2026-09-11 10:00:00', $data['locked_at']);
        // Denormalizace kalendáře běží dál.
        $this->assertSame(3, $data['calendar_month']);
    }

    public function testUnlockingClearsStamp(): void
    {
        $doc = $this->doc();
        $data = $this->lockedMarch(['locked' => 0]);

        $doc->beforeSave($data, $this->lockedMarch(['locked_at' => '2026-01-01 00:00:00', 'locked_by' => 5]));

        $this->assertNull($data['locked_at']);
        $this->assertNull($data['locked_by']);
    }
}
