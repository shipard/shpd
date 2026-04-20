<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\IdempotencyStore;

class IdempotencyStoreTest extends TestCase
{
    public function testLookupReturnsNullForUnknownKey(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $store = new IdempotencyStore($db);
        $this->assertNull($store->lookup('some-key'));
    }

    public function testLookupReturnsNullForEmptyKey(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');

        $store = new IdempotencyStore($db);
        $this->assertNull($store->lookup(''));
    }

    public function testLookupReturnsMessageIdAndResponseBody(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'message' => 123,
            'response_body' => '{"success":true}',
        ]);

        $store = new IdempotencyStore($db);
        $found = $store->lookup('some-key');

        $this->assertNotNull($found);
        $this->assertSame(123, $found['message_id']);
        $this->assertSame('{"success":true}', $found['response_body']);
    }

    public function testStoreInsertsRecord(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $this->assertSame('core_mail_incoming_idempotency', $table);
                $captured = $data;
                return 1;
            });

        $store = new IdempotencyStore($db);
        $store->store('the-key', 42, '{"success":true,"data":{"ndx":42}}');

        $this->assertSame('the-key', $captured['idempotency_key']);
        $this->assertSame(42, $captured['message']);
        $this->assertSame('{"success":true,"data":{"ndx":42}}', $captured['response_body']);
        $this->assertArrayHasKey('created', $captured);
    }

    public function testPruneReturnsAffectedRowCount(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('execute');
        $db->method('getAffectedRows')->willReturn(17);

        $store = new IdempotencyStore($db);
        $this->assertSame(17, $store->prune(7));
    }
}
