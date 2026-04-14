<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Dibi\Connection;
use Shipard\Core\Config\DataSourceConfig;

class DataSourceConnection
{
    private Connection $connection;

    public function __construct(DataSourceConfig $config)
    {
        $this->connection = new Connection([
            'driver'   => 'mysqli',
            'host'     => 'localhost',
            'database' => $config->getDatabaseName(),
            'username' => $config->getDatabaseUser(),
            'password' => $config->getDatabasePassword(),
        ]);
    }

    public function getDibiConnection(): Connection
    {
        return $this->connection;
    }

    private function tableExists(string $table): bool
    {
        $result = $this->connection->query('SHOW TABLES LIKE %s', $table);
        return $result->count() > 0;
    }

    /** @return array<string, string> col_name → type (e.g. 'varchar(100)'), empty if table doesn't exist */
    public function getTableColumns(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $result = $this->connection->query('SHOW COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($result->fetchAll() as $row) {
            $columns[$row['Field']] = strtolower((string) $row['Type']);
        }

        return $columns;
    }

    /** @return string[] index names (excluding PRIMARY), empty if table doesn't exist */
    public function getTableIndexes(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $result = $this->connection->query('SHOW INDEX FROM `' . $table . '`');
        $indexes = [];
        foreach ($result->fetchAll() as $row) {
            $keyName = (string) $row['Key_name'];
            if ($keyName !== 'PRIMARY' && !in_array($keyName, $indexes, true)) {
                $indexes[] = $keyName;
            }
        }

        return $indexes;
    }

    public function executeSQL(string $sql): void
    {
        $this->connection->query($sql);
    }

    /** Fetch one row as associative array, or null if not found. */
    public function fetchRow(mixed ...$args): ?array
    {
        $row = $this->connection->fetch(...$args);
        if ($row === null) {
            return null;
        }
        $result = [];
        foreach ($row as $key => $value) {
            $result[$key] = $this->normalizeValue($value);
        }
        return $result;
    }

    /** Fetch all rows as array of associative arrays. */
    public function fetchAll(mixed ...$args): array
    {
        $rows = $this->connection->fetchAll(...$args);
        return array_map(function ($row): array {
            $result = [];
            foreach ($row as $key => $value) {
                $result[$key] = $this->normalizeValue($value);
            }
            return $result;
        }, $rows);
    }

    /**
     * Normalize a value from Dibi for JSON serialization.
     * Converts DateTime objects to ISO strings.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            // Dibi\DateTime for DATE columns has time 00:00:00 — detect by checking
            // whether the time portion is midnight and format accordingly.
            $h = (int) $value->format('H');
            $m = (int) $value->format('i');
            $s = (int) $value->format('s');
            if ($h === 0 && $m === 0 && $s === 0) {
                // Likely a DATE column — return YYYY-MM-DD
                return $value->format('Y-m-d');
            }
            // DATETIME column — return ISO 8601
            return $value->format('Y-m-d\TH:i:s');
        }
        return $value;
    }

    /** Execute a query (INSERT/UPDATE/DELETE) without returning rows. */
    public function execute(mixed ...$args): void
    {
        $this->connection->query(...$args);
    }

    /** Insert a row and return the auto-increment ID. */
    public function insertRow(string $table, array $data): int
    {
        $this->connection->insert($table, $data)->execute();
        return (int) $this->connection->getInsertId();
    }

    /** Update rows matching $where (Dibi format, e.g. 'id = %i', 5). */
    public function updateWhere(string $table, array $data, string $where, mixed ...$whereParams): void
    {
        $this->connection->update($table, $data)->where($where, ...$whereParams)->execute();
    }

    /** Delete rows matching $where (Dibi format). */
    public function deleteWhere(string $table, string $where, mixed ...$whereParams): void
    {
        $this->connection->delete($table)->where($where, ...$whereParams)->execute();
    }
}
