<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Item;

use Shipard\Module\Core\Exchange\Resolve\ResolveResult;

/**
 * Aggregate output of {@see ItemFlowResolver::resolve()}. Mirrors the
 * `_resolve` shape from docs/exchange-format-items.md §8:
 *
 *   - `header`         — header ResolveResult (Matched / CanCreate / Ambiguous).
 *   - `kind`           — kind ResolveResult (KindResolver output).
 *   - `unit`           — unit ResolveResult (UnitResolver output).
 *   - `supplierCodes`  — per-index sub-record results (from SupplierCodesResolver).
 *   - `issues`         — warnings / errors collected during resolve (kind_inferred_from_itemType,
 *                        unit_unknown, code_conflict).
 *
 * NO `closingExisting` — items spec has no closing semantics for
 * supplierCodes (spec §6.4); the table has no `valid_to` / `docState`.
 */
final class ItemResolveResult
{
    /**
     * @param array<int, array<string, mixed>> $supplierCodes
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function __construct(
        public readonly ResolveResult $header,
        public readonly ResolveResult $kind,
        public readonly ResolveResult $unit,
        public readonly array $supplierCodes,
        public readonly array $issues,
    ) {}

    /**
     * Serialize to the `_resolve.*` shape. The caller assembles `summary`
     * and merges any external (schema / validator) issues into `issues`
     * before returning to the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $header = $this->header->toArray();
        // Rename `matchedId` → `itemId` for header (spec §8 _resolve shape).
        if (isset($header['matchedId'])) {
            $header['itemId'] = $header['matchedId'];
            unset($header['matchedId']);
        }

        $kind = $this->kind->toArray();
        if (isset($kind['matchedId'])) {
            $kind['kindId'] = $kind['matchedId'];
            unset($kind['matchedId']);
        }

        $unit = $this->unit->toArray();
        if (isset($unit['matchedId'])) {
            $unit['unitId'] = $unit['matchedId'];
            unset($unit['matchedId']);
        }

        return [
            'header'        => $header,
            'kind'          => $kind,
            'unit'          => $unit,
            'supplierCodes' => $this->supplierCodes,
            'issues'        => $this->issues,
        ];
    }
}
