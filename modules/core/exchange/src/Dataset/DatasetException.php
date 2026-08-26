<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Chyby při čtení / zápisu datové sady (`shpd.dataset.v1`).
 *
 * Statické factory metody drží jednotné znění hlášek; CLI příkazy je
 * vypisují uživateli tak, jak jsou.
 */
final class DatasetException extends \RuntimeException
{
    public static function invalidManifest(string $why): self
    {
        return new self("Invalid dataset manifest: {$why}");
    }

    public static function notImplemented(string $what): self
    {
        return new self("Not implemented: {$what}");
    }

    public static function notFound(string $what): self
    {
        return new self("Dataset: {$what} not found");
    }

    public static function invalidArchive(string $why): self
    {
        return new self("Invalid dataset archive: {$why}");
    }

    public static function invalidPath(string $path): self
    {
        return new self("Dataset: invalid relative path '{$path}'");
    }

    public static function invalidFile(string $relPath, string $why): self
    {
        return new self("Dataset file '{$relPath}': {$why}");
    }

    public static function targetNotEmpty(string $dir): self
    {
        return new self("Dataset target directory '{$dir}' is not empty (use --force to overwrite)");
    }
}
