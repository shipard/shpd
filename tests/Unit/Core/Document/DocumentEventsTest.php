<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModuleDefinition;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * Fake handler — dispatcher instancuje přes class name, takže evidence volání
 * je statická (reset v setUp).
 */
class FakeEventHandler extends AbstractDocumentEventHandler
{
    /** @var list<array{table: string, old: int, new: int, id: mixed}> */
    public static array $stateCalls = [];

    /** @var list<array{table: string, id: mixed}> */
    public static array $deleteCalls = [];

    /** @var list<array{table: string, id: mixed, original: ?array}> */
    public static array $beforeSaveCalls = [];

    /** @var list<array{table: string, id: mixed, original: ?array}> */
    public static array $afterSaveCalls = [];

    public static bool $throwOnStateChanged = false;
    public static bool $throwOnBeforeDelete = false;
    public static bool $throwOnBeforeSave = false;
    public static bool $throwOnAfterSave = false;

    public static function reset(): void
    {
        self::$stateCalls = [];
        self::$deleteCalls = [];
        self::$beforeSaveCalls = [];
        self::$afterSaveCalls = [];
        self::$throwOnStateChanged = false;
        self::$throwOnBeforeDelete = false;
        self::$throwOnBeforeSave = false;
        self::$throwOnAfterSave = false;
    }

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
        self::$beforeSaveCalls[] = ['table' => $tableId, 'id' => $data['id'] ?? null, 'original' => $originalData];
        // Mutace dat handlerem se musí propsat do zápisu hlavičky.
        $data['handler_column'] = 'set-by-handler';
        if (self::$throwOnBeforeSave) {
            throw new \RuntimeException('before save boom');
        }
    }

    public function onAfterSave(string $tableId, array $data, ?array $originalData): void
    {
        self::$afterSaveCalls[] = ['table' => $tableId, 'id' => $data['id'] ?? null, 'original' => $originalData];
        if (self::$throwOnAfterSave) {
            throw new \RuntimeException('after save boom');
        }
    }

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        self::$stateCalls[] = [
            'table' => $tableId, 'old' => $oldState, 'new' => $newState,
            'id' => $data['id'] ?? null,
        ];
        if (self::$throwOnStateChanged) {
            throw new \RuntimeException('handler boom');
        }
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
        self::$deleteCalls[] = ['table' => $tableId, 'id' => $data['id'] ?? null];
        if (self::$throwOnBeforeDelete) {
            throw new \RuntimeException('delete handler boom');
        }
    }
}

/** Druhý handler pro test více handlerů na tabulce. */
class SecondFakeEventHandler extends AbstractDocumentEventHandler
{
    public static int $stateCallCount = 0;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        self::$stateCallCount++;
    }
}

/** Document stub: nastaví stateTransition podle statické hodnoty. */
class TransitionDocument extends Document
{
    /** @var array{old: int, new: int}|null */
    public static ?array $transition = null;

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->stateTransition = self::$transition;
    }
}

class EventTestGateway extends TableGateway
{
    /** @var array<int, array> */
    public array $storedRows = [];

    /** @var array<int, array{table: string, id: int}> */
    public array $deleteRowCalls = [];

    /** @var list<array{table: string}> */
    public array $deleteChildrenCalls = [];

    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    protected function fetchRow(int $id): ?array
    {
        return $this->storedRows[$id] ?? null;
    }

    protected function fetchChildren(string $table, string $foreignKey, int $parentId): array
    {
        return [];
    }

    /** @var list<array<string, mixed>> */
    public array $updateRowCalls = [];

    protected function insertRow(string $table, array $data): int
    {
        return 1;
    }

    protected function updateRow(string $table, int $id, array $data): void
    {
        $this->updateRowCalls[] = $data;
    }

    protected function deleteRow(string $table, int $id): void
    {
        $this->deleteRowCalls[] = ['table' => $table, 'id' => $id];
    }

    protected function deleteChildren(string $table, string $foreignKey, int $parentId): void
    {
        $this->deleteChildrenCalls[] = ['table' => $table];
    }

    protected function beginTransaction(): void { $this->beginCount++; }
    protected function commitTransaction(): void { $this->commitCount++; }
    protected function rollbackTransaction(): void { $this->rollbackCount++; }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class DocumentEventsTest extends TestCase
{
    protected function setUp(): void
    {
        FakeEventHandler::reset();
        SecondFakeEventHandler::$stateCallCount = 0;
        TransitionDocument::$transition = null;
    }

    private function dispatcher(array $events = ['stateChanged', 'beforeDelete']): DocumentEventDispatcher
    {
        return new DocumentEventDispatcher([
            ['table' => 'test_table', 'class' => FakeEventHandler::class, 'events' => $events],
        ]);
    }

    private function gateway(?DocumentEventDispatcher $dispatcher): EventTestGateway
    {
        $registry = new DocumentRegistry([
            ['table' => 'test_table', 'class' => TransitionDocument::class],
        ]);
        return new EventTestGateway(
            'test_table',
            $this->createMock(Connection::class),
            $registry,
            [['table' => 'test_children', 'foreignKey' => 'parent', 'dataKey' => 'rows']],
            null,
            null,
            $dispatcher,
        );
    }

    // ── Dispatcher ──────────────────────────────────────────────────────────

    public function testDispatchesOnlyToMatchingTableAndEvent(): void
    {
        $d = $this->dispatcher();
        $d->dispatchStateChanged('other_table', ['id' => 1], 10, 40);
        $this->assertCount(0, FakeEventHandler::$stateCalls);

        $d->dispatchStateChanged('test_table', ['id' => 1], 10, 40);
        $this->assertCount(1, FakeEventHandler::$stateCalls);
        $this->assertSame(
            ['table' => 'test_table', 'old' => 10, 'new' => 40, 'id' => 1],
            FakeEventHandler::$stateCalls[0],
        );
    }

    public function testHandlerNotSubscribedToEventIsSkipped(): void
    {
        $d = $this->dispatcher(events: ['beforeDelete']);
        $d->dispatchStateChanged('test_table', ['id' => 1], 10, 40);

        $this->assertCount(0, FakeEventHandler::$stateCalls);
    }

    public function testStateChangedExceptionIsSwallowedAndOtherHandlersRun(): void
    {
        FakeEventHandler::$throwOnStateChanged = true;
        $d = new DocumentEventDispatcher([
            ['table' => 'test_table', 'class' => FakeEventHandler::class, 'events' => ['stateChanged']],
            ['table' => 'test_table', 'class' => SecondFakeEventHandler::class, 'events' => ['stateChanged']],
        ]);

        $d->dispatchStateChanged('test_table', ['id' => 1], 10, 40);

        $this->assertCount(1, FakeEventHandler::$stateCalls);
        $this->assertSame(1, SecondFakeEventHandler::$stateCallCount);
    }

    public function testBeforeDeleteExceptionPropagates(): void
    {
        FakeEventHandler::$throwOnBeforeDelete = true;
        $d = $this->dispatcher();

        $this->expectException(\RuntimeException::class);
        $d->dispatchBeforeDelete('test_table', ['id' => 1]);
    }

    // ── Gateway: stateChanged ───────────────────────────────────────────────

    public function testSaveDispatchesStateChangedWithTransitionFromDocument(): void
    {
        TransitionDocument::$transition = ['old' => 20, 'new' => 40];
        $gw = $this->gateway($this->dispatcher());
        $gw->storedRows[5] = ['id' => 5, 'docState' => 20];

        $result = $gw->saveDocument(['id' => 5, 'docState' => 40]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, FakeEventHandler::$stateCalls);
        $this->assertSame(20, FakeEventHandler::$stateCalls[0]['old']);
        $this->assertSame(40, FakeEventHandler::$stateCalls[0]['new']);
        // dispatch až po commitu
        $this->assertSame(1, $gw->commitCount);
    }

    public function testSaveWithoutTransitionDoesNotDispatch(): void
    {
        TransitionDocument::$transition = null;
        $gw = $this->gateway($this->dispatcher());
        $gw->storedRows[5] = ['id' => 5, 'docState' => 40];

        $result = $gw->saveDocument(['id' => 5, 'description' => 'x']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(0, FakeEventHandler::$stateCalls);
    }

    public function testSaveWithoutDispatcherWorks(): void
    {
        TransitionDocument::$transition = ['old' => 10, 'new' => 40];
        $gw = $this->gateway(null);
        $gw->storedRows[5] = ['id' => 5];

        $this->assertTrue($gw->saveDocument(['id' => 5])->isSuccess());
    }

    // ── Gateway: beforeSave / afterSave ─────────────────────────────────────

    public function testBeforeSaveRunsInsideTransactionAndMutatesHeadData(): void
    {
        $gw = $this->gateway($this->dispatcher(['beforeSave']));
        $gw->storedRows[5] = ['id' => 5, 'docState' => 40];

        $result = $gw->saveDocument(['id' => 5, 'description' => 'x']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, FakeEventHandler::$beforeSaveCalls);
        $this->assertSame(5, FakeEventHandler::$beforeSaveCalls[0]['id']);
        $this->assertSame(40, FakeEventHandler::$beforeSaveCalls[0]['original']['docState']);
        // mutace handleru došla do UPDATE hlavičky
        $this->assertSame('set-by-handler', $gw->updateRowCalls[0]['handler_column'] ?? null);
        $this->assertSame(1, $gw->beginCount);
        $this->assertSame(1, $gw->commitCount);
    }

    public function testBeforeSaveExceptionRollsBackAndFailsSave(): void
    {
        FakeEventHandler::$throwOnBeforeSave = true;
        $gw = $this->gateway($this->dispatcher(['beforeSave', 'afterSave']));
        $gw->storedRows[5] = ['id' => 5];

        $result = $gw->saveDocument(['id' => 5]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(1, $gw->rollbackCount);
        $this->assertSame(0, $gw->commitCount);
        $this->assertCount(0, $gw->updateRowCalls);
        $this->assertCount(0, FakeEventHandler::$afterSaveCalls);
    }

    public function testAfterSaveDispatchesAfterCommitForEverySaveAndSwallowsException(): void
    {
        FakeEventHandler::$throwOnAfterSave = true;
        TransitionDocument::$transition = null;
        $gw = $this->gateway($this->dispatcher(['afterSave']));

        // insert: originalData null
        $result = $gw->saveDocument(['description' => 'new']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, FakeEventHandler::$afterSaveCalls);
        $this->assertNull(FakeEventHandler::$afterSaveCalls[0]['original']);
        $this->assertSame(1, FakeEventHandler::$afterSaveCalls[0]['id']);
        $this->assertSame(1, $gw->commitCount);
        // bez přechodu stavu se stateChanged nevolá, afterSave ano
        $this->assertCount(0, FakeEventHandler::$stateCalls);
    }

    // ── Gateway: beforeDelete ───────────────────────────────────────────────

    public function testDeleteDispatchesBeforeDeleteInsideTransactionBeforeChildren(): void
    {
        $gw = $this->gateway($this->dispatcher());
        $gw->storedRows[7] = ['id' => 7];

        $result = $gw->deleteDocument(7);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, FakeEventHandler::$deleteCalls);
        $this->assertSame(7, FakeEventHandler::$deleteCalls[0]['id']);
        $this->assertSame(1, $gw->commitCount);
        $this->assertSame(0, $gw->rollbackCount);
        $this->assertCount(1, $gw->deleteChildrenCalls);
        $this->assertCount(1, $gw->deleteRowCalls);
    }

    public function testDeleteHandlerExceptionRollsBackAndKeepsDocument(): void
    {
        FakeEventHandler::$throwOnBeforeDelete = true;
        $gw = $this->gateway($this->dispatcher());
        $gw->storedRows[7] = ['id' => 7];

        $result = $gw->deleteDocument(7);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(1, $gw->rollbackCount);
        $this->assertSame(0, $gw->commitCount);
        // nic se nesmazalo — dokument i children netknuté
        $this->assertCount(0, $gw->deleteChildrenCalls);
        $this->assertCount(0, $gw->deleteRowCalls);
    }

    // ── ModuleDefinition parsing ────────────────────────────────────────────

    private function moduleData(array $handlers): array
    {
        return [
            'id' => 'economy.accounting',
            'name' => 'Accounting',
            'documentEventHandlers' => $handlers,
        ];
    }

    public function testModuleDefinitionParsesHandlers(): void
    {
        $def = ModuleDefinition::fromArray($this->moduleData([
            ['table' => 'docs_core_heads', 'class' => 'Foo\\Bar', 'events' => ['stateChanged']],
        ]));

        $this->assertSame(
            [['table' => 'docs_core_heads', 'class' => 'Foo\\Bar', 'events' => ['stateChanged']]],
            $def->documentEventHandlers,
        );
    }

    public function testModuleDefinitionDefaultsEventsToAll(): void
    {
        $def = ModuleDefinition::fromArray($this->moduleData([
            ['table' => 'docs_core_heads', 'class' => 'Foo\\Bar'],
        ]));

        $this->assertSame(['beforeSave', 'afterSave', 'stateChanged', 'beforeDelete'], $def->documentEventHandlers[0]['events']);
    }

    public function testModuleDefinitionRejectsUnknownEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray($this->moduleData([
            ['table' => 'docs_core_heads', 'class' => 'Foo\\Bar', 'events' => ['afterCommit']],
        ]));
    }

    public function testModuleDefinitionRejectsMissingClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray($this->moduleData([
            ['table' => 'docs_core_heads'],
        ]));
    }
}
