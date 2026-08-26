<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Výsledek `DatasetDumper::dump()` — počty per sekce, varování exporterů
 * a zapisovače, výsledný manifest.
 */
final class DumpResult
{
    /**
     * @param array<string, int> $counts
     * @param list<string>       $warnings
     */
    public function __construct(
        public readonly DatasetManifest $manifest,
        public readonly array $counts,
        public readonly array $warnings,
    ) {}
}
