<?php

declare(strict_types=1);

namespace Shipard\Core;

/**
 * Centrální verze aplikace. Semver bumpuje se ručně při milnících;
 * denní přesnost nese git hash HEAD doplněný za běhu.
 */
final class Version
{
    public const VERSION = '0.1.1';

    private static ?string $gitHash = null;
    private static bool $gitHashResolved = false;

    /** "0.1.1 (abc1234)"; bez dostupného gitu jen "0.1.1". */
    public static function full(): string
    {
        $hash = self::gitHash();
        return $hash === null ? self::VERSION : self::VERSION . ' (' . $hash . ')';
    }

    /**
     * Short hash HEAD repozitáře; null když git binárka nebo .git chybí.
     * Výsledek (včetně negativního) se cachuje po dobu procesu.
     */
    public static function gitHash(): ?string
    {
        if (self::$gitHashResolved) {
            return self::$gitHash;
        }
        self::$gitHashResolved = true;

        $root = dirname(__DIR__, 2);
        $lines = [];
        $exitCode = 1;
        @exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null', $lines, $exitCode);
        $hash = trim($lines[0] ?? '');
        if ($exitCode === 0 && preg_match('/^[0-9a-f]{7,40}$/', $hash) === 1) {
            self::$gitHash = $hash;
        }

        return self::$gitHash;
    }
}
