<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Permission contract for /opt/shipard and /etc/shipard.
 *
 * @phpstan-type SpecEntry array{path: string, type: 'dir'|'file', owner: 'root'|'user', group: 'user', mode: int, optional?: bool}
 */
final class PermissionSpec
{
    public function __construct(
        private readonly string $shipardUser,
        private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
        private readonly string $logDir = '/opt/shipard/log',
        private readonly string $configDir = '/etc/shipard',
        private readonly string $shipardRoot = '/opt/shipard',
    ) {}

    /**
     * @return list<SpecEntry>
     */
    public function getGlobalEntries(): array
    {
        return [
            ['path' => $this->configDir,                  'type' => 'dir',  'owner' => 'root', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->configDir . '/server.json', 'type' => 'file', 'owner' => 'root', 'group' => 'user', 'mode' => 0640],
            ['path' => $this->shipardRoot,                'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->dataSourcesDir,             'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->logDir,                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->logDir . '/shipard.log',    'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0640, 'optional' => true],
        ];
    }

    /**
     * @return list<SpecEntry>
     */
    public function getDataSourceEntries(string $dsDir): array
    {
        return [
            ['path' => $dsDir,                                'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $dsDir . '/config',                    'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $dsDir . '/config/main.json',          'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0600],
            ['path' => $dsDir . '/config/configuration',      'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/secrets',                   'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0700, 'optional' => true],
            ['path' => $dsDir . '/secrets/secrets.key',       'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0600, 'optional' => true],
            ['path' => $dsDir . '/att',                       'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/cache',                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
            ['path' => $dsDir . '/cache/thumbnails',          'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true],
        ];
    }

    public function getShipardUser(): string
    {
        return $this->shipardUser;
    }

    public function getDataSourcesDir(): string
    {
        return $this->dataSourcesDir;
    }

    public function getConfigDir(): string
    {
        return $this->configDir;
    }

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    public function getShipardRoot(): string
    {
        return $this->shipardRoot;
    }

    /**
     * Resolves 'user' → $shipardUser, 'root' → 'root'.
     */
    public function resolveOwner(string $token): string
    {
        return $token === 'user' ? $this->shipardUser : $token;
    }

    /**
     * @return list<string> existing data-source root directories (contain config/main.json)
     */
    public function discoverDataSources(): array
    {
        if (!is_dir($this->dataSourcesDir)) {
            return [];
        }
        $dirs = glob($this->dataSourcesDir . '/*', GLOB_ONLYDIR) ?: [];
        $found = [];
        foreach ($dirs as $d) {
            if (is_file($d . '/config/main.json')) {
                $found[] = $d;
            }
        }
        sort($found);
        return $found;
    }
}
