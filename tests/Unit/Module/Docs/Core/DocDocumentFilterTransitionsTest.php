<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * DocDocument::filterStateTransitions — přechod →10 (Koncept) se nabízí
 * jen poslednímu dokladu v řadě (uvolnění čísla jinak padá v
 * releaseDocumentNumber). Hook jen filtruje UI nabídku, bariéra zůstává.
 */
class DocDocumentFilterTransitionsTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function transitionsWithDraft(): array
    {
        return [
            ['state' => 40, 'actionName' => 'V pořádku'],
            ['state' => 30, 'actionName' => 'Stornovat'],
            ['state' => 10, 'actionName' => 'Uložit jako koncept'],
            ['state' => 90, 'actionName' => 'Smazat'],
        ];
    }

    private function dbWithMaxSeq(int $maxSeq): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['max_seq' => $maxSeq]));
        return $db;
    }

    public function testDraftTransitionRemovedForNonLastDocument(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithMaxSeq(7));

        $row = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $result = $doc->filterStateTransitions($this->transitionsWithDraft(), $row);

        $this->assertSame([40, 30, 90], array_column($result, 'state'));
    }

    public function testDraftTransitionKeptForLastDocument(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithMaxSeq(5));

        $row = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $result = $doc->filterStateTransitions($this->transitionsWithDraft(), $row);

        $this->assertSame([40, 30, 10, 90], array_column($result, 'state'));
    }

    public function testPassThroughForDocumentWithoutNumber(): void
    {
        // Bez čísla je release no-op — nabídka se nemění a DB se nedotýká.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $row = ['number_series' => 1, 'fiscal_year' => null, 'sequence_number' => null];
        $result = $doc->filterStateTransitions($this->transitionsWithDraft(), $row);

        $this->assertSame([40, 30, 10, 90], array_column($result, 'state'));
    }

    public function testPassThroughWhenDraftNotOffered(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $transitions = [['state' => 80, 'actionName' => 'Opravit']];
        $row = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];

        $this->assertSame($transitions, $doc->filterStateTransitions($transitions, $row));
    }

    public function testPassThroughWithoutDb(): void
    {
        $doc = new TestableDocsHeadsDocument();

        $row = ['number_series' => 1, 'fiscal_year' => 100, 'sequence_number' => 5];
        $result = $doc->filterStateTransitions($this->transitionsWithDraft(), $row);

        $this->assertSame([40, 30, 10, 90], array_column($result, 'state'));
    }
}
