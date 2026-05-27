<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\ApiKeyService;

class ApiKeyServiceTest extends TestCase
{
    private DataSourceConnection&MockObject $db;
    private ApiKeyService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DataSourceConnection::class);
        $this->service = new ApiKeyService($this->db);
    }

    public function testGenerateTokenHasRightFormat(): void
    {
        $token = ApiKeyService::generateToken();
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $token);
    }

    public function testGenerateTokenIsRandom(): void
    {
        $a = ApiKeyService::generateToken();
        $b = ApiKeyService::generateToken();
        $this->assertNotSame($a, $b);
    }

    public function testCreateKeyHashesPlaintextAndStoresPrefix(): void
    {
        $captured = null;
        $this->db->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $this->assertSame('core_system_api_keys', $table);
                $captured = $data;
                return 42;
            });

        $result = $this->service->createKey(7, 'integration-x');

        $this->assertSame(42, $result['id']);
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $result['plaintext']);
        $this->assertSame(12, strlen($result['keyPrefix']));

        $this->assertSame(7, $captured['user_id']);
        $this->assertSame('integration-x', $captured['name']);
        $this->assertSame(1, $captured['is_active']);
        $this->assertNull($captured['last_used_at']);
        $this->assertNull($captured['expires_at']);
        $this->assertNull($captured['allowed_ips']);
        $this->assertSame(64, strlen($captured['key_hash']));
        $this->assertSame(hash('sha256', $result['plaintext']), $captured['key_hash']);
        $this->assertSame($result['keyPrefix'], $captured['key_prefix']);
        $this->assertSame(substr($result['plaintext'], strlen('shpd_ak_'), 12), $captured['key_prefix']);
        $this->assertStringNotContainsString($result['plaintext'], json_encode($captured) ?: '');
    }

    public function testCreateKeyStoresAllowedIpsAsJsonArray(): void
    {
        $captured = null;
        $this->db->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $this->service->createKey(7, 'k', ['1.2.3.4', '5.6.7.8']);

        $this->assertSame(json_encode(['1.2.3.4', '5.6.7.8']), $captured['allowed_ips']);
    }

    public function testCreateKeyStoresEmptyAllowedIpsAsNull(): void
    {
        $captured = null;
        $this->db->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $this->service->createKey(7, 'k', []);

        $this->assertNull($captured['allowed_ips'], 'empty array must store NULL, not "[]"');
    }

    public function testCreateKeyStoresExpiresAt(): void
    {
        $captured = null;
        $this->db->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $expires = new \DateTimeImmutable('2026-12-31 23:59:59');
        $this->service->createKey(7, 'k', [], $expires);

        $this->assertSame('2026-12-31 23:59:59', $captured['expires_at']);
    }

    public function testListKeysActiveOnlyByDefault(): void
    {
        $this->db->expects($this->once())
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, ...$params): array {
                $this->assertStringContainsString('k.is_active = %i', $sql);
                $this->assertSame([1], $params);
                return [['id' => 1, 'is_active' => 1, 'user_login' => 'alice']];
            });

        $rows = $this->service->listKeys();

        $this->assertCount(1, $rows);
        $this->assertSame('alice', $rows[0]['user_login']);
    }

    public function testListKeysIncludeInactive(): void
    {
        $this->db->expects($this->once())
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, ...$params): array {
                $this->assertStringNotContainsString('k.is_active', $sql);
                $this->assertSame([], $params);
                return [];
            });

        $this->service->listKeys(null, true);
    }

    public function testListKeysFilterByUserId(): void
    {
        $this->db->expects($this->once())
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, ...$params): array {
                $this->assertStringContainsString('k.user_id = %i', $sql);
                $this->assertContains(5, $params);
                return [];
            });

        $this->service->listKeys(5, true);
    }

    public function testFindUserByLogin(): void
    {
        $row = ['id' => 7, 'login' => 'alice', 'email' => 'a@x'];
        $this->db->method('fetchRow')
            ->willReturnCallback(function (string $sql, $value) use ($row): ?array {
                if (str_contains($sql, 'login = %s')) {
                    return $value === 'alice' ? $row : null;
                }
                return null;
            });

        $this->assertSame($row, $this->service->findUser('alice'));
    }

    public function testFindUserByEmailWhenLoginMisses(): void
    {
        $row = ['id' => 7, 'login' => 'alice', 'email' => 'a@x'];
        $this->db->method('fetchRow')
            ->willReturnCallback(function (string $sql, $value) use ($row): ?array {
                if (str_contains($sql, 'email = %s') && $value === 'a@x') {
                    return $row;
                }
                return null;
            });

        $this->assertSame($row, $this->service->findUser('a@x'));
    }

    public function testFindUserByNumericId(): void
    {
        $row = ['id' => 7, 'login' => 'alice', 'email' => 'a@x'];
        $this->db->method('fetchRow')
            ->willReturnCallback(function (string $sql, $value) use ($row): ?array {
                if (str_contains($sql, 'id = %i') && $value === 7) {
                    return $row;
                }
                return null;
            });

        $this->assertSame($row, $this->service->findUser('7'));
    }

    public function testFindUserReturnsNullWhenAmbiguous(): void
    {
        $byLogin = ['id' => 1, 'login' => 'bob', 'email' => 'l@x'];
        $byEmail = ['id' => 2, 'login' => 'bob_alt', 'email' => 'bob'];

        $this->db->method('fetchRow')
            ->willReturnCallback(function (string $sql, $value) use ($byLogin, $byEmail): ?array {
                if (str_contains($sql, 'login = %s') && $value === 'bob') {
                    return $byLogin;
                }
                if (str_contains($sql, 'email = %s') && $value === 'bob') {
                    return $byEmail;
                }
                return null;
            });

        $this->assertNull($this->service->findUser('bob'));
    }

    public function testFindUserReturnsNullWhenNotFound(): void
    {
        $this->db->method('fetchRow')->willReturn(null);
        $this->assertNull($this->service->findUser('nobody'));
    }

    public function testRevokeKeyDeactivatesActiveKey(): void
    {
        $this->db->method('fetchRow')->willReturn(['id' => 42, 'is_active' => 1]);

        $this->db->expects($this->once())
            ->method('updateWhere')
            ->with(
                'core_system_api_keys',
                $this->callback(fn(array $data) => $data['is_active'] === 0 && isset($data['modified'])),
                'id = %i',
                42,
            );

        $this->assertTrue($this->service->revokeKey(42));
    }

    public function testRevokeKeyReturnsFalseWhenAlreadyInactive(): void
    {
        $this->db->method('fetchRow')->willReturn(['id' => 42, 'is_active' => 0]);
        $this->db->expects($this->never())->method('updateWhere');

        $this->assertFalse($this->service->revokeKey(42));
    }

    public function testRevokeKeyReturnsFalseWhenNotFound(): void
    {
        $this->db->method('fetchRow')->willReturn(null);
        $this->db->expects($this->never())->method('updateWhere');

        $this->assertFalse($this->service->revokeKey(42));
    }

    public function testFindKeyByPrefixReturnsRow(): void
    {
        $row = ['id' => 42, 'key_prefix' => 'aabbccdd1122', 'user_login' => 'alice'];
        $this->db->expects($this->once())
            ->method('fetchRow')
            ->with(
                $this->stringContains('k.key_prefix = %s'),
                'aabbccdd1122',
            )
            ->willReturn($row);

        $this->assertSame($row, $this->service->findKeyByPrefix('aabbccdd1122'));
    }

    public function testCountKeysByPrefix(): void
    {
        $this->db->method('fetchSingle')->willReturn(2);

        $this->assertSame(2, $this->service->countKeysByPrefix('aabbccdd1122'));
    }

    public function testFindKeyByIdJoinsUserLogin(): void
    {
        $row = ['id' => 42, 'user_id' => 5, 'user_login' => 'alice'];
        $this->db->expects($this->once())
            ->method('fetchRow')
            ->with(
                $this->stringContains('k.id = %i'),
                42,
            )
            ->willReturn($row);

        $this->assertSame($row, $this->service->findKeyById(42));
    }
}
