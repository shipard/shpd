<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\HostingApiKeyAuthenticator;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory stub pro authenticator — jediná tabulka klíčů, název
 * parametrizovaný (sdílená validace serverů i mail-routerů).
 */
class InMemoryApiKeyDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $rows = [];
    public string $expectedTable = 'hosting_core_servers';

    public static function create(string $expectedTable): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $db */
        $db = $ref->newInstanceWithoutConstructor();
        $db->expectedTable = $expectedTable;
        return $db;
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];
        if (!str_contains($sql, $this->expectedTable) || !str_contains($sql, 'api_key_prefix =')) {
            throw new \LogicException("InMemoryApiKeyDb: unexpected fetchRow: {$sql}");
        }
        foreach ($this->rows as $row) {
            if ($row['api_key_prefix'] === $args[1]) {
                return $row;
            }
        }
        return null;
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        if ($table !== $this->expectedTable) {
            throw new \LogicException("InMemoryApiKeyDb: unexpected update of {$table}");
        }
        $id = (int) ($whereParams[0] ?? 0);
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $data);
        }
    }
}

class HostingApiKeyAuthenticatorTest extends TestCase
{
    private const TOKEN = 'shpd_hk_' . 'abcdefghijkl' . 'mnopqrstuvwxyz0123456789ABCDEFG';

    /** @return array<string, array{string}> */
    public static function tables(): array
    {
        return [
            'servers' => ['hosting_core_servers'],
            'mail routers' => ['hosting_core_mail_routers'],
        ];
    }

    private function makeDb(string $table, int $docState = 40): InMemoryApiKeyDb
    {
        $db = InMemoryApiKeyDb::create($table);
        $db->rows[1] = [
            'id' => 1,
            'name' => 'Test row',
            'api_key_prefix' => substr(substr(self::TOKEN, strlen('shpd_hk_')), 0, 12),
            'api_key_hash' => hash('sha256', self::TOKEN),
            'last_seen' => null,
            'docState' => $docState,
        ];
        return $db;
    }

    private function request(string|false $token): Request
    {
        $server = ['HTTP_HOST' => '127.0.0.1'];
        if ($token !== false) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        return Request::fromArray('GET', '/api/v1/_hosting/mail/lookup', [], '', $server);
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testValidTokenReturnsRowAndUpdatesLastSeen(string $table): void
    {
        $db = $this->makeDb($table);
        $auth = new HostingApiKeyAuthenticator($table);

        $result = $auth->authenticate($this->request(self::TOKEN), $db);

        $this->assertIsArray($result);
        $this->assertSame(1, (int) $result['id']);
        $this->assertNotNull($db->rows[1]['last_seen']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testMissingHeaderIsRejected(string $table): void
    {
        $auth = new HostingApiKeyAuthenticator($table);
        $result = $auth->authenticate($this->request(false), $this->makeDb($table));

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testForeignTokenTypeIsRejected(string $table): void
    {
        $auth = new HostingApiKeyAuthenticator($table);
        $result = $auth->authenticate($this->request('shpd_ak_' . str_repeat('a', 32)), $this->makeDb($table));

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testUnknownPrefixIsRejected(string $table): void
    {
        $auth = new HostingApiKeyAuthenticator($table);
        $result = $auth->authenticate(
            $this->request('shpd_hk_' . str_repeat('X', 43)),
            $this->makeDb($table),
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testWrongHashIsRejected(string $table): void
    {
        $db = $this->makeDb($table);
        // Stejný prefix, jiný zbytek tokenu → hash nesedí.
        $tampered = substr(self::TOKEN, 0, -4) . 'ZZZZ';
        $auth = new HostingApiKeyAuthenticator($table);

        $result = $auth->authenticate($this->request($tampered), $db);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
        $this->assertNull($db->rows[1]['last_seen']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testRevokedKeyIsRejected(string $table): void
    {
        $db = $this->makeDb($table);
        $db->rows[1]['api_key_hash'] = null;
        $auth = new HostingApiKeyAuthenticator($table);

        $result = $auth->authenticate($this->request(self::TOKEN), $db);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function testArchivedRowIsRejected(string $table): void
    {
        $db = $this->makeDb($table, docState: 90);
        $auth = new HostingApiKeyAuthenticator($table);

        $result = $auth->authenticate($this->request(self::TOKEN), $db);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $this->getStatus($result));
    }

    public function testCustomErrorMessagesArePassedThrough(): void
    {
        $auth = new HostingApiKeyAuthenticator(
            'hosting_core_servers',
            errorMessage: 'Server key required',
            invalidMessage: 'Invalid server key',
        );

        $missing = $auth->authenticate($this->request(false), $this->makeDb('hosting_core_servers'));
        $this->assertInstanceOf(Response::class, $missing);
        $this->assertSame('Server key required', $missing->getPayload()['error']['message']);

        $invalid = $auth->authenticate(
            $this->request('shpd_hk_' . str_repeat('X', 43)),
            $this->makeDb('hosting_core_servers'),
        );
        $this->assertInstanceOf(Response::class, $invalid);
        $this->assertSame('Invalid server key', $invalid->getPayload()['error']['message']);
    }
}
