<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Hosting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy provisioning API
 * (HostingServerController + SettingsStore). Interpretuje jen SQL, které
 * kontroler skutečně posílá (match podle podřetězců). Instancuje se přes
 * ReflectionClass::newInstanceWithoutConstructor(). Záměrně nesdílí
 * InMemoryHostingOidcDb — jiná sada dotazů (updateWhere, fetchAll).
 */
class InMemoryHostingServerDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $servers = [];
    /** @var array<int, array> */
    public array $dataSources = [];
    /** @var array<int, array> */
    public array $dsUsers = [];
    /** @var array<int, array> */
    public array $users = [];
    /** @var array<int, array> */
    public array $aiTokens = [];
    /** @var array<string, string> Hodnoty JSON-encoded jako v core_system_settings. */
    public array $settings = [];
    private int $nextId = 1;

    public static function create(): self
    {
        $ref = new \ReflectionClass(self::class);
        /** @var self $db */
        $db = $ref->newInstanceWithoutConstructor();
        return $db;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->settings[$key] = json_encode($value);
    }

    public function addServer(array $row): int
    {
        return $this->insert($this->servers, $row + [
            'api_key_prefix' => null,
            'api_key_hash' => null,
            'can_provision' => 0,
            'last_seen' => null,
            'last_version' => null,
            'docState' => 40,
        ]);
    }

    public function addDataSource(array $row): int
    {
        return $this->insert($this->dataSources, $row + [
            'lifecycle' => 'active',
            'docState' => 40,
            'server' => null,
            'owner' => null,
            'web_id' => null,
            'install_module' => null,
            'url_app' => '',
            'oidc_client_secret' => null,
            'oidc_redirect_uri' => null,
            'provision_error' => null,
            'claimed_at' => null,
        ]);
    }

    public function addUser(array $row): int
    {
        return $this->insert($this->users, $row + ['is_active' => 1, 'email' => null]);
    }

    public function addAiToken(array $row): int
    {
        return $this->insert($this->aiTokens, $row + [
            'data_source' => 0,
            'token_prefix' => '',
            'token_hash' => '',
            'token_encrypted' => null,
            'active' => 1,
            'docState' => 40,
        ]);
    }

    public function insertRow(string $table, array $data): int
    {
        return match ($table) {
            'hosting_core_ds_users' => $this->insert($this->dsUsers, $data),
            'hosting_core_ai_tokens' => $this->insert($this->aiTokens, $data),
            default => throw new \LogicException("InMemoryHostingServerDb: unexpected insert into {$table}"),
        };
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_servers')) {
            if (str_contains($sql, 'api_key_prefix =')) {
                foreach ($this->servers as $row) {
                    if ($row['api_key_prefix'] === $args[1]) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'id =')) {
                return $this->servers[(int) $args[1]] ?? null;
            }
        }
        if (str_contains($sql, 'hosting_core_ds_users')) {
            foreach ($this->dsUsers as $row) {
                if ((int) $row['user'] === (int) $args[1] && (int) $row['data_source'] === (int) $args[2]) {
                    return $row;
                }
            }
            return null;
        }
        if (str_contains($sql, 'hosting_core_data_sources') && str_contains($sql, 'id =')) {
            return $this->dataSources[(int) $args[1]] ?? null;
        }
        // queue ai sekce: WHERE data_source = %i AND active = 1
        //                 AND docState IN %in ORDER BY id DESC
        if (str_contains($sql, 'hosting_core_ai_tokens') && str_contains($sql, 'data_source =')) {
            foreach (array_reverse($this->aiTokens, true) as $row) {
                if ((int) $row['data_source'] === (int) $args[1]
                    && (int) $row['active'] === 1
                    && in_array((int) $row['docState'], (array) $args[2], true)
                ) {
                    return $row;
                }
            }
            return null;
        }
        if (str_contains($sql, 'core_system_users')) {
            $row = $this->users[(int) $args[1]] ?? null;
            if ($row !== null && str_contains($sql, 'is_active') && !(int) $row['is_active']) {
                return null;
            }
            return $row;
        }

        throw new \LogicException("InMemoryHostingServerDb: unexpected fetchRow: {$sql}");
    }

    public function fetchSingle(mixed ...$args): mixed
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'core_system_settings')) {
            return $this->settings[(string) $args[1]] ?? null;
        }

        throw new \LogicException("InMemoryHostingServerDb: unexpected fetchSingle: {$sql}");
    }

    public function fetchAll(mixed ...$args): array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_data_sources')) {
            // queue: WHERE server = %i AND lifecycle IN %in AND docState IN %in
            if (str_contains($sql, 'lifecycle IN')) {
                $out = [];
                foreach ($this->dataSources as $row) {
                    if ((int) ($row['server'] ?? 0) === (int) $args[1]
                        && in_array($row['lifecycle'], (array) $args[2], true)
                        && in_array((int) $row['docState'], (array) $args[3], true)
                    ) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
            // reconcile: WHERE server = %i AND lifecycle = %s
            if (str_contains($sql, 'lifecycle =')) {
                $out = [];
                foreach ($this->dataSources as $row) {
                    if ((int) ($row['server'] ?? 0) === (int) $args[1] && $row['lifecycle'] === $args[2]) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        }

        throw new \LogicException("InMemoryHostingServerDb: unexpected fetchAll: {$sql}");
    }

    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        $id = (int) ($whereParams[0] ?? 0);
        if ($table === 'hosting_core_servers') {
            if (isset($this->servers[$id])) {
                $this->servers[$id] = array_merge($this->servers[$id], $data);
            }
            return;
        }
        if ($table === 'hosting_core_data_sources') {
            if (isset($this->dataSources[$id])) {
                $this->dataSources[$id] = array_merge($this->dataSources[$id], $data);
            }
            return;
        }
        throw new \LogicException("InMemoryHostingServerDb: unexpected update of {$table}");
    }

    private function insert(array &$storage, array $data): int
    {
        $id = $this->nextId++;
        $data['id'] = $id;
        $storage[$id] = $data;
        return $id;
    }
}
