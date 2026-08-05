<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Module\Hosting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * In-memory náhrada DataSourceConnection pro testy OIDC OP hostingu.
 * Interpretuje jen SQL, které HostingOidcController + SettingsStore
 * skutečně posílají (match podle podřetězců). Instancuje se přes
 * ReflectionClass::newInstanceWithoutConstructor(). Záměrně nesdílí
 * InMemoryAuthDb — jeho handlery jsou auth-transaction-specific.
 */
class InMemoryHostingOidcDb extends DataSourceConnection
{
    /** @var array<int, array> */
    public array $dataSources = [];
    /** @var array<int, array> */
    public array $oidcCodes = [];
    /** @var array<int, array> */
    public array $users = [];
    /** @var array<string, string> Hodnoty JSON-encoded jako v core_system_settings. */
    public array $settings = [];
    public array $executedQueries = [];
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

    public function addDataSource(array $row): int
    {
        return $this->insert($this->dataSources, $row + [
            'lifecycle' => 'active',
            'docState' => 40,
            'oidc_client_secret' => null,
            'oidc_redirect_uri' => null,
        ]);
    }

    public function addOidcCode(array $row): int
    {
        return $this->insert($this->oidcCodes, $row + ['user' => null, 'code' => null]);
    }

    public function addUser(array $row): int
    {
        return $this->insert($this->users, $row);
    }

    public function insertRow(string $table, array $data): int
    {
        return match ($table) {
            // Nullable sloupce vrací reálná DB vždy — doplnit jako NULL.
            'hosting_core_oidc_codes' => $this->insert(
                $this->oidcCodes,
                $data + ['user' => null, 'code' => null],
            ),
            default => throw new \LogicException("InMemoryHostingOidcDb: unexpected insert into {$table}"),
        };
    }

    public function fetchRow(mixed ...$args): ?array
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'hosting_core_data_sources')) {
            if (str_contains($sql, 'ds_id =')) {
                foreach ($this->dataSources as $row) {
                    if ($row['ds_id'] === $args[1]) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'id =')) {
                return $this->dataSources[(int) $args[1]] ?? null;
            }
        }
        if (str_contains($sql, 'hosting_core_oidc_codes')) {
            if (str_contains($sql, 'txn =')) {
                foreach ($this->oidcCodes as $row) {
                    if ($row['txn'] === $args[1]) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'code =')) {
                foreach ($this->oidcCodes as $row) {
                    if (($row['code'] ?? null) === $args[1]) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($sql, 'id =')) {
                return $this->oidcCodes[(int) $args[1]] ?? null;
            }
        }
        if (str_contains($sql, 'core_system_users')) {
            return $this->users[(int) $args[1]] ?? null;
        }

        throw new \LogicException("InMemoryHostingOidcDb: unexpected fetchRow: {$sql}");
    }

    public function fetchSingle(mixed ...$args): mixed
    {
        $sql = (string) $args[0];

        if (str_contains($sql, 'core_system_settings')) {
            return $this->settings[(string) $args[1]] ?? null;
        }

        throw new \LogicException("InMemoryHostingOidcDb: unexpected fetchSingle: {$sql}");
    }

    public function execute(mixed ...$args): void
    {
        $this->executedQueries[] = $args;
        $sql = (string) $args[0];

        if (str_contains($sql, 'DELETE FROM hosting_core_oidc_codes WHERE expires <')) {
            foreach ($this->oidcCodes as $id => $row) {
                if ($row['expires'] < $args[1]) {
                    unset($this->oidcCodes[$id]);
                }
            }
            return;
        }
        if (str_contains($sql, 'DELETE FROM hosting_core_oidc_codes WHERE id =')) {
            unset($this->oidcCodes[(int) $args[1]]);
            return;
        }
        if (str_contains($sql, 'UPDATE hosting_core_oidc_codes SET user =')) {
            // args: userId, code, expires, id — WHERE id = %i AND user IS NULL
            $id = (int) $args[4];
            if (isset($this->oidcCodes[$id]) && $this->oidcCodes[$id]['user'] === null) {
                $this->oidcCodes[$id]['user'] = $args[1];
                $this->oidcCodes[$id]['code'] = $args[2];
                $this->oidcCodes[$id]['expires'] = $args[3];
            }
            return;
        }

        throw new \LogicException("InMemoryHostingOidcDb: unexpected execute: {$sql}");
    }

    private function insert(array &$storage, array $data): int
    {
        $id = $this->nextId++;
        $data['id'] = $id;
        $storage[$id] = $data;
        return $id;
    }
}
