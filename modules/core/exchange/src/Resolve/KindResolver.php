<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves a canonical `kind` sub-object to an `economy_items_kinds.id`.
 *
 * Per docs/exchange-format-items.md §5.1, the strategy priority is:
 *
 *   1. `kind.code` exact match in `economy_items_kinds.system_code` (unique)
 *      → matched, matchedBy = "system_code".
 *
 *   2. `kind.name` exact match in `economy_items_kinds.name`
 *      → matched (1 hit) / ambiguous (n hits), matchedBy = "name".
 *
 *   3. `kind.name` filled + no match → canCreate with a payload ready
 *      for `ItemKindDocument::saveDocument`. The new kind gets `name`
 *      from the payload and `item_type` from `kind.itemType ?? 3`
 *      (Other) as a safe default. **Note:** canCreate fires before
 *      itemTypeFallback because explicit `kind.name` is a stronger
 *      signal — the caller named a specific kind; we'd rather create
 *      it than silently fall through to a generic seeded kind.
 *
 *   4. Fallback per `kind.itemType` via {@see FALLBACK_KIND_BY_ITEM_TYPE}
 *      → matched, matchedBy = "itemTypeFallback". ItemFlowResolver wraps
 *      this result with a `kind_inferred_from_itemType` warning so the
 *      user sees that the kind was guessed. Only reachable when
 *      `kind.name` is empty (otherwise step 3 wins).
 *
 *   5. No hints at all → notFound. ItemApplier rejects with a
 *      `kind_unresolved` validation issue.
 *
 * Kind-table probes filter `docState IN (10, 40, 80)` — archived (70)
 * and deleted (90) kinds are not matched.
 *
 * The fallback table is a PHP constant so it can't drift from the seed
 * loaded by {@see \Shipard\Module\Economy\Items\ItemKindsProvisioner}.
 * If the provisioner ever renames a `system_code`, the lookup explodes
 * loudly instead of silently picking the wrong kind.
 */
class KindResolver
{
    private const ACTIVE_STATES = [10, 40, 80];
    private const AMBIGUOUS_LIMIT = 5;

    /** Mapping `kind.itemType` → system_code seeded by ItemKindsProvisioner. */
    private const FALLBACK_KIND_BY_ITEM_TYPE = [
        0 => 'service',
        1 => 'stock',
        2 => 'accounting',
        3 => 'other',
    ];

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $kind  `kind` sub-object from canonical.
     */
    public function resolve(array $kind): ResolveResult
    {
        $code = $this->normalize($kind['code'] ?? null);
        $name = $this->normalize($kind['name'] ?? null);
        $itemType = $this->intOrNull($kind['itemType'] ?? null);

        // Strategy 1 — system_code exact match.
        if ($code !== null) {
            $row = $this->fetchBySystemCode($code);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'system_code');
            }
        }

        // Strategy 2 — name exact match (may be ambiguous).
        if ($name !== null) {
            $rows = $this->fetchByName($name);
            if (count($rows) === 1) {
                return ResolveResult::matched((int) $rows[0]['id'], 'name');
            }
            if (count($rows) > 1) {
                return ResolveResult::ambiguous($rows);
            }
            // Strategy 3 — name filled + miss → canCreate. Wins over
            // itemTypeFallback so explicit `kind.name` is respected.
            return ResolveResult::canCreate($this->buildCreatePayload($name, $itemType));
        }

        // Strategy 4 — itemType fallback to seeded system kind. Only
        // reachable when `kind.name` is empty.
        if ($itemType !== null && isset(self::FALLBACK_KIND_BY_ITEM_TYPE[$itemType])) {
            $fallbackCode = self::FALLBACK_KIND_BY_ITEM_TYPE[$itemType];
            $row = $this->fetchBySystemCode($fallbackCode);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'itemTypeFallback');
            }
        }

        // No usable hint, no fallback hit.
        return ResolveResult::notFound();
    }

    private function fetchBySystemCode(string $systemCode): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_items_kinds]
             WHERE [system_code] = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $systemCode,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @return array<int, array{id: int, name: string, item_type: int, system_code: ?string}>
     */
    private function fetchByName(string $name): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [item_type], [system_code] FROM [economy_items_kinds]
             WHERE [name] = %s AND [docState] IN (%i, %i, %i)
             LIMIT %i',
            $name,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            self::AMBIGUOUS_LIMIT,
        );
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $out[] = [
                'id'          => (int) $arr['id'],
                'name'        => (string) ($arr['name'] ?? ''),
                'item_type'   => (int) ($arr['item_type'] ?? 3),
                'system_code' => $arr['system_code'] !== null ? (string) $arr['system_code'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCreatePayload(string $name, ?int $itemType): array
    {
        return [
            'name'         => $name,
            'item_type'    => $itemType ?? 3,
            'docState'     => 40,
            'docStateMain' => 2,
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

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && ctype_digit($value)) return (int) $value;
        return null;
    }
}
