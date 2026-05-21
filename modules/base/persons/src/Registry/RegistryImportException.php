<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

/**
 * The registry returned a valid canonical payload but
 * {@see \Shipard\Module\Core\Exchange\Person\PersonApplier::apply()}
 * refused to persist it for a non-existence reason (validation failure,
 * person_id collision, internal error). Distinct from
 * {@see RegistryNotFoundException} (registry doesn't have the entity)
 * and {@see RegistryUnavailableException} (network can't reach the
 * registry) — this signals a problem on the apply side after the fetch
 * succeeded.
 *
 * Carries the applier error code + the enriched canonical so callers
 * (AI Analyzer post-processing, dead-letter queue) can log details
 * without re-running the import.
 */
final class RegistryImportException extends RegistryException
{
    /**
     * @param array<string, mixed> $canonical  Enriched canonical from the
     *   applier's `ApplyResult.canonical` — typically carries `_resolve`
     *   with issue details.
     */
    public function __construct(
        string $message,
        public readonly ?string $applierErrorCode = null,
        public readonly array $canonical = [],
    ) {
        parent::__construct($message);
    }
}
