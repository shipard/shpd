<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

class ServerConfig
{
    private array $data = [];

    public function __construct(private readonly string $configPath = '/etc/shipard/server.json')
    {
    }

    public function load(): void
    {
        if (!file_exists($this->configPath)) {
            throw new \RuntimeException("Config file not found: {$this->configPath}");
        }

        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in config file: " . json_last_error_msg());
        }

        $required = ['host', 'port', 'admin_user', 'admin_password', 'mode'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required config field: {$field}");
            }
        }

        $this->data = $data;
    }

    public function getHost(): string
    {
        return $this->data['host'];
    }

    public function getPort(): int
    {
        return (int) $this->data['port'];
    }

    public function getAdminUser(): string
    {
        return $this->data['admin_user'];
    }

    public function getAdminPassword(): string
    {
        return $this->data['admin_password'];
    }

    public function getMode(): string
    {
        return $this->data['mode'];
    }

    public function getDomainsFile(): string
    {
        return $this->data['domainsFile'] ?? '/etc/shipard/domains.json';
    }
}
