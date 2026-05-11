<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Diagnostic checks against PermissionSpec. Read-only; FixPermissions applies the fixes.
 *
 * @phpstan-import-type SpecEntry from PermissionSpec
 * @phpstan-type Issue array{severity: 'ok'|'warn'|'error', path: string, message: string, fixable: bool}
 */
final class HealthChecker
{
    public function __construct(
        private readonly PermissionSpec $spec,
    ) {}

    /**
     * @return list<Issue>
     */
    public function checkAll(): array
    {
        $issues = [];

        foreach ($this->spec->getGlobalEntries() as $entry) {
            foreach ($this->checkEntry($entry) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($this->spec->discoverDataSources() as $dsDir) {
            foreach ($this->spec->getDataSourceEntries($dsDir) as $entry) {
                foreach ($this->checkEntry($entry) as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @param SpecEntry $entry
     * @return list<Issue>
     */
    private function checkEntry(array $entry): array
    {
        $path = $entry['path'];
        $optional = $entry['optional'] ?? false;

        if (!file_exists($path)) {
            if ($optional) {
                return [];
            }
            return [[
                'severity' => 'error',
                'path'     => $path,
                'message'  => 'does not exist',
                'fixable'  => false,
            ]];
        }

        $actualType = is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'other');
        if ($actualType !== $entry['type']) {
            return [[
                'severity' => 'error',
                'path'     => $path,
                'message'  => "expected {$entry['type']}, found {$actualType}",
                'fixable'  => false,
            ]];
        }

        $issues = [];
        $stat = @stat($path);
        if ($stat === false) {
            return [[
                'severity' => 'error',
                'path'     => $path,
                'message'  => 'stat() failed',
                'fixable'  => false,
            ]];
        }

        $expectedOwner = $this->spec->resolveOwner($entry['owner']);
        $ownerInfo = posix_getpwuid($stat['uid']);
        $actualOwner = $ownerInfo['name'] ?? (string) $stat['uid'];
        if ($actualOwner !== $expectedOwner) {
            $issues[] = [
                'severity' => 'error',
                'path'     => $path,
                'message'  => "owner {$actualOwner}, expected {$expectedOwner}",
                'fixable'  => true,
            ];
        }

        $expectedGroup = $this->spec->resolveOwner($entry['group']);
        $groupInfo = posix_getgrgid($stat['gid']);
        $actualGroup = $groupInfo['name'] ?? (string) $stat['gid'];
        if ($actualGroup !== $expectedGroup) {
            $issues[] = [
                'severity' => 'error',
                'path'     => $path,
                'message'  => "group {$actualGroup}, expected {$expectedGroup}",
                'fixable'  => true,
            ];
        }

        $actualMode = $stat['mode'] & 0777;
        if ($actualMode !== $entry['mode']) {
            $issues[] = [
                'severity' => 'error',
                'path'     => $path,
                'message'  => sprintf('mode %04o, expected %04o', $actualMode, $entry['mode']),
                'fixable'  => true,
            ];
        }

        return $issues;
    }

    /**
     * Parses /etc/php/*\/fpm/pool.d/shipard.conf and returns the configured `user` value.
     * Returns null when no shipard pool is configured.
     */
    public function detectPoolUser(string $pattern = '/etc/php/*/fpm/pool.d/shipard.conf'): ?string
    {
        foreach (glob($pattern) ?: [] as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/^\s*user\s*=\s*(\S+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
