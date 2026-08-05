<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Hosting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy lookup API mail-routerů
 * (HostingMailController). Interpretuje jen SQL, které kontroler skutečně
 * posílá (match podle podřetězců). Instancuje se přes
 * ReflectionClass::newInstanceWithoutConstructor(). Záměrně nesdílí
 * InMemoryHostingServerDb — jiná sada dotazů (konvence per-controller).
 */
class InMemoryHostingMailDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $routers = [];
    /** @var array<int, array> */
    public array $dataSources = [];
    private int $nextId = 1;

    public static function create(): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $db */
        $db = $ref->newInstanceWithoutConstructor();
        return $db;
    }

    public function addRouter(array $row): int
    {
        return $this->insert($this->routers, $row + [
            'name' => 'Router',
            'domains' => 'shipard.email',
            'api_key_prefix' => null,
            'api_key_hash' => null,
            'last_seen' => null,
            'docState' => 40,
        ]);
    }

    public function addDataSource(array $row): int
    {
        return $this->insert($this->dataSources, $row + [
            'lifecycle' => 'active',
            'docState' => 40,
            'web_id' => null,
            'url_app' => '',
            'mail_token' => null,
        ]);
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_mail_routers') && str_contains($sql, 'api_key_prefix =')) {
            foreach ($this->routers as $row) {
                if ($row['api_key_prefix'] === $args[1]) {
                    return $row;
                }
            }
            return null;
        }

        throw new \LogicException("InMemoryHostingMailDb: unexpected fetchRow: {$sql}");
    }

    public function fetchAll(mixed ...$args): array
    {
        $sql = (string) $args[0];

        // lookup: WHERE lifecycle = %s AND docState IN %in AND mail_token IS NOT NULL
        if (str_contains($sql, 'hosting_core_data_sources') && str_contains($sql, 'mail_token IS NOT NULL')) {
            $out = [];
            foreach ($this->dataSources as $row) {
                if ($row['lifecycle'] === $args[1]
                    && in_array((int) $row['docState'], (array) $args[2], true)
                    && $row['mail_token'] !== null
                ) {
                    $out[] = $row;
                }
            }
            return $out;
        }

        throw new \LogicException("InMemoryHostingMailDb: unexpected fetchAll: {$sql}");
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        if ($table !== 'hosting_core_mail_routers') {
            throw new \LogicException("InMemoryHostingMailDb: unexpected update of {$table}");
        }
        $id = (int) ($whereParams[0] ?? 0);
        if (isset($this->routers[$id])) {
            $this->routers[$id] = array_merge($this->routers[$id], $data);
        }
    }

    private function insert(array &$storage, array $data): int
    {
        $id = $this->nextId++;
        $data['id'] = $id;
        $storage[$id] = $data;
        return $id;
    }
}
