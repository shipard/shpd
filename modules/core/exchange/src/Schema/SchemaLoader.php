<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Schema;

/**
 * Loads and caches Exchange JSON Schema definitions.
 *
 * Schema files live in modules/core/exchange/schemas/ and are named
 * `{formatId}.v{version}.json` (e.g. `shpd.docs.document.v1.json`). The
 * accompanying `.jsonc` source is human-edited; the `.json` is the
 * compiled form (no comments / no trailing commas) loaded here.
 */
final class SchemaLoader
{
    /** @var array<string, \stdClass> */
    private array $cache = [];

    public function __construct(
        private readonly string $schemasDir,
    ) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/schemas');
    }

    public function load(string $formatId, string $version): \stdClass
    {
        $key = "{$formatId}.v{$version}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $path = "{$this->schemasDir}/{$formatId}.v{$version}.json";
        if (!is_file($path)) {
            throw new \RuntimeException("Exchange schema not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read schema file: {$path}");
        }

        $schema = json_decode($contents, false);
        if (!$schema instanceof \stdClass) {
            $err = json_last_error_msg();
            throw new \RuntimeException("Invalid JSON in {$path}: {$err}");
        }

        return $this->cache[$key] = $schema;
    }
}
