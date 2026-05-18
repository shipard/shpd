<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertFinding;

class AlertFindingTest extends TestCase
{
    public function testMinimalConstruction(): void
    {
        $f = new AlertFinding(findingKey: '', title: 'Something is missing');
        $this->assertSame('', $f->findingKey);
        $this->assertSame('Something is missing', $f->title);
        $this->assertSame('', $f->message);
        $this->assertSame('warning', $f->severity);
        $this->assertNull($f->subjectTableId);
        $this->assertNull($f->subjectRowId);
        $this->assertSame([], $f->actions);
        $this->assertNull($f->context);
    }

    public function testFullConstruction(): void
    {
        $f = new AlertFinding(
            findingKey: 'persons:42',
            title: 'Person 42 has stale state',
            message: 'Has been in V opravě for 7 days',
            severity: 'error',
            subjectTableId: 201,
            subjectRowId: 42,
            actions: [
                ['id' => 'open', 'label' => 'Open', 'kind' => 'open_form', 'primary' => true],
            ],
            context: ['days_stale' => 7],
        );
        $this->assertSame('persons:42', $f->findingKey);
        $this->assertSame('error', $f->severity);
        $this->assertSame(201, $f->subjectTableId);
        $this->assertSame(42, $f->subjectRowId);
        $this->assertCount(1, $f->actions);
        $this->assertSame(['days_stale' => 7], $f->context);
    }

    public function testEmptyTitleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title must not be empty');
        new AlertFinding(findingKey: '', title: '');
    }

    public function testInvalidSeverityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('severity must be one of');
        new AlertFinding(findingKey: '', title: 't', severity: 'critical');
    }

    public function testValidSeverities(): void
    {
        foreach (['info', 'warning', 'error'] as $s) {
            $f = new AlertFinding(findingKey: '', title: 't', severity: $s);
            $this->assertSame($s, $f->severity);
        }
    }

    public function testTwoPrimaryActionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most one action may be primary');
        new AlertFinding(
            findingKey: '', title: 't',
            actions: [
                ['id' => 'a', 'primary' => true],
                ['id' => 'b', 'primary' => true],
            ],
        );
    }

    public function testOnePrimaryPlusSecondariesIsOk(): void
    {
        $f = new AlertFinding(
            findingKey: '', title: 't',
            actions: [
                ['id' => 'a', 'primary' => true],
                ['id' => 'b'],
                ['id' => 'c', 'primary' => false],
            ],
        );
        $this->assertCount(3, $f->actions);
    }

    public function testActionsMustBeArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actions[0] must be an array');
        new AlertFinding(findingKey: '', title: 't', actions: ['not an array']);
    }

    public function testSubjectTablePresentButRowMissingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subjectTableId and subjectRowId must be both set or both null');
        new AlertFinding(findingKey: '', title: 't', subjectTableId: 201, subjectRowId: null);
    }

    public function testSubjectRowPresentButTableMissingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AlertFinding(findingKey: '', title: 't', subjectTableId: null, subjectRowId: 42);
    }
}
