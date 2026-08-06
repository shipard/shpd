<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Hosting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy AI gateway
 * (HostingAiGatewayController + HostingAiGatewayTokenAuthenticator).
 * Interpretuje jen SQL, které kontroler skutečně posílá (match podle
 * podřetězců). Instancuje se přes ReflectionClass::newInstanceWithoutConstructor().
 * Záměrně nesdílí ostatní hosting fixtures — jiná sada dotazů
 * (konvence per-controller).
 */
class InMemoryHostingAiGatewayDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $tokens = [];
    /** @var array<int, array> */
    public array $dataSources = [];
    /** @var list<array> */
    public array $usage = [];
    public bool $failUsageInsert = false;
    private int $nextId = 1;

    public static function create(): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $db */
        $db = $ref->newInstanceWithoutConstructor();
        return $db;
    }

    public function addToken(array $row): int
    {
        return $this->insert($this->tokens, $row + [
            'data_source' => 0,
            'token_prefix' => '',
            'token_hash' => null,
            'token_encrypted' => null,
            'active' => 1,
            'note' => null,
            'last_used' => null,
            'docState' => 40,
        ]);
    }

    public function addDataSource(array $row): int
    {
        return $this->insert($this->dataSources, $row + [
            'ds_id' => 'abcd-efgh-ijkl-mnop',
            'name' => 'Test DS',
            'lifecycle' => 'active',
            'docState' => 40,
        ]);
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_ai_tokens') && str_contains($sql, 'token_prefix =')) {
            foreach ($this->tokens as $row) {
                if ($row['token_prefix'] === $args[1]) {
                    return $row;
                }
            }
            return null;
        }

        if (str_contains($sql, 'hosting_core_data_sources') && str_contains($sql, 'id =')) {
            return $this->dataSources[(int) $args[1]] ?? null;
        }

        throw new \LogicException("InMemoryHostingAiGatewayDb: unexpected fetchRow: {$sql}");
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        if ($table !== 'hosting_core_ai_tokens') {
            throw new \LogicException("InMemoryHostingAiGatewayDb: unexpected update of {$table}");
        }
        $id = (int) ($whereParams[0] ?? 0);
        if (isset($this->tokens[$id])) {
            $this->tokens[$id] = array_merge($this->tokens[$id], $data);
        }
    }

    public function insertRow(string $table, array $data): int
    {
        if ($table !== 'hosting_core_ai_usage') {
            throw new \LogicException("InMemoryHostingAiGatewayDb: unexpected insert into {$table}");
        }
        if ($this->failUsageInsert) {
            throw new \RuntimeException('simulated usage insert failure');
        }
        $this->usage[] = $data;
        return count($this->usage);
    }

    private function insert(array &$storage, array $data): int
    {
        $id = $this->nextId++;
        $data['id'] = $id;
        $storage[$id] = $data;
        return $id;
    }
}
