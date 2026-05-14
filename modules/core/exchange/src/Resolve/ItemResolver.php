<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves a canonical row.item to an `economy_items.id`.
 *
 * Probes in order (first hit wins):
 *
 *   1. `ourCode` exact match in `economy_items.code`         → matchedBy = "ourCode"
 *   2. `(supplier.personId, supplierCode)` per-partner       → matchedBy = "supplierCode"
 *      mapping via `economy_items_supplier_codes`
 *   3. `ean` exact match in `economy_items.ean`              → matchedBy = "ean"
 *   4. `sku` exact match in `economy_items.sku`              → matchedBy = "sku"
 *   5. `name` LIKE %name%                                    → matchedBy = "name" (1)
 *                                                              / ambiguous (n)
 *   6. No match → `canCreate` payload (caller still needs to supply
 *      `item_kind` and `unit` before INSERT — see ItemDocument::validate).
 */
class ItemResolver
{
    private const ACTIVE_STATES = [10, 40, 80];
    private const AMBIGUOUS_LIMIT = 5;

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $item              Canonical row.item block.
     * @param int|null             $supplierPersonId  Resolved supplier id, for
     *                                                per-partner mapping lookup.
     */
    public function resolve(array $item, ?int $supplierPersonId): ResolveResult
    {
        $ourCode = $this->normalize($item['ourCode'] ?? null);
        $supplierCode = $this->normalize($item['supplierCode'] ?? null);
        $ean = $this->normalize($item['ean'] ?? null);
        $sku = $this->normalize($item['sku'] ?? null);
        $name = $this->normalize($item['name'] ?? null);

        if ($ourCode !== null) {
            $row = $this->fetchByColumn('code', $ourCode);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'ourCode');
            }
        }

        if ($supplierPersonId !== null && $supplierCode !== null) {
            $row = $this->db->fetch(
                'SELECT [item] FROM [economy_items_supplier_codes]
                 WHERE [person] = %i AND [supplier_code] = %s
                 LIMIT 1',
                $supplierPersonId, $supplierCode,
            );
            if ($row !== null) {
                return ResolveResult::matched((int) $row['item'], 'supplierCode');
            }
        }

        if ($ean !== null) {
            $row = $this->fetchByColumn('ean', $ean);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'ean');
            }
        }

        if ($sku !== null) {
            $row = $this->fetchByColumn('sku', $sku);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'sku');
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

        if ($name === null) {
            return ResolveResult::notFound();
        }

        return ResolveResult::canCreate($this->buildCreatePayload($item, $name));
    }

    private function fetchByColumn(string $column, string $value): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_items]
             WHERE %n = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $column, $value,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @return array<int, array{id: int, name: string, code: ?string}>
     */
    private function fetchByName(string $name): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [code] FROM [economy_items]
             WHERE [name] LIKE %s AND [docState] IN (%i, %i, %i)
             LIMIT %i',
            '%' . $name . '%',
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            self::AMBIGUOUS_LIMIT,
        );
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $out[] = [
                'id'   => (int) $arr['id'],
                'name' => (string) ($arr['name'] ?? ''),
                'code' => $arr['code'] !== null ? (string) $arr['code'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $item, string $name): array
    {
        return [
            'name'        => $name,
            'description' => $this->normalize($item['description'] ?? null) ?? '',
            'code'        => $this->normalize($item['ourCode'] ?? null) ?? '',
            'sku'         => $this->normalize($item['sku'] ?? null),
            'ean'         => $this->normalize($item['ean'] ?? null),
            // item_kind + unit must be supplied by Applier before save —
            // ItemDocument::validate rejects rows without them. The Exchange
            // applier picks defaults: item_kind = "service" kind (well-known),
            // unit = resolved row.unit if present.
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
