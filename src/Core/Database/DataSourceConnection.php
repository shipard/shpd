<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Dibi\Connection;
use Shipard\Core\Config\DataSourceConfig;

class DataSourceConnection
{
    private Connection $connection;

    /**
     * Dibi Connection varianta obalí EXISTUJÍCÍ spojení místo otevírání
     * nového — pro kód, který dostává jen dibi (Document hooky) a volá
     * služby stavěné na DataSourceConnection (provisionery, SettingsStore).
     */
    public function __construct(DataSourceConfig|Connection $source)
    {
        $this->connection = $source instanceof Connection ? $source : new Connection([
            'driver'   => 'mysqli',
            'host'     => 'localhost',
            'database' => $source->getDatabaseName(),
            'username' => $source->getDatabaseUser(),
            'password' => $source->getDatabasePassword(),
            'charset'  => 'utf8mb4',
        ]);
    }

    public function getDibiConnection(): Connection
    {
        return $this->connection;
    }

    public function disconnect(): void
    {
        $this->connection->disconnect();
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

    /** @return array<string, bool> col_name → nullable, empty if table doesn't exist */
    public function getTableColumnsNullability(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $result = $this->connection->query('SHOW COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($result->fetchAll() as $row) {
            $columns[$row['Field']] = strtoupper((string) $row['Null']) === 'YES';
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

    /** @return string[] all base table names in the database */
    public function getAllTableNames(): array
    {
        $rows = $this->connection->query('SHOW TABLES')->fetchAll();
        $names = [];
        foreach ($rows as $row) {
            // SHOW TABLES returns rows keyed by a variable column name
            // (`Tables_in_<db>`), so take the first value of each row.
            foreach ($row as $value) {
                $names[] = (string) $value;
                break;
            }
        }
        return $names;
    }

    public function begin(): void
    {
        $this->connection->begin();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollback();
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

    /** Fetch a single scalar value (first column of first row), or null. */
    public function fetchSingle(mixed ...$args): mixed
    {
        $value = $this->connection->fetchSingle(...$args);
        if ($value === false) {
            return null;
        }
        return $this->normalizeValue($value);
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

    /** Number of rows affected by the last INSERT/UPDATE/DELETE query. */
    public function getAffectedRows(): int
    {
        return (int) $this->connection->getAffectedRows();
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
