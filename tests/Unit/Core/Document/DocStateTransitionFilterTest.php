<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocStateTransitionFilter;

final class FilterTestLockedProvider implements DocumentLockProvider
{
    public static bool $locked = true;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        return self::$locked ? [new DocumentLockReason('fiscal_month', 'Měsíc je uzamčený')] : [];
    }
}

final class FilterTestDocument extends Document
{
    public function filterStateTransitions(array $transitions, array $row): array
    {
        return array_values(array_filter($transitions, fn(array $t): bool => (int) $t['state'] !== 10));
    }
}

/**
 * DocStateTransitionFilter — zamčený záznam (documentLockProviders) nemá
 * žádné přechody; jinak platí Document::filterStateTransitions.
 */
class DocStateTransitionFilterTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function transitions(): array
    {
        return [['state' => 80], ['state' => 10], ['state' => 90]];
    }

    private function registry(): DocumentRegistry
    {
        return new DocumentRegistry(
            [['table' => 'docs_core_heads', 'class' => FilterTestDocument::class]],
            [['table' => 'docs_core_heads', 'class' => FilterTestLockedProvider::class]],
        );
    }

    public function testLockedRowHasNoTransitions(): void
    {
        FilterTestLockedProvider::$locked = true;
        $db = $this->createMock(\Dibi\Connection::class);

        $result = DocStateTransitionFilter::apply('docs_core_heads', ['id' => 1], $this->transitions(), $this->registry(), $db);

        $this->assertSame([], $result);
    }

    public function testUnlockedRowFallsThroughToDocumentHook(): void
    {
        FilterTestLockedProvider::$locked = false;
        $db = $this->createMock(\Dibi\Connection::class);

        $result = DocStateTransitionFilter::apply('docs_core_heads', ['id' => 1], $this->transitions(), $this->registry(), $db);

        $this->assertSame([80, 90], array_column($result, 'state'));
    }

    public function testWithoutDbLockIsNotEvaluated(): void
    {
        FilterTestLockedProvider::$locked = true;

        $result = DocStateTransitionFilter::apply('docs_core_heads', ['id' => 1], $this->transitions(), $this->registry(), null);

        // Bez DB se providery neptáme (bariérou zůstává gateway), hook dokumentu běží dál.
        $this->assertSame([80, 90], array_column($result, 'state'));
    }

    public function testWithoutRegistryPassThrough(): void
    {
        $result = DocStateTransitionFilter::apply('docs_core_heads', ['id' => 1], $this->transitions(), null, null);

        $this->assertSame($this->transitions(), $result);
    }
}
