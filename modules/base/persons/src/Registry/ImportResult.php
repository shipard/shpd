<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * Outcome of {@see RegistryPersonImporter::ensureImported()}.
 *
 * `created = true` means the importer just inserted a fresh row;
 * `created = false` means the row matched an existing person and the
 * applier returned the existing id without touching the row. Callers
 * can use this to differentiate UI feedback (toast "imported X" vs
 * "X already in DB, opened existing").
 */
final readonly class ImportResult
{
    public function __construct(
        public int $personId,
        public bool $created,
    ) {
        if ($personId <= 0) {
            throw new \InvalidArgumentException(
                "ImportResult: personId must be positive, got {$personId}",
            );
        }
    }
}
