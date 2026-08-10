<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Permission contract for /opt/shipard and /etc/shipard.
 *
 * Entries with `recurse: true` (only valid on dirs) declare that the
 * directory's contents must also belong to the shipard user. HealthChecker
 * scans them and reports ownership mismatches; FixPermissions chowns them.
 * Modes inside recursive dirs are left untouched (file modes vary by content
 * type — JSON vs upload vs cache vs log rotation — and aren't part of the
 * contract). Exception: `contentsMaxMode` on a recursive dir caps the mode
 * of every file inside (secrets/ — anything above 0600 leaks key material).
 *
 * @phpstan-type SpecEntry array{path: string, type: 'dir'|'file', owner: 'root'|'user', group: 'user', mode: int, optional?: bool, recurse?: bool, contentsMaxMode?: int}
 */
final class PermissionSpec
{
    public function __construct(
        private readonly string $shipardUser,
        private readonly string $dataSourcesDir = '/opt/shipard/data-sources',
        private readonly string $logDir = '/opt/shipard/log',
        private readonly string $configDir = '/etc/shipard',
        private readonly string $shipardRoot = '/opt/shipard',
        private readonly string $runDir = '/opt/shipard/run',
    ) {}

    /**
     * @return list<SpecEntry>
     */
    public function getGlobalEntries(): array
    {
        return [
            ['path' => $this->configDir,                  'type' => 'dir',  'owner' => 'root', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->configDir . '/server.json', 'type' => 'file', 'owner' => 'root', 'group' => 'user', 'mode' => 0640],
            // /opt/shipard is 0751 (not 0750) so that nginx (www-data) can traverse
            // into /opt/shipard/shpd/public for SPA assets. Contents (data-sources,
            // log) keep 0750 so data is not readable by others.
            ['path' => $this->shipardRoot,                'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0751],
            ['path' => $this->dataSourcesDir,             'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750],
            ['path' => $this->logDir,                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'recurse' => true],
            ['path' => $this->logDir . '/shipard.log',    'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0640, 'optional' => true],
            // Locky a heartbeaty cron dispatcheru; optional — servery před
            // rolloutem cronu adresář nemají a nemají kvůli tomu failovat.
            ['path' => $this->runDir,                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true, 'recurse' => true],
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
            ['path' => $dsDir . '/config/configuration',      'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true, 'recurse' => true],
            ['path' => $dsDir . '/secrets',                   'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0700, 'optional' => true, 'recurse' => true, 'contentsMaxMode' => 0600],
            ['path' => $dsDir . '/secrets/secrets.key',       'type' => 'file', 'owner' => 'user', 'group' => 'user', 'mode' => 0600, 'optional' => true],
            ['path' => $dsDir . '/att',                       'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true, 'recurse' => true],
            ['path' => $dsDir . '/cache',                     'type' => 'dir',  'owner' => 'user', 'group' => 'user', 'mode' => 0750, 'optional' => true, 'recurse' => true],
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
