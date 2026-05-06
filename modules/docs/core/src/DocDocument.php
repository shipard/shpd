<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Base class for all document types (issued invoice, received invoice, …).
 *
 * Polymorphism: docs_core_heads has `doc_type` (enumString) which resolves
 * to a specific subclass via cfgItem docs.core.docTypes (`subclass` attr).
 * Concrete subclasses live in docs.invoicesOut and docs.invoicesIn.
 *
 * Phase 1 — minimal logic:
 *   - validate(): required number_series, issue_date, accounting_date
 *   - beforeSave(): denormalize doc_type from number_series
 *   - afterPersist(): init doc_number as `!{id_padded}` for new drafts
 *
 * Phase 2 fills in the stub methods (price/VAT calculations,
 * recapitulation, snapshots, atomic number assignment).
 */
abstract class DocDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['number_series'])) {
            $result->addError('number_series', 'Číselná řada je povinná', 'required');
        }
        if (empty($data['issue_date'])) {
            $result->addError('issue_date', 'Datum vystavení je povinné', 'required');
        }
        if (empty($data['accounting_date'])) {
            $result->addError('accounting_date', 'Účetní datum je povinné', 'required');
        }

        return $result;
    }

    public function beforeSave(array &$data): void
    {
        if (!empty($data['number_series']) && $this->db !== null) {
            $row = $this->db->fetch(
                'SELECT [doc_type] FROM [docs_core_number_series] WHERE [id] = %i',
                (int) $data['number_series'],
            );
            if ($row !== null) {
                $data['doc_type'] = (string) $row['doc_type'];
            }
        }

        // Phase 2 will add here:
        //   - accounting_date / vat_duzp defaults from issue_date
        //   - fiscal_year / fiscal_month resolution
        //   - vat_period resolution
        //   - row calculations (price, vat)
        //   - vat recapitulation build
        //   - totals sum
        //   - snapshots
        //   - on Concept → Confirmed: assignDocumentNumber
    }

    public function afterPersist(array $data): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }

        $current = $this->db->fetch(
            'SELECT [doc_number] FROM [docs_core_heads] WHERE [id] = %i',
            (int) $data['id'],
        );
        if ($current === null) {
            return;
        }

        $docNumber = (string) ($current['doc_number'] ?? '');
        if ($docNumber !== '') {
            return;
        }

        $placeholder = '!' . str_pad((string) $data['id'], 10, '0', STR_PAD_LEFT);
        $this->db->query(
            'UPDATE [docs_core_heads] SET [doc_number] = %s WHERE [id] = %i',
            $placeholder,
            (int) $data['id'],
        );
    }

    // ── Phase 2 stub methods ────────────────────────────────────────────────
    //
    // Subclasses can already reference these, but they are no-ops in Phase 1.
    // Phase 2 fills them in.

    protected function calculateRowPrice(array &$row): void
    {
        // Phase 2
    }

    protected function calculateRowVat(array &$row, int $vatMode): void
    {
        // Phase 2
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildVatRecapitulation(array &$data): array
    {
        // Phase 2
        return [];
    }

    protected function sumTotals(array &$data, array $recap): void
    {
        // Phase 2
    }

    protected function applyRounding(float $amount, int $mode): float
    {
        // Phase 2
        return $amount;
    }

    protected function maintainSnapshots(array &$data, ?array $originalData): void
    {
        // Phase 2
    }

    protected function assignDocumentNumber(array &$data): void
    {
        // Phase 2
    }

    protected function resolveFiscalYearId(string $accountingDate): ?int
    {
        // Phase 2
        return null;
    }

    protected function resolveFiscalMonthId(string $accountingDate): ?int
    {
        // Phase 2
        return null;
    }

    protected function resolveVatPeriodId(string $vatDuzp, ?int $vatRegistrationId): ?int
    {
        // Phase 2
        return null;
    }
}
