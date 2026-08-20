<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\ContentTagBackfill;

/**
 * Reverzní otagování živých položek (D34) — jen prázdné štítky, jen
 * jednoznačné účty, zápis přes gateway seam.
 */
class ContentTagBackfillTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    private static function itemsTableDef(bool $withAccountingColumn = true): TableDefinition
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 25, 'nullable' => false],
        ];
        if ($withAccountingColumn) {
            $columns[] = ['id' => 'accounting_account', 'name' => 'Account', 'type' => 'int', 'nullable' => true];
        }
        return TableDefinition::fromArray(['tableId' => 320, 'name' => 'Items', 'columns' => $columns]);
    }

    /** @param list<array<string, mixed>> $untagged */
    private function db(array $untagged): MockObject&DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($untagged): array {
                return str_contains((string) $args[0], 'content_tags IS NULL') ? $untagged : [];
            },
        );
        return $db;
    }

    /**
     * @param list<array<string, mixed>> $untagged řádky dotazu (account_number)
     * @param array<int, array<string, mixed>> $itemRows id → celý řádek položky
     * @param array<string, list<string>> $tagsByAccount mapa z nabídky
     */
    private function backfill(
        array $untagged,
        array $itemRows = [],
        array $tagsByAccount = ['518202' => ['it.internet'], '501100' => ['office.supplies', 'vehicle.parts']],
        bool $withAccountingColumn = true,
        array $taxonomy = ['it.internet' => ['name' => 'Internet'], 'office.supplies' => ['name' => 'Kancelář']],
    ): ContentTagBackfill {
        $db = $this->db($untagged);

        $offer = $this->getMockBuilder(AccountingItemsOffer::class)
            ->setConstructorArgs([$db, new ModulePathResolver([dirname(__DIR__, 5) . '/modules'])])
            ->onlyMethods(['tagsByAccount'])
            ->getMock();
        $offer->method('tagsByAccount')->willReturn($tagsByAccount);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([['core.exchange.contentTags', $taxonomy]]);

        $this->saved = [];
        return new class($db, $offer, $config, ['economy_items' => self::itemsTableDef($withAccountingColumn)], $itemRows, $this) extends ContentTagBackfill {
            /**
             * @param array<string, TableDefinition> $tables
             * @param array<int, array<string, mixed>> $itemRows
             */
            public function __construct(
                DataSourceConnection $db,
                AccountingItemsOffer $offer,
                ConfigRuntime $config,
                array $tables,
                private readonly array $itemRows,
                private readonly ContentTagBackfillTest $test,
            ) {
                parent::__construct($db, $offer, $config, $tables);
            }

            protected function fetchItemRow(int $id): ?array
            {
                return $this->itemRows[$id] ?? null;
            }

            protected function saveItemRow(array $payload): DocumentResult
            {
                $this->test->saved[] = $payload;
                return isset($payload['fail'])
                    ? DocumentResult::error('nope')
                    : DocumentResult::ok(['id' => (int) $payload['id']] + $payload);
            }
        };
    }

    public function testPlanTakesOnlyUnambiguousAccounts(): void
    {
        $backfill = $this->backfill([
            ['id' => 1, 'code' => 'A1', 'name' => 'Internet', 'account_number' => '518202'],
            ['id' => 2, 'code' => 'A2', 'name' => 'Papír', 'account_number' => '501100'],   // kolizní účet
            ['id' => 3, 'code' => 'A3', 'name' => 'Něco', 'account_number' => '999999'],    // mimo nabídku
        ]);

        $plan = $backfill->plan();
        $this->assertCount(1, $plan);
        $this->assertSame(1, $plan[0]['id']);
        $this->assertSame('it.internet', $plan[0]['tag']);
        $this->assertSame('518202', $plan[0]['account']);

        $this->assertSame(
            ['candidates' => 1, 'ambiguousAccount' => 1, 'unmappedAccount' => 1],
            $backfill->planSkipped(),
        );
    }

    public function testTagOutsideTaxonomyIsNotApplied(): void
    {
        $backfill = $this->backfill(
            [['id' => 1, 'code' => 'A1', 'name' => 'X', 'account_number' => '518202']],
            tagsByAccount: ['518202' => ['legacy.tag']],
        );
        $this->assertSame([], $backfill->plan());
    }

    public function testApplyMergesWithExistingTags(): void
    {
        $backfill = $this->backfill(
            [['id' => 7, 'code' => 'A1', 'name' => 'Internet', 'account_number' => '518202']],
            itemRows: [7 => ['id' => 7, 'code' => 'A1', 'name' => 'Internet', 'content_tags' => '["it.phone"]']],
        );

        $result = $backfill->apply($backfill->plan());

        $this->assertSame([['id' => 7, 'tags' => ['it.phone', 'it.internet']]], $result['updated']);
        $this->assertSame([], $result['failed']);
        $this->assertCount(1, $this->saved);
        $this->assertSame(['it.phone', 'it.internet'], $this->saved[0]['content_tags']);
        $this->assertSame('A1', $this->saved[0]['code'], 'gateway dostane celý řádek, ne jen štítky');
    }

    public function testMissingItemAndFailedSaveDoNotAbortBatch(): void
    {
        $backfill = $this->backfill(
            [
                ['id' => 1, 'code' => 'A1', 'name' => 'X', 'account_number' => '518202'],
                ['id' => 2, 'code' => 'A2', 'name' => 'Y', 'account_number' => '518202'],
                ['id' => 3, 'code' => 'A3', 'name' => 'Z', 'account_number' => '518202'],
            ],
            itemRows: [
                1 => ['id' => 1, 'code' => 'A1', 'content_tags' => null, 'fail' => true],
                3 => ['id' => 3, 'code' => 'A3', 'content_tags' => null],
            ],
        );

        $result = $backfill->apply($backfill->plan());

        $this->assertSame([['id' => 3, 'tags' => ['it.internet']]], $result['updated']);
        $this->assertSame(
            [['id' => 1, 'reason' => 'save_failed'], ['id' => 2, 'reason' => 'not_found']],
            $result['failed'],
        );
    }

    public function testAccountingInactiveIsDetectedFromTableDefinition(): void
    {
        $this->assertTrue($this->backfill([])->accountingActive());
        $this->assertFalse($this->backfill([], withAccountingColumn: false)->accountingActive());
    }

    public function testEmptyOfferMeansEmptyPlanAndIsReportedAsUnavailable(): void
    {
        $backfill = $this->backfill(
            [['id' => 1, 'code' => 'A1', 'name' => 'X', 'account_number' => '518202']],
            tagsByAccount: [],
        );
        $this->assertSame([], $backfill->plan());
        // Bez nastavené varianty osnovy nesmí volající tvrdit „účet mimo
        // nabídku" — mapa je prázdná, ne účty špatné.
        $this->assertFalse($backfill->offerAvailable());
        $this->assertTrue($this->backfill([])->offerAvailable());
    }
}
