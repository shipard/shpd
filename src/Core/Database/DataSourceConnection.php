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
}
