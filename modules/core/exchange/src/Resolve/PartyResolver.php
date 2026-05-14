<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Resolves a canonical Party object to a `base_persons_persons.id`.
 *
 * Probes in order (first hit wins):
 *
 *   1. `company_id` exact match           → matchedBy = "companyId"
 *   2. `vat_id` exact match               → matchedBy = "vatId"
 *   3. `tax_id` exact match               → matchedBy = "taxId"
 *   4. `full_name` LIKE %name%            → matchedBy = "name" (single) /
 *                                            ambiguous (multiple)
 *   5. No match → `canCreate` with payload ready for PersonDocument::saveDocument.
 *
 * Self-party flow: when the caller asks for the side we are ourselves
 * ({@see resolveSelfParty}), we delegate to OwnCompanyResolver and return
 * `matched` with `matchedBy = "selfParty"`. If the canonical payload also
 * carries identifiers for the self-party side, we cross-check and the
 * caller emits a warning when they disagree.
 *
 * Country is documented as a filter input on identifier probes, but since
 * IČO / VAT ID / DIČ are global enough in the CZ context (and country is
 * stored on `base_persons_addresses`, not `persons`), Phase 1 ignores it.
 * Country filter on name fuzzy search is a follow-up.
 */
class PartyResolver
{
    private const ACTIVE_STATES = [10, 40, 80];
    private const AMBIGUOUS_LIMIT = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly OwnCompanyResolver $ownCompanyResolver,
    ) {}

    /**
     * @param array<string, mixed> $party Canonical Party object (may be empty).
     */
    public function resolve(array $party): ResolveResult
    {
        $companyId = $this->normalize($party['companyId'] ?? null);
        $vatId = $this->normalize($party['vatId'] ?? null);
        $taxId = $this->normalize($party['taxId'] ?? null);
        $name = $this->normalize($party['name'] ?? null);

        if ($companyId !== null) {
            $row = $this->fetchByColumn('company_id', $companyId);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'companyId');
            }
        }
        if ($vatId !== null) {
            $row = $this->fetchByColumn('vat_id', $vatId);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'vatId');
            }
        }
        if ($taxId !== null) {
            $row = $this->fetchByColumn('tax_id', $taxId);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'taxId');
            }
        }

        if ($name !== null) {
            $candidates = $this->fetchByName($name);
            if (count($candidates) === 1) {
                return ResolveResult::matched((int) $candidates[0]['id'], 'name');
            }
            if (count($candidates) > 1) {
                return ResolveResult::ambiguous($candidates);
            }
        }

        // Nothing matched. We can offer a create if we have a name.
        if ($name === null) {
            return ResolveResult::notFound();
        }

        return ResolveResult::canCreate($this->buildCreatePayload($party, $name));
    }

    /**
     * Resolve the side of the document that *is* us. Returns matched on the
     * own person row if configured, notFound otherwise.
     *
     * If `$party` carries identifiers, the caller is responsible for any
     * warning about identity mismatch — this method only does the lookup.
     */
    public function resolveSelfParty(): ResolveResult
    {
        $ownId = $this->ownCompanyResolver->getOwnPersonId();
        if ($ownId === null) {
            return ResolveResult::notFound();
        }
        return ResolveResult::matched($ownId, 'selfParty');
    }

    /**
     * Detect identity mismatch between canonical self-party payload and the
     * configured own company. Useful for warning emission by the caller.
     *
     * @param array<string, mixed> $party
     * @return list<string>  Names of identifiers that disagree (companyId, vatId, taxId).
     */
    public function diffSelfPartyIdentity(array $party): array
    {
        $own = $this->ownCompanyResolver->getOwnPersonData();
        if ($own === null) {
            return [];
        }
        $disagree = [];
        foreach ([
            'companyId' => 'company_id',
            'vatId'     => 'vat_id',
            'taxId'     => 'tax_id',
        ] as $canonicalKey => $dbCol) {
            $payloadValue = $this->normalize($party[$canonicalKey] ?? null);
            $ownValue = $this->normalize($own[$dbCol] ?? null);
            if ($payloadValue !== null && $ownValue !== null && $payloadValue !== $ownValue) {
                $disagree[] = $canonicalKey;
            }
        }
        return $disagree;
    }

    private function fetchByColumn(string $column, string $value): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [base_persons_persons]
             WHERE %n = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $column, $value,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @return array<int, array{id: int, name: string, companyId: ?string}>
     */
    private function fetchByName(string $name): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [full_name], [company_id] FROM [base_persons_persons]
             WHERE [full_name] LIKE %s AND [docState] IN (%i, %i, %i)
             LIMIT %i',
            '%' . $name . '%',
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            self::AMBIGUOUS_LIMIT,
        );
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $out[] = [
                'id'        => (int) $arr['id'],
                'name'      => (string) ($arr['full_name'] ?? ''),
                'companyId' => $arr['company_id'] !== null ? (string) $arr['company_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $party
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $party, string $name): array
    {
        return [
            'person_type'        => PersonType::Company->value,
            'full_name'          => $name,
            'company_id'         => $this->normalize($party['companyId'] ?? null) ?? '',
            'tax_id'             => $this->normalize($party['taxId'] ?? null) ?? '',
            'vat_id'             => $this->normalize($party['vatId'] ?? null) ?? '',
            'court_registration' => $this->normalize($party['courtRegistration'] ?? null) ?? '',
            'email'              => $this->normalize($party['contact']['email'] ?? null) ?? '',
            'phone'              => $this->normalize($party['contact']['phone'] ?? null) ?? '',
            'web'                => $this->normalize($party['contact']['web'] ?? null) ?? '',
            'payment_term_days'  => is_int($party['paymentTermDays'] ?? null)
                                    ? $party['paymentTermDays']
                                    : null,
        ];
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
