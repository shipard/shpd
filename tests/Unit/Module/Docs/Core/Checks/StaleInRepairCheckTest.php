<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\Checks\StaleInRepairCheck;

class StaleInRepairCheckTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $stubRows */
    private function makeCheck(array $stubRows, string $language = 'cs'): StaleInRepairCheck
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($stubRows);

        $config = $this->createMock(ConfigRuntime::class);

        return new StaleInRepairCheck($db, $config, $language);
    }

    public function testNoStaleDocumentsReturnsEmptyArray(): void
    {
        // SQL filter (docState=80 AND doc_state_changed_at < threshold) is what
        // limits the result set, so we simulate "DB returned 0 rows" directly.
        $check = $this->makeCheck([]);
        $this->assertSame([], $check->run());
    }

    public function testSingleStaleDocumentProducesSingleFinding(): void
    {
        $changedAt = (new \DateTimeImmutable('-36 hours'))->format('Y-m-d H:i:s');
        $check = $this->makeCheck([[
            'id'                   => 42,
            'doc_number'           => 'FV2026-0001',
            'doc_text'             => 'Oprava motoru',
            'doc_state_changed_at' => $changedAt,
        ]]);

        $findings = $check->run();
        $this->assertCount(1, $findings);

        $f = $findings[0];
        $this->assertInstanceOf(AlertFinding::class, $f);
        $this->assertSame('42', $f->findingKey);
        $this->assertSame(401, $f->subjectTableId);
        $this->assertSame(42, $f->subjectRowId);
        $this->assertSame('warning', $f->severity);
        $this->assertStringContainsString('FV2026-0001', $f->title);

        $this->assertCount(1, $f->actions);
        $action = $f->actions[0];
        $this->assertSame('open_doc', $action['id']);
        $this->assertSame('open_form', $action['kind']);
        $this->assertTrue($action['primary']);
        $this->assertSame('docs_core_heads', $action['target']['table']);
        $this->assertSame('edit', $action['target']['mode']);
        $this->assertSame(42, $action['target']['id']);
    }

    public function testFindingKeyIsRowIdAsString(): void
    {
        $check = $this->makeCheck([[
            'id'                   => 7,
            'doc_number'           => 'X-7',
            'doc_text'             => '',
            'doc_state_changed_at' => (new \DateTimeImmutable('-30 hours'))->format('Y-m-d H:i:s'),
        ]]);
        $findings = $check->run();
        $this->assertSame('7', $findings[0]->findingKey);
    }

    public function testCzechTitleSingularPlural(): void
    {
        $cases = [
            // [days, expected fragment]
            [1,  '1 den'],
            [2,  '2 dny'],
            [4,  '4 dny'],
            [5,  '5 dnů'],
            [10, '10 dnů'],
        ];

        foreach ($cases as $i => [$days, $expected]) {
            $changedAt = (new \DateTimeImmutable('-' . ($days * 24 + 1) . ' hours'))
                ->format('Y-m-d H:i:s');
            $check = $this->makeCheck([[
                'id'                   => 1 + $i,
                'doc_number'           => 'D-' . (1 + $i),
                'doc_text'             => '',
                'doc_state_changed_at' => $changedAt,
            ]], 'cs');

            $title = $check->run()[0]->title;
            $this->assertStringContainsString(
                $expected,
                $title,
                "days={$days} expected '{$expected}' in '{$title}'",
            );
        }
    }

    public function testEnglishTitleSingularPlural(): void
    {
        $oneDay = (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s');
        $twoDays = (new \DateTimeImmutable('-49 hours'))->format('Y-m-d H:i:s');

        $check1 = $this->makeCheck([[
            'id' => 1, 'doc_number' => 'A', 'doc_text' => '',
            'doc_state_changed_at' => $oneDay,
        ]], 'en');
        $this->assertStringContainsString('for 1 day', $check1->run()[0]->title);
        $this->assertStringNotContainsString('1 days', $check1->run()[0]->title);

        $check2 = $this->makeCheck([[
            'id' => 2, 'doc_number' => 'B', 'doc_text' => '',
            'doc_state_changed_at' => $twoDays,
        ]], 'en');
        $this->assertStringContainsString('for 2 days', $check2->run()[0]->title);
    }

    public function testPlaceholderDocNumberFallsBackToHashId(): void
    {
        $changedAt = (new \DateTimeImmutable('-26 hours'))->format('Y-m-d H:i:s');
        $check = $this->makeCheck([[
            'id'                   => 99,
            'doc_number'           => '!0000000099',
            'doc_text'             => '',
            'doc_state_changed_at' => $changedAt,
        ]]);
        $title = $check->run()[0]->title;
        $this->assertStringContainsString('#99', $title);
        $this->assertStringNotContainsString('!0000000099', $title);
    }

    public function testEmptyDocNumberFallsBackToHashId(): void
    {
        $changedAt = (new \DateTimeImmutable('-26 hours'))->format('Y-m-d H:i:s');
        $check = $this->makeCheck([[
            'id'                   => 12,
            'doc_number'           => '',
            'doc_text'             => '',
            'doc_state_changed_at' => $changedAt,
        ]]);
        $this->assertStringContainsString('#12', $check->run()[0]->title);
    }

    public function testContextIncludesDocNumberAndDaysStale(): void
    {
        $changedAt = (new \DateTimeImmutable('-50 hours'))->format('Y-m-d H:i:s');
        $check = $this->makeCheck([[
            'id'                   => 5,
            'doc_number'           => 'FV-5',
            'doc_text'             => 'Foo',
            'doc_state_changed_at' => $changedAt,
        ]]);
        $finding = $check->run()[0];
        $this->assertSame('FV-5', $finding->context['doc_number']);
        $this->assertSame(2, $finding->context['days_stale']);
    }

    public function testAcceptsDibiDateTimeAsTimestamp(): void
    {
        // Dibi typically hydrates `datetime` columns as Dibi\DateTime objects.
        $dt = new \Dibi\DateTime('-30 hours');
        $check = $this->makeCheck([[
            'id'                   => 8,
            'doc_number'           => 'D-8',
            'doc_text'             => '',
            'doc_state_changed_at' => $dt,
        ]]);
        $finding = $check->run()[0];
        $this->assertSame(1, $finding->context['days_stale']);
    }
}
