<?php

declare(strict_types=1);

namespace Shipard\Core\Database;

use Dibi\Connection;
use Shipard\Core\Config\ServerConfig;

class DatabaseManager
{
    private Connection $connection;

    public function __construct(private readonly ServerConfig $config)
    {
        $this->connection = new Connection([
            'driver'   => 'mysqli',
            'host'     => $config->getHost(),
            'port'     => $config->getPort(),
            'username' => $config->getAdminUser(),
            'password' => $config->getAdminPassword(),
            'charset'  => 'utf8mb4',
        ]);
    }

    public function createDatabase(string $dbName): void
    {
        $this->connection->query(
            'CREATE DATABASE %n CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci',
            $dbName
        );
    }

    public function createUser(string $user, string $password, string $dbName): void
    {
        $this->connection->query(
            "CREATE USER %s@'localhost' IDENTIFIED BY %s",
            $user,
            $password
        );

        $this->connection->query(
            "GRANT ALL PRIVILEGES ON %n.* TO %s@'localhost'",
            $dbName,
            $user
        );

        $this->connection->query('FLUSH PRIVILEGES');
    }

    public function generatePassword(): string
    {
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|';
        $length = 32;
        $charsetLength = strlen($charset);
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $charset[random_int(0, $charsetLength - 1)];
        }

        return $password;
    }
}
