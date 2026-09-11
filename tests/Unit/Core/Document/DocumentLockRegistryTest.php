<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Document\DocumentRegistry;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

final class AlwaysLockedProvider extends AbstractDocumentLockProvider
{
    public static int $calls = 0;
    public static ?\Dibi\Connection $seenDb = null;
    public static ?ConfigRuntime $seenConfig = null;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        self::$calls++;
        self::$seenDb = $this->db;
        self::$seenConfig = $this->config;
        return [new DocumentLockReason('vat_period', "Přiznání {$tableId} je uzamčené", 'detail', 441, 7, ['name' => '01/2026'])];
    }
}

final class NeverLockedProvider implements DocumentLockProvider
{
    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        return [];
    }
}

final class SecondLockedProvider implements DocumentLockProvider
{
    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        return [new DocumentLockReason('fiscal_month', 'Fiskální měsíc 2026/01 je uzamčený')];
    }
}

final class BrokenProvider implements DocumentLockProvider
{
    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        return ['not-a-reason'];
    }
}

final class NotAProvider
{
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class DocumentLockRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        AlwaysLockedProvider::$calls = 0;
        AlwaysLockedProvider::$seenDb = null;
        AlwaysLockedProvider::$seenConfig = null;
    }

    public function testEmptyRegistryHasNoProvidersAndNoReasons(): void
    {
        $registry = new DocumentLockRegistry();

        $this->assertFalse($registry->hasProviders('docs_core_heads'));
        $this->assertSame([], $registry->reasons('docs_core_heads', ['id' => 1], null));
        $this->assertSame(['locked' => false, 'reasons' => []], $registry->describe('docs_core_heads', ['id' => 1]));
    }

    public function testReasonsAggregateAcrossProvidersInRegistrationOrder(): void
    {
        $registry = new DocumentLockRegistry([
            ['table' => 'docs_core_heads', 'class' => AlwaysLockedProvider::class],
            ['table' => 'docs_core_heads', 'class' => NeverLockedProvider::class],
            ['table' => 'docs_core_heads', 'class' => SecondLockedProvider::class],
            ['table' => 'other_table',     'class' => SecondLockedProvider::class],
        ]);

        $this->assertTrue($registry->hasProviders('docs_core_heads'));
        $this->assertTrue($registry->hasProviders('other_table'));
        $this->assertFalse($registry->hasProviders('base_persons'));

        $reasons = $registry->reasons('docs_core_heads', ['id' => 1], ['id' => 1]);

        $this->assertSame(['vat_period', 'fiscal_month'], array_map(fn($r) => $r->source, $reasons));
        $this->assertSame('Přiznání docs_core_heads je uzamčené; Fiskální měsíc 2026/01 je uzamčený', DocumentLockRegistry::summarize($reasons));
    }

    public function testProviderInstantiatedOnceAndReceivesServices(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $config = $this->createMock(ConfigRuntime::class);
        $registry = new DocumentLockRegistry(
            [['table' => 'docs_core_heads', 'class' => AlwaysLockedProvider::class]],
            $db,
            $config,
        );

        $registry->reasons('docs_core_heads', [], null);
        $registry->reasons('docs_core_heads', [], null);

        $this->assertSame(2, AlwaysLockedProvider::$calls);
        $this->assertSame($db, AlwaysLockedProvider::$seenDb);
        $this->assertSame($config, AlwaysLockedProvider::$seenConfig);
    }

    public function testDescribeReturnsUiContract(): void
    {
        $registry = new DocumentLockRegistry([
            ['table' => 'docs_core_heads', 'class' => AlwaysLockedProvider::class],
        ]);

        $described = $registry->describe('docs_core_heads', ['id' => 5]);

        $this->assertTrue($described['locked']);
        $this->assertSame([
            'source'         => 'vat_period',
            'title'          => 'Přiznání docs_core_heads je uzamčené',
            'message'        => 'detail',
            'params'         => ['name' => '01/2026'],
            'subjectTableId' => 441,
            'subjectRowId'   => 7,
        ], $described['reasons'][0]);
    }

    public function testForDocumentsTakesProvidersFromDocumentRegistry(): void
    {
        $documents = new DocumentRegistry([], [
            ['table' => 'docs_core_heads', 'class' => SecondLockedProvider::class],
        ]);

        $this->assertTrue($documents->hasLockProviders('docs_core_heads'));
        $this->assertFalse($documents->hasLockProviders('base_persons'));

        $registry = DocumentLockRegistry::forDocuments($documents, null);
        $this->assertCount(1, $registry->reasons('docs_core_heads', [], null));

        $this->assertFalse(DocumentLockRegistry::forDocuments(null, null)->hasProviders('docs_core_heads'));
    }

    public function testClassNotImplementingInterfaceThrows(): void
    {
        $registry = new DocumentLockRegistry([
            ['table' => 'docs_core_heads', 'class' => NotAProvider::class],
        ]);

        $this->expectException(\LogicException::class);
        $registry->reasons('docs_core_heads', [], null);
    }

    public function testProviderReturningForeignItemThrows(): void
    {
        $registry = new DocumentLockRegistry([
            ['table' => 'docs_core_heads', 'class' => BrokenProvider::class],
        ]);

        $this->expectException(\LogicException::class);
        $registry->reasons('docs_core_heads', [], null);
    }

    public function testReasonRejectsHalfSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentLockReason('vat_period', 'x', subjectTableId: 441);
    }

    public function testReasonRejectsEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentLockReason('vat_period', '');
    }
}
