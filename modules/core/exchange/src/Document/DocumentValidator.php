<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Semantic validator for a canonical document — runs **after** schema
 * validation and **before** resolve. Two kinds of checks:
 *
 *   - Required fields per `docType` (polymorphism the JSON schema
 *     intentionally does not enforce).
 *   - Totals coherence — declared vs. computed amounts; mismatch produces
 *     a warning, not an error, since the canonical's totals are
 *     informative and DocDocument::beforeSave recomputes them anyway.
 *
 * Returns the same `{severity, path, code, message}` shape as
 * {@see \Shipard\Module\Core\Exchange\Schema\SchemaValidator}. Issues
 * with `severity = error` block /apply; warnings only surface in
 * `_resolve.issues` and never block.
 */
final class DocumentValidator
{
    /**
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $canonical): array
    {
        $issues = [];

        $this->checkCommonRequired($canonical, $issues);
        $this->checkPerDocType($canonical, $issues);
        $this->checkTotalsCoherence($canonical, $issues);
        $this->checkPartnerDocNumber($canonical, $issues);

        return $issues;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCommonRequired(array $canonical, array &$issues): void
    {
        $dates = $canonical['dates'] ?? [];
        if (empty($dates['issueDate'])) {
            $issues[] = $this->required('dates.issueDate', 'Datum vystavení je povinné.');
        }

        if (empty($canonical['rows']) || !is_array($canonical['rows']) || count($canonical['rows']) === 0) {
            $issues[] = $this->required('rows', 'Doklad musí mít alespoň jeden řádek.');
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPerDocType(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        switch ($docType) {
            case 'invoiceReceived':
                if (empty($canonical['supplier'])) {
                    $issues[] = $this->required('supplier', 'U přijaté faktury je dodavatel povinný.');
                }
                break;
            case 'invoiceIssued':
                if (empty($canonical['customer'])) {
                    $issues[] = $this->required('customer', 'U vydané faktury je odběratel povinný.');
                }
                break;
            // Other docTypes (creditNote, order, deliveryNote, cashDoc, …)
            // pick up the common checks above; type-specific validation
            // arrives with each module that introduces them.
        }
    }

    /**
     * Cross-checks declared `totals.totalAmount` against three independent
     * variants the canonical may express. A warning fires only when *none*
     * of them lands within tolerance — AI extractors typically don't fill
     * `row.computed.vatTotal`, so the legacy "sum of row.totalPrice vs
     * total with VAT" check produced a false alarm on most VAT invoices.
     *
     * Variants:
     *   1. Sum of `row.totalPrice`                  — base only.
     *   2. Sum of `row.totalPrice * (1 + vat.pct)`  — base scaled per-row.
     *   3. Sum of `vatRecap[].total`                — most authoritative
     *      when available (per-rate breakdown with `total` = base + tax).
     *
     * Rounding-aware: a variant also passes when the declared amount is a
     * whole number less than 1.00 away from it — the invoice total was
     * rounded to whole currency units (DocumentApplier then derives
     * `total_rounding_mode` independently from the same numbers).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkTotalsCoherence(array $canonical, array &$issues): void
    {
        $totals = $canonical['totals'] ?? null;
        $rows = $canonical['rows'] ?? null;
        if (!is_array($totals) || !is_array($rows)) {
            return;
        }

        $declared = $totals['totalAmount'] ?? null;
        if ($declared === null) {
            return;
        }

        $declaredF = round((float) $declared, 2);

        // (1) base, (2) base × (1 + vat.pct/100)
        $sumBase = 0.0;
        $sumWithVat = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rowBase = $row['totalPrice'] ?? null;
            if ($rowBase === null || !is_numeric($rowBase)) continue;
            $rowBaseF = (float) $rowBase;
            $sumBase += $rowBaseF;

            $pct = $row['vat']['pct'] ?? null;
            if ($pct !== null && is_numeric($pct)) {
                $sumWithVat += $rowBaseF * (1.0 + ((float) $pct) / 100.0);
            } else {
                $sumWithVat += $rowBaseF;
            }
        }
        $sumBase = round($sumBase, 2);
        $sumWithVat = round($sumWithVat, 2);

        // (3) vatRecap total
        $sumVatRecap = null;
        $vatRecap = $canonical['vatRecap'] ?? null;
        if (is_array($vatRecap) && count($vatRecap) > 0) {
            $acc = 0.0;
            $hasAny = false;
            foreach ($vatRecap as $r) {
                if (is_array($r) && isset($r['total']) && is_numeric($r['total'])) {
                    $acc += (float) $r['total'];
                    $hasAny = true;
                }
            }
            if ($hasAny) {
                $sumVatRecap = round($acc, 2);
            }
        }

        // Celá deklarovaná částka v pásmu < 1,00 od varianty je vždy její
        // floor/ceil/round podoba — pokrývá zaokrouhlení celkové částky
        // (total_rounding_mode 1/3/4) bez výčtu módů. Nezávisí na
        // extrahovaném totals.totalRounding.
        $tolerance = 0.01;
        $declaredIsWhole = abs($declaredF - round($declaredF, 0)) <= 0.001;
        $matches = static fn (float $v): bool =>
            abs($v - $declaredF) <= $tolerance
            || ($declaredIsWhole && abs($v - $declaredF) < 1.00);

        $matchBase = $matches($sumBase);
        $matchWithVat = $matches($sumWithVat);
        $matchRecap = $sumVatRecap !== null && $matches($sumVatRecap);

        if ($matchBase || $matchWithVat || $matchRecap) {
            return;
        }

        $detail = "součet řádků bez DPH: {$sumBase}; s DPH per řádek: {$sumWithVat}";
        if ($sumVatRecap !== null) {
            $detail .= "; podle vatRecap: {$sumVatRecap}";
        }
        $issues[] = [
            'severity' => 'warning',
            'path'     => 'totals.totalAmount',
            'code'     => 'totals_mismatch',
            'message'  => "Deklarovaná částka {$declaredF} neodpovídá žádné vypočtené variantě ({$detail}).",
        ];
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkPartnerDocNumber(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        if ($docType !== 'invoiceReceived') {
            return;
        }
        $targetState = $canonical['applyOptions']['targetDocState'] ?? null;
        if ($targetState === null || (int) $targetState < 20) {
            return;
        }
        $partnerDocNumber = $canonical['docNumber'] ?? null;
        if ($partnerDocNumber === null || trim((string) $partnerDocNumber) === '') {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'docNumber',
                'code'     => 'partner_doc_number_missing',
                'message'  => 'Číslo dokladu od dodavatele není vyplněno.',
            ];
        }
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    private function required(string $path, string $message): array
    {
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'required',
            'message'  => $message,
        ];
    }
}
