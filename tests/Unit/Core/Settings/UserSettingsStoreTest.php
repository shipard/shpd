<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Settings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\UserSettingsStore;

/**
 * UserSettingsStore — per-user key-value úložiště. DB je mockovaná;
 * ověřujeme dekódování JSON, request-level cache a scoping na user_id
 * (set → execute s userId, mazání → deleteWhere).
 */
class UserSettingsStoreTest extends TestCase
{
    public function testGetDecodesJsonAndCaches(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        // Jen jeden DB hit i při opakovaném get() — druhé čtení jde z cache.
        $db->expects($this->once())
            ->method('fetchSingle')
            ->willReturn(json_encode(['mode' => 'custom']));

        $store = new UserSettingsStore($db, 7);

        $this->assertSame(['mode' => 'custom'], $store->get('account.theme'));
        $this->assertSame(['mode' => 'custom'], $store->get('account.theme'));
    }

    public function testGetMissingReturnsNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(null);

        $store = new UserSettingsStore($db, 7);

        $this->assertNull($store->get('account.language'));
    }

    public function testGetManyMapsRowsAndFillsMissingWithNull(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['key' => 'account.language', 'value' => json_encode('cs')],
        ]);

        $store = new UserSettingsStore($db, 7);
        $result = $store->getMany(['account.language', 'account.theme']);

        $this->assertSame('cs', $result['account.language']);
        $this->assertArrayHasKey('account.theme', $result);
        $this->assertNull($result['account.theme']);
    }

    public function testSetUpsertsWithUserId(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('core_system_user_settings'),
                7,
                $this->anything(),
                $this->anything(),
                $this->anything(),
            );
        $db->expects($this->never())->method('deleteWhere');

        $store = new UserSettingsStore($db, 7);
        $store->set('account.language', 'cs');

        // Po set je hodnota v cache — get nejde do DB.
        $this->assertSame('cs', $store->get('account.language'));
    }

    public function testSetNullDeletesKey(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('execute');
        $db->expects($this->once())
            ->method('deleteWhere')
            ->with('core_system_user_settings', $this->anything(), 7, 'account.language');

        $store = new UserSettingsStore($db, 7);
        $store->set('account.language', null);
    }

    public function testDeleteScopedToUser(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())
            ->method('deleteWhere')
            ->with('core_system_user_settings', $this->anything(), 42, 'account.theme');

        $store = new UserSettingsStore($db, 42);
        $store->delete('account.theme');
    }
}
