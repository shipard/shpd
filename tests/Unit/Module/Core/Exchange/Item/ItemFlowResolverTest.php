<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Item;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Item\ItemFlowResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\KindResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\SupplierCodesResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;

class ItemFlowResolverTest extends TestCase
{
    public function testHappyPathAllMatched(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // no code_conflict

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::matched(42, 'ourCode')),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $result = $resolver->resolve([
            'name' => 'Konzultace IT',
            'unit' => 'h',
            'kind' => ['code' => 'service'],
        ]);

        $this->assertSame(ResolveStatus::Matched, $result->header->status);
        $this->assertSame(ResolveStatus::Matched, $result->kind->status);
        $this->assertSame(ResolveStatus::Matched, $result->unit->status);
        $this->assertSame([], $result->issues);
    }

    // ── Warnings ──────────────────────────────────────────────────────────

    public function testKindInferredFromItemTypeEmitsWarning(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::canCreate(['name' => 'New'])),
            $this->stubKindResolver(ResolveResult::matched(5, 'itemTypeFallback')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $result = $resolver->resolve([
            'name' => 'New',
            'unit' => 'h',
            'kind' => ['itemType' => 0],
        ]);

        $this->assertCount(1, $result->issues);
        $this->assertSame('warning', $result->issues[0]['severity']);
        $this->assertSame('kind_inferred_from_itemType', $result->issues[0]['code']);
        $this->assertSame('kind', $result->issues[0]['path']);
    }

    public function testKindMatchedBySystemCodeDoesNotEmitWarning(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::matched(42, 'ourCode')),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $result = $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
        ]);

        $this->assertSame([], $result->issues);
    }

    public function testUnitNotFoundEmitsWarning(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::canCreate(['name' => 'X'])),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::notFound()),
            $this->stubSupplierCodesResolver([]),
        );

        $result = $resolver->resolve([
            'name' => 'X', 'unit' => 'unknownXYZ', 'kind' => ['code' => 'service'],
        ]);

        $codes = array_column($result->issues, 'code');
        $this->assertContains('unit_unknown', $codes);
    }

    // ── code_conflict probe ───────────────────────────────────────────────

    public function testCodeConflictWhenAnotherItemOwnsTheCode(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('economy_items'),
                'K-001',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 99]));

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::canCreate(['name' => 'X'])),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $result = $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
            'code' => 'K-001',
        ]);

        $codes = array_column($result->issues, 'code');
        $this->assertContains('code_conflict', $codes);
        $issue = $result->issues[array_search('code_conflict', $codes, true)];
        $this->assertSame('error', $issue['severity']);
        $this->assertStringContainsString('id=99', $issue['message']);
    }

    public function testNoCodeConflictWhenHeaderMatchedByOurCode(): void
    {
        $db = $this->createMock(Connection::class);
        // No probe when header already matched by ourCode (= the same row).
        $db->expects($this->never())->method('fetch');

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::matched(42, 'ourCode')),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
            'code' => 'K-001',
        ]);
    }

    public function testCodeConflictExcludesMatchedHeaderId(): void
    {
        $db = $this->createMock(Connection::class);
        // Probe should pass excludeId for header matched by name.
        $captured = null;
        $db->method('fetch')->willReturnCallback(
            function (...$args) use (&$captured): ?Row {
                $captured = $args;
                return null;
            },
        );

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::matched(42, 'name')),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
            'code' => 'K-001',
        ]);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('id]', (string) $captured[0]);
        // Last positional param should be the excluded id.
        $this->assertContains(42, $captured);
    }

    public function testNoCodeConflictProbeWhenCodeMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::canCreate(['name' => 'X'])),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $this->stubSupplierCodesResolver([]),
        );

        $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
        ]);
    }

    // ── supplierCodes itemId wiring ───────────────────────────────────────

    public function testItemIdPassedToSupplierCodesResolverWhenHeaderMatched(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $scResolver = $this->createMock(SupplierCodesResolver::class);
        $scResolver->expects($this->once())
            ->method('resolve')
            ->with($this->anything(), 42) // itemId from matched header
            ->willReturn([]);

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::matched(42, 'ourCode')),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $scResolver,
        );

        $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
        ]);
    }

    public function testNullItemIdPassedWhenHeaderCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $scResolver = $this->createMock(SupplierCodesResolver::class);
        $scResolver->expects($this->once())
            ->method('resolve')
            ->with($this->anything(), null)
            ->willReturn([]);

        $resolver = new ItemFlowResolver(
            $db,
            $this->stubItemResolver(ResolveResult::canCreate(['name' => 'X'])),
            $this->stubKindResolver(ResolveResult::matched(5, 'system_code')),
            $this->stubUnitResolver(ResolveResult::matched(3, 'alias')),
            $scResolver,
        );

        $resolver->resolve([
            'name' => 'X', 'unit' => 'h', 'kind' => ['code' => 'service'],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function stubItemResolver(ResolveResult $result): ItemResolver
    {
        $m = $this->createMock(ItemResolver::class);
        $m->method('resolve')->willReturn($result);
        return $m;
    }

    private function stubKindResolver(ResolveResult $result): KindResolver
    {
        $m = $this->createMock(KindResolver::class);
        $m->method('resolve')->willReturn($result);
        return $m;
    }

    private function stubUnitResolver(ResolveResult $result): UnitResolver
    {
        $m = $this->createMock(UnitResolver::class);
        $m->method('resolve')->willReturn($result);
        return $m;
    }

    private function stubSupplierCodesResolver(array $result): SupplierCodesResolver
    {
        $m = $this->createMock(SupplierCodesResolver::class);
        $m->method('resolve')->willReturn($result);
        return $m;
    }
}
