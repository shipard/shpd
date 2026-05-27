<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Shipard\Module\Base\Persons\PersonType;

/**
 * Resolves the `supplierCodes[]` sub-collection of an item canonical to
 * `economy_items_supplier_codes` mappings.
 *
 * Per docs/exchange-format-items.md §6.1, each element carries an inline
 * Party fragment (`supplier`) and a `supplierCode` string. This resolver:
 *
 *   1. Delegates supplier lookup to {@see PartyResolver} with
 *      `personType = company` filter (supplier-side parties are always
 *      companies in Phase 1).
 *
 *   2. When the supplier is matched and `$itemId` is known (update flow),
 *      looks up the existing `(person, supplier_code)` row in
 *      `economy_items_supplier_codes`. The unique index
 *      `unq_person_supplier_code` guarantees at most one match. Mapping
 *      hit → `status: matched` with `mappingId`; miss → `status: canCreate`.
 *
 *   3. When the supplier is matched but `$itemId` is null (create flow —
 *      header is canCreate, item row does not exist yet), the mapping
 *      cannot exist yet by definition → `status: canCreate` immediately.
 *      ItemApplier patches `item` into the INSERT IGNORE statement after
 *      the header row gets its id.
 *
 *   4. When the supplier is `canCreate` / `ambiguous` / `notFound`, the
 *      sub-record cannot be wired without an existing person row. Default
 *      `status: skipped` with `issue: supplier_unknown`. The user can
 *      override per-index via `_resolve.supplierCodes[i].supplier.userAction`
 *      = "create" — ItemApplier then delegates the side-create to
 *      PersonApplier (via {@see \Shipard\Module\Core\Exchange\Common\PartyToPersonCanonical}).
 *
 * The supplier-codes table itself has no `docState`/`valid_to` columns
 * (per `tables/economy_items_supplier_codes.jsonc`); no state filter is
 * applied on the mapping lookup.
 */
class SupplierCodesResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PartyResolver $partyResolver,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $supplierCodes  supplierCodes[] from canonical.
     * @param int|null $itemId  Matched item id (null for header-canCreate flow).
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $supplierCodes, ?int $itemId): array
    {
        $out = [];
        foreach ($supplierCodes as $index => $element) {
            if (!is_array($element)) {
                continue;
            }
            $out[] = $this->resolveOne((int) $index, $element, $itemId);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function resolveOne(int $index, array $element, ?int $itemId): array
    {
        $supplier = is_array($element['supplier'] ?? null) ? $element['supplier'] : [];
        $supplierCode = $this->normalize($element['supplierCode'] ?? null);
        $supplierName = $this->normalize($element['supplierName'] ?? null);

        $supplierResult = $this->partyResolver->resolve($supplier, PersonType::Company);

        $base = [
            'index'    => $index,
            'supplier' => $supplierResult->toArray(),
        ];

        // Supplier didn't resolve to an existing person — sub-record cannot
        // be wired without an explicit userAction override. ItemApplier
        // decides skip vs. create.
        if ($supplierResult->status !== ResolveStatus::Matched) {
            return $base + [
                'status'      => 'skipped',
                'userAction'  => null,
                'issue'       => 'supplier_unknown',
                'supplierCode' => $supplierCode,
                'supplierName' => $supplierName,
            ];
        }

        // Supplier matched. For the update flow ($itemId != null) probe
        // existing mapping; for create flow ($itemId == null) the mapping
        // cannot exist yet.
        $personId = $supplierResult->matchedId;
        if ($personId === null || $supplierCode === null) {
            // PartyResolver claimed matched but didn't provide an id, or
            // supplierCode is empty — schema should prevent the latter; be
            // defensive and skip.
            return $base + [
                'status'       => 'skipped',
                'userAction'   => null,
                'issue'        => 'supplier_unknown',
                'supplierCode' => $supplierCode,
                'supplierName' => $supplierName,
            ];
        }

        if ($itemId !== null) {
            $row = $this->fetchMapping($personId, $supplierCode);
            if ($row !== null) {
                return $base + [
                    'status'       => 'matched',
                    'mappingId'    => (int) $row['id'],
                    'supplierCode' => $supplierCode,
                    'supplierName' => $supplierName,
                ];
            }
        }

        return $base + [
            'status'       => 'canCreate',
            'supplierCode' => $supplierCode,
            'supplierName' => $supplierName,
        ];
    }

    private function fetchMapping(int $personId, string $supplierCode): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_items_supplier_codes]
             WHERE [person] = %i AND [supplier_code] = %s
             LIMIT 1',
            $personId, $supplierCode,
        );
        return $row !== null ? $row->toArray() : null;
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
