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

        $computed = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            // Prefer row.computed.vatTotal (with VAT) over row.totalPrice
            // — the canonical's row.totalPrice is base-only.
            $rowTotal = $row['computed']['vatTotal'] ?? $row['totalPrice'] ?? null;
            if ($rowTotal !== null) {
                $computed += (float) $rowTotal;
            }
        }
        $computed = round($computed, 2);
        $declaredF = round((float) $declared, 2);

        // Half-cent tolerance for rounding noise.
        if (abs($computed - $declaredF) > 0.01) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'totals.totalAmount',
                'code'     => 'totals_mismatch',
                'message'  => "Deklarovaná částka {$declaredF} neodpovídá vypočtené {$computed}.",
            ];
        }
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
