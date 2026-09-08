<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Items\ItemsForm;

/**
 * Defaulty nové položky v applyNewRecordDefaults (issue #60): druh =
 * systémový `other` s odvozeným typem, jednotka = systémová `pcs`.
 * Dřív stály v buildFormDefinition nad kopií dat a ke klientovi nedoletěly.
 */
class ItemsFormNewRecordDefaultsTest extends TestCase
{
    /** @param list<string>|null $queries */
    private function db(bool $hasOtherKind = true, bool $hasPcs = true, ?array &$queries = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($hasOtherKind, $hasPcs, &$queries): ?array {
                if ($queries !== null) {
                    $queries[] = $sql;
                }
                if (str_contains($sql, 'economy_items_kinds') && str_contains($sql, 'system_code')) {
                    return $hasOtherKind ? ['id' => 4] : null;
                }
                if (str_contains($sql, 'economy_items_kinds') && str_contains($sql, 'item_type')) {
                    // typ podle id druhu: 4 = Ostatní (3), 2 = Služba (0)
                    return ['item_type' => (int) ($params[0] ?? 0) === 4 ? 3 : 0];
                }
                if (str_contains($sql, 'core_units')) {
                    return $hasPcs ? ['id' => 1] : null;
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    private function form(?DataSourceConnection $db = null): ItemsForm
    {
        $form = new ItemsForm('economy_items');
        if ($db !== null) {
            $form->setDb($db);
        }
        return $form;
    }

    public function testNewItemGetsOtherKindDerivedTypeAndPcsUnit(): void
    {
        $data = ['item_type' => 3]; // schéma default
        $this->form($this->db())->applyNewRecordDefaults($data);

        $this->assertSame(4, $data['item_kind']);
        $this->assertSame(3, $data['item_type']);
        $this->assertSame(1, $data['unit']);
    }

    public function testExplicitKindAndUnitWinButTypeFollowsKind(): void
    {
        $data = ['item_kind' => 2, 'unit' => 5, 'item_type' => 3];
        $this->form($this->db())->applyNewRecordDefaults($data);

        $this->assertSame(2, $data['item_kind']);
        $this->assertSame(5, $data['unit']);
        $this->assertSame(0, $data['item_type'], 'typ se zadat přímo nedá — vždy z druhu');
    }

    public function testWithoutSystemKindNothingIsForced(): void
    {
        $data = ['item_type' => 3];
        $this->form($this->db(hasOtherKind: false))->applyNewRecordDefaults($data);

        $this->assertArrayNotHasKey('item_kind', $data);
        $this->assertSame(3, $data['item_type']);
        $this->assertSame(1, $data['unit'], 'jednotka nezávisí na druhu');
    }

    public function testWithoutPcsUnitLeavesUnitEmpty(): void
    {
        $data = [];
        $this->form($this->db(hasPcs: false))->applyNewRecordDefaults($data);

        $this->assertSame(4, $data['item_kind']);
        $this->assertArrayNotHasKey('unit', $data);
    }

    public function testWithoutDbNothingHappens(): void
    {
        $data = ['item_type' => 3];
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(['item_type' => 3], $data);
    }

    public function testRecalculateItemKindStillDerivesType(): void
    {
        $result = $this->form($this->db())->recalculate('item_kind', ['item_kind' => 2, 'item_type' => 3]);

        $this->assertSame(0, $result->data['item_type']);
    }
}
