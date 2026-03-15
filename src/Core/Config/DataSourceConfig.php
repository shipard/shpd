<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

class DataSourceConfig
{
    private array $data = [];

    public function __construct(private readonly string $dataSourceDir)
    {
        $this->load();
    }

    private function load(): void
    {
        $configFile = $this->dataSourceDir . '/config/main.json';

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Data source config not found: {$configFile}");
        }

        $content = file_get_contents($configFile);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in data source config: " . json_last_error_msg());
        }

        $required = ['id', 'name', 'database_name', 'database_user', 'database_password', 'created'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required data source config field: {$field}");
            }
        }

        $this->data = $data;
    }

    public function getId(): string
    {
        return $this->data['id'];
    }

    public function getName(): string
    {
        return $this->data['name'];
    }

    public function getDatabaseName(): string
    {
        return $this->data['database_name'];
    }

    public function getDatabaseUser(): string
    {
        return $this->data['database_user'];
    }

    public function getDatabasePassword(): string
    {
        return $this->data['database_password'];
    }

    public function getCreated(): string
    {
        return $this->data['created'];
    }

    public function getModules(): array
    {
        return $this->data['modules'] ?? [];
    }
}
