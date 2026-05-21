<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Registry;

use Shipard\Module\Core\Exchange\Person\PersonApplier;

/**
 * Convenience facade combining {@see PersonsRegistryClient::fetchPerson()}
 * with {@see PersonApplier::apply()} into one operation: "ensure a
 * person with this country + companyId exists in this data source".
 *
 * Designed for the AI Analyzer post-processing flow — AI extracts an
 * IČO from an incoming document, the importer makes sure we have a
 * `base_persons_persons` row to attach the document to. The PHP entry
 * point is intentionally compact; HTTP-level concerns live in the
 * registry client, schema/apply concerns in the applier.
 *
 * The wizard flow ("Přidat firmu z registru" UI) does NOT go through
 * this class. It hits the registry client directly + uses the
 * `/api/v1/_exchange/persons/person/apply` REST endpoint so the
 * user can preview / pick userActions before persisting. The importer
 * is the headless variant for when the caller already knows it wants
 * to create-or-match without human review.
 *
 * Merge semantics: {@see MergeStrategy::CreateOnly}. If the person
 * already exists in the DS (matched by companyId/vatId/taxId via
 * `PersonResolver`), the existing id is returned without touching the
 * row. User-edited data is never overwritten by this code path. A
 * future re-sync flow (cron or wizard "Aktualizovat z registru" button)
 * will use `MergeAdd` / `FullSync` strategies explicitly.
 *
 * `targetDocState: 40` (V pořádku) — registry data is authoritative,
 * so we skip the Koncept staging step the manual UI uses.
 */
final class RegistryPersonImporter
{
    public function __construct(
        private readonly PersonsRegistryClient $registry,
        private readonly PersonApplier $applier,
    ) {}

    /**
     * Ensure a person exists in this DS for the given country +
     * companyId. Returns the row's primary key — newly created on first
     * call, existing on subsequent calls.
     *
     * Idempotent for a given (country, companyId): repeated calls
     * return the same id without DB writes after the first.
     *
     * @return ImportResult  `personId` of the matched/created row, plus a
     *   `created` flag (true = newly inserted, false = matched existing).
     *
     * @throws RegistryUnavailableException     Network / 5xx / timeout fetching from registry.
     * @throws RegistryNotFoundException        Registry has no record for {country}/{companyId}.
     * @throws RegistryInvalidResponseException Registry returned malformed canonical.
     * @throws RegistryImportException          Apply failed (validation, person_id conflict, …).
     */
    public function ensureImported(string $country, string $companyId): ImportResult
    {
        $canonical = $this->registry->fetchPerson($country, $companyId);

        // Overwrite any applyOptions baked into the registry payload — we
        // own the merge policy on the import side. Registry should not
        // be dictating how this DS handles existing records.
        $canonical['applyOptions'] = [
            'mergeStrategy'  => 'createOnly',
            'targetDocState' => 40,
        ];

        $result = $this->applier->apply($canonical);

        if ($result->success) {
            $savedId = $result->savedId;
            if (!is_int($savedId) || $savedId <= 0) {
                // Shouldn't happen — applier guarantees savedId on success.
                throw new RegistryImportException(
                    "Apply succeeded for {$country}/{$companyId} but savedId is missing.",
                    applierErrorCode: null,
                    canonical: $result->canonical,
                );
            }
            return new ImportResult(personId: $savedId, created: true);
        }

        // createOnly + matched header → ApplyResult::error('person_exists', …)
        // with the matched id surfaced in `_resolve.header.matchedId`.
        // That's not a failure from our perspective — the row exists,
        // we just didn't create it now.
        if ($result->errorCode === 'person_exists') {
            $matchedId = $result->canonical['_resolve']['header']['matchedId'] ?? null;
            if (is_int($matchedId) && $matchedId > 0) {
                return new ImportResult(personId: $matchedId, created: false);
            }
            throw new RegistryImportException(
                "Registry reported person_exists for {$country}/{$companyId} "
                . 'but no matchedId in _resolve.header — applier bug or stale data.',
                applierErrorCode: $result->errorCode,
                canonical: $result->canonical,
            );
        }

        throw new RegistryImportException(
            sprintf(
                'Failed to import person %s/%s: [%s] %s',
                $country,
                $companyId,
                $result->errorCode ?? 'unknown',
                $result->errorMessage ?? 'no message',
            ),
            applierErrorCode: $result->errorCode,
            canonical: $result->canonical,
        );
    }
}
