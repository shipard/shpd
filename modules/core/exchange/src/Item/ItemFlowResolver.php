<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Item;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\KindResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\SupplierCodesResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;

/**
 * Orchestrates the four resolver calls for an item canonical:
 *
 *   - {@see ItemResolver}             — header lookup by ourCode / ean / sku / name.
 *   - {@see KindResolver}             — kind (item_kind FK) by code / name / itemTypeFallback.
 *   - {@see UnitResolver}             — unit (core_units FK).
 *   - {@see SupplierCodesResolver}    — per-index supplier mapping resolution.
 *
 * In addition to wiring the resolvers, this class:
 *
 *   - Emits the `kind_inferred_from_itemType` warning when KindResolver
 *     returns `matchedBy = "itemTypeFallback"`. Spec §5.3.
 *
 *   - Emits the `unit_unknown` warning when UnitResolver returns notFound.
 *     ItemApplier later falls back to `pcs`. Spec §7.3.
 *
 *   - Runs the explicit `code_conflict` probe: when the canonical carries
 *     a non-empty `code`, look up any DB row with the same code and a
 *     **different** id from the matched header. Emits `code_conflict`
 *     error issue. ItemApplier maps it to 409 code_conflict. Spec §7.5.
 *
 * The header lookup is dispatched on existing {@see ItemResolver} with
 * `supplierPersonId = null` — Item apply flow does not narrow header
 * lookup by per-partner supplierCode (the canonical has a *list* of
 * suppliers, not a single one). Spec §7.1.
 */
class ItemFlowResolver
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
        private readonly ItemResolver $itemResolver,
        private readonly KindResolver $kindResolver,
        private readonly UnitResolver $unitResolver,
        private readonly SupplierCodesResolver $supplierCodesResolver,
    ) {}

    /**
     * @param array<string, mixed> $canonical
     */
    public function resolve(array $canonical): ItemResolveResult
    {
        $issues = [];

        // 1. Header — adapt canonical onto ItemResolver shape (it expects
        // flat keys: ourCode / name / ean / sku / supplierCode). Item apply
        // flow has no single supplier — supplierPersonId = null.
        $header = $this->itemResolver->resolve(
            $this->toHeaderProbe($canonical),
            supplierPersonId: null,
        );

        // 2. Kind
        $kindInput = is_array($canonical['kind'] ?? null) ? $canonical['kind'] : [];
        $kind = $this->kindResolver->resolve($kindInput);
        if ($kind->status === ResolveStatus::Matched && $kind->matchedBy === 'itemTypeFallback') {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'kind',
                'code'     => 'kind_inferred_from_itemType',
                'message'  => 'Druh nedohledán podle code/name; použit systémový druh podle itemType.',
            ];
        }

        // 3. Unit
        $unitToken = is_string($canonical['unit'] ?? null) ? $canonical['unit'] : null;
        $unit = $this->unitResolver->resolve($unitToken);
        if ($unit->status === ResolveStatus::NotFound) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'unit',
                'code'     => 'unit_unknown',
                'message'  => 'Jednotku se nepodařilo přiřadit; bude použita výchozí (pcs).',
            ];
        }

        // 4. SupplierCodes — pass matched item id when available (update
        // flow); for header canCreate the resolver returns canCreate per
        // element automatically.
        $supplierCodesInput = is_array($canonical['supplierCodes'] ?? null) ? $canonical['supplierCodes'] : [];
        $itemId = ($header->status === ResolveStatus::Matched) ? $header->matchedId : null;
        $supplierCodes = $this->supplierCodesResolver->resolve($supplierCodesInput, $itemId);

        // 4a. Lift per-element `issue` markers into top-level issues so the
        // client sees them in the standard place. Per-element data stays in
        // `_resolve.supplierCodes[i]` for index-aware rendering.
        foreach ($supplierCodes as $entry) {
            $code = $entry['issue'] ?? null;
            if (!is_string($code)) continue;
            $i = (int) ($entry['index'] ?? 0);
            $issues[] = [
                'severity' => 'warning',
                'path'     => "supplierCodes[{$i}]",
                'code'     => $code,
                'message'  => $this->messageForSupplierIssue($code, $entry),
            ];
        }

        // 5. code_conflict probe — when payload carries a non-empty `code`
        // and another item in DB owns it, emit error issue.
        $codeIssue = $this->probeCodeConflict($canonical, $header);
        if ($codeIssue !== null) {
            $issues[] = $codeIssue;
        }

        return new ItemResolveResult($header, $kind, $unit, $supplierCodes, $issues);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function messageForSupplierIssue(string $code, array $entry): string
    {
        $label = $entry['supplierName']
            ?? ($entry['supplier']['matchedBy'] ?? null)
            ?? '?';
        return match ($code) {
            'supplier_unknown' => "Dodavatel u řádku supplierCodes nebyl nalezen — sub-záznam přeskočen ({$label}).",
            default            => "Sub-záznam supplierCodes vrátil issue `{$code}`.",
        };
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function toHeaderProbe(array $canonical): array
    {
        return [
            'ourCode' => $canonical['code'] ?? null,
            'name'    => $canonical['name'] ?? null,
            'ean'     => $canonical['ean'] ?? null,
            'sku'     => $canonical['sku'] ?? null,
        ];
    }

    /**
     * @return ?array{severity: string, path: string, code: string, message: string}
     */
    private function probeCodeConflict(array $canonical, ResolveResult $header): ?array
    {
        $code = $canonical['code'] ?? null;
        if (!is_string($code)) return null;
        $code = trim($code);
        if ($code === '') return null;

        // If header already matched by ourCode the canonical is consistent
        // with DB (the matched row IS the row with this code) — nothing to
        // probe.
        if ($header->status === ResolveStatus::Matched && $header->matchedBy === 'ourCode') {
            return null;
        }

        // Probe: any other active item with this code?
        $excludeId = $header->status === ResolveStatus::Matched ? $header->matchedId : null;
        $sql = 'SELECT [id] FROM [economy_items]
                WHERE [code] = %s AND [docState] IN (%i, %i, %i)';
        $params = [$code, self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2]];
        if ($excludeId !== null) {
            $sql .= ' AND [id] != %i';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $row = $this->db->fetch($sql, ...$params);
        if ($row === null) return null;

        $conflictId = (int) $row['id'];
        return [
            'severity' => 'error',
            'path'     => 'code',
            'code'     => 'code_conflict',
            'message'  => "Kód „{$code}\" je již použit u jiné položky (id={$conflictId}).",
        ];
    }
}
