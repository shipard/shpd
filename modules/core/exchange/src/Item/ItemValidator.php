<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Item;

/**
 * Semantic validator for a canonical item — runs **after** schema validation
 * and **before** resolve. Schema enforces structural shape; this validator
 * adds business-rule checks that don't fit JSON Schema cleanly:
 *
 *   - `name` and `unit` are required — schema enforces this too; we mirror
 *     the check so the issue carries our path/code shape rather than the
 *     raw JSON Schema `required` shape (defense in depth).
 *   - `salesPriceNoVat >= 0` when non-null (schema accepts `null` for
 *     unpriced items; `minimum: 0` in schema would falsely reject `null`).
 *   - `kind` must carry at least one of `code` / `name` / `itemType` —
 *     otherwise KindResolver has nothing to dispatch on.
 *   - `code` pattern: when filled, `/^\S{1,25}$/` — matches the DB column
 *     length and rules out accidental whitespace.
 *   - `applyOptions.targetDocState` must be one of the docState archive
 *     values; default `10` (Koncept) is applied at the applier layer.
 *
 * Returns the same `{severity, path, code, message}` shape as
 * {@see \Shipard\Module\Core\Exchange\Schema\SchemaValidator}. Issues
 * with `severity = error` block /apply; warnings only surface in
 * `_resolve.issues` and never block (unless caller sets
 * `applyOptions.rejectOnIssues = ["error", "warning"]`).
 */
final class ItemValidator
{
    private const TARGET_DOC_STATES = [10, 40, 70, 80, 90];

    private const CODE_PATTERN = '/^\S{1,25}$/';

    /** Soft sanity caps for free-form identifiers — DB widths. */
    private const SKU_MAX_LENGTH = 50;
    private const EAN_MAX_LENGTH = 20;

    /**
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $canonical): array
    {
        $issues = [];

        $this->checkRequiredHeaderFields($canonical, $issues);
        $this->checkKindHints($canonical, $issues);
        $this->checkPricing($canonical, $issues);
        $this->checkCodePattern($canonical, $issues);
        $this->checkSecondaryKeys($canonical, $issues);
        $this->checkApplyOptions($canonical, $issues);

        return $issues;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkRequiredHeaderFields(array $canonical, array &$issues): void
    {
        if (!$this->isNonEmptyString($canonical['name'] ?? null)) {
            $issues[] = $this->error(
                'name',
                'required',
                'Název položky je povinný.',
            );
        }
        if (!$this->isNonEmptyString($canonical['unit'] ?? null)) {
            $issues[] = $this->error(
                'unit',
                'required',
                'Jednotka je povinná.',
            );
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkKindHints(array $canonical, array &$issues): void
    {
        $kind = $canonical['kind'] ?? null;
        if (!is_array($kind)) {
            $issues[] = $this->error(
                'kind',
                'kind_required',
                'Druh položky musí být uveden (alespoň jeden hint: code / name / itemType).',
            );
            return;
        }

        $hasCode = $this->isNonEmptyString($kind['code'] ?? null);
        $hasName = $this->isNonEmptyString($kind['name'] ?? null);
        $itemType = $kind['itemType'] ?? null;
        $hasItemType = is_int($itemType) || (is_string($itemType) && ctype_digit($itemType));

        if (!$hasCode && !$hasName && !$hasItemType) {
            $issues[] = $this->error(
                'kind',
                'kind_required',
                'Druh položky musí mít alespoň jeden hint: code / name / itemType.',
            );
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPricing(array $canonical, array &$issues): void
    {
        if (!array_key_exists('salesPriceNoVat', $canonical)) {
            return;
        }
        $price = $canonical['salesPriceNoVat'];
        if ($price === null) {
            return;
        }
        if (!is_int($price) && !is_float($price)) {
            // Schema already rejects non-numeric; defensive fallback only.
            return;
        }
        if ((float) $price < 0.0) {
            $issues[] = $this->error(
                'salesPriceNoVat',
                'price_negative',
                'Prodejní cena bez DPH nesmí být záporná.',
            );
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCodePattern(array $canonical, array &$issues): void
    {
        $code = $canonical['code'] ?? null;
        if ($code === null || $code === '') {
            return;
        }
        if (!is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1) {
            $issues[] = $this->error(
                'code',
                'code_invalid',
                'Kód položky musí být 1–25 znaků bez bílých znaků.',
            );
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkSecondaryKeys(array $canonical, array &$issues): void
    {
        $sku = $canonical['sku'] ?? null;
        if (is_string($sku) && mb_strlen($sku) > self::SKU_MAX_LENGTH) {
            $issues[] = $this->error(
                'sku',
                'sku_too_long',
                'SKU smí mít maximálně ' . self::SKU_MAX_LENGTH . ' znaků.',
            );
        }
        $ean = $canonical['ean'] ?? null;
        if (is_string($ean) && mb_strlen($ean) > self::EAN_MAX_LENGTH) {
            $issues[] = $this->error(
                'ean',
                'ean_too_long',
                'EAN smí mít maximálně ' . self::EAN_MAX_LENGTH . ' znaků.',
            );
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkApplyOptions(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? null;
        if (!is_array($options)) {
            return;
        }
        $target = $options['targetDocState'] ?? null;
        if ($target === null) {
            return;
        }
        if (!is_int($target) || !in_array($target, self::TARGET_DOC_STATES, true)) {
            $issues[] = $this->error(
                'applyOptions.targetDocState',
                'target_doc_state_invalid',
                'targetDocState musí být jedna z hodnot: 10, 40, 70, 80, 90.',
            );
        }
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function error(string $path, string $code, string $message): array
    {
        return ['severity' => 'error', 'path' => $path, 'code' => $code, 'message' => $message];
    }
}
