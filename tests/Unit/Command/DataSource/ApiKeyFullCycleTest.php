<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Middleware\AuthMiddleware;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\ApiKeyService;

/**
 * Subclass `AuthMiddleware` který místo skutečné DB obsluhy konzultuje
 * in-memory mapu key_hash → row. Stačí pro ověření, že plaintext vyrobený
 * `ApiKeyService` projde skrz středolinný kontrakt middleware (formát
 * tokenu, prefix lookup, hash lookup, is_active gate).
 */
class InMemoryAuthMiddleware extends AuthMiddleware
{
    /** @var array<string, array<string, mixed>> key_hash → row */
    public array $store = [];

    protected function lookupApiKey(string $keyHash, string $keyPrefix, DataSourceConnection $db): ?array
    {
        $row = $this->store[$keyHash] ?? null;
        if ($row === null) {
            return null;
        }
        if ($row['key_prefix'] !== $keyPrefix) {
            return null;
        }
        return $row;
    }

    protected function updateApiKeyLastUsed(int $id, DataSourceConnection $db): void
    {
        // no-op in smoke test
    }
}

class ApiKeyFullCycleTest extends TestCase
{
    public function testCreatedKeyPassesAuthMiddlewareThenFailsAfterRevoke(): void
    {
        // 1) vytvořit klíč přes service. fetchRow nikdy nezavoláme (žádný revoke
        //    check), insertRow odchytíme a dohromady postavíme „DB row".
        $db = $this->createMock(DataSourceConnection::class);

        $stored = null;
        $db->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$stored): int {
                $stored = $data + ['id' => 42];
                return 42;
            });

        $service = new ApiKeyService($db);
        $result = $service->createKey(5, 'smoke-test');

        $this->assertNotNull($stored);
        $this->assertSame(1, $stored['is_active']);
        $this->assertSame(hash('sha256', $result['plaintext']), $stored['key_hash']);
        $this->assertSame($result['keyPrefix'], $stored['key_prefix']);

        // 2) middleware s in-memory storage musí klíč přijmout.
        $middleware = new InMemoryAuthMiddleware();
        $middleware->store[$stored['key_hash']] = $stored;

        $authDb = $this->createMock(DataSourceConnection::class);
        $request = $this->makeRequest($result['plaintext']);
        $route = new Route('persons', 'list');

        $ctx = $middleware->handle($request, $route, $authDb);

        $this->assertInstanceOf(AuthContext::class, $ctx);
        $this->assertTrue($ctx->isAuthenticated);
        $this->assertSame(5, $ctx->userId);
        $this->assertSame('api_key', $ctx->tokenType);

        // 3) revoke přes service (mockovaný fetchRow + updateWhere — service vrátí true).
        $revokeDb = $this->createMock(DataSourceConnection::class);
        $revokeDb->method('fetchRow')->willReturn(['id' => 42, 'is_active' => 1]);
        $revokeDb->expects($this->once())->method('updateWhere');
        $revokeService = new ApiKeyService($revokeDb);

        $this->assertTrue($revokeService->revokeKey(42));

        // 4) simulujeme DB stav po revoke — flipnout is_active v in-memory store.
        $middleware->store[$stored['key_hash']]['is_active'] = 0;

        $ctx2 = $middleware->handle($request, $route, $authDb);
        $this->assertInstanceOf(Response::class, $ctx2);
        $payload = $ctx2->getPayload();
        $this->assertIsArray($payload);
        $this->assertSame('UNAUTHORIZED', $payload['error']['code'] ?? null);
        $this->assertSame('API key is inactive', $payload['error']['message'] ?? null);
    }

    public function testListAfterRevokeRespectsActiveFilter(): void
    {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use ($db): array {
                $active = ['id' => 42, 'is_active' => 1, 'user_login' => 'alice'];
                $inactive = ['id' => 42, 'is_active' => 0, 'user_login' => 'alice'];

                if (str_contains($sql, 'is_active = %i')) {
                    return []; // po revoke žádný aktivní
                }
                return [$inactive]; // include-inactive vrátí revokovaný row
            },
        );

        $service = new ApiKeyService($db);

        $this->assertSame([], $service->listKeys()); // default = aktivní only
        $this->assertCount(1, $service->listKeys(null, true)); // include-inactive
    }

    private function makeRequest(string $token): Request
    {
        return Request::fromArray(
            'GET',
            '/api/v1/persons',
            [],
            '',
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'REMOTE_ADDR'        => '127.0.0.1',
                'HTTP_HOST'          => 'localhost',
            ],
        );
    }
}
