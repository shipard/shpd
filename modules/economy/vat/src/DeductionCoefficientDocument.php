<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Koeficient odpočtu DPH (`economy_vat_deduction_coefficients`, #59 D13).
 *
 * Validace (D13d, fail loudly): povinná registrace a rok; oba koeficienty
 * volitelné, ale když jsou zadané, musí ležet v ⟨0; 1⟩ a mít přesnost na
 * setiny (§ 76 odst. 3 ZDPH — celé procento); registrace má za rok nejvýš
 * jeden živý záznam. Prázdný řetězec z formuláře se normalizuje na NULL.
 *
 * DB dotaz je v protected metodě, aby šel v testech přepsat bez mockování dibi.
 */
class DeductionCoefficientDocument extends Document
{
    private const DOC_STATE_DELETED = 90;

    public const COEFFICIENT_COLUMNS = ['coefficient_provisional', 'coefficient_settled'];

    private const YEAR_MIN = 1993;
    private const YEAR_MAX = 2100;

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['vat_registration'])) {
            $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
        }

        $year = $data['year'] ?? null;
        if ($year === null || $year === '') {
            $result->addError('year', 'Kalendářní rok je povinný', 'required');
        } elseif (!is_numeric($year) || (int) $year < self::YEAR_MIN || (int) $year > self::YEAR_MAX) {
            $result->addError('year', 'Kalendářní rok musí být v rozsahu ' . self::YEAR_MIN . '–' . self::YEAR_MAX, 'invalid_range');
        }

        foreach (self::COEFFICIENT_COLUMNS as $column) {
            $this->validateCoefficient($result, $data, $column);
        }

        if (!$result->isValid()) {
            return $result;
        }

        $state = (int) ($data['docState'] ?? 10);
        if ($state !== self::DOC_STATE_DELETED) {
            $selfId = isset($data['id']) ? (int) $data['id'] : null;
            $duplicate = $this->findDuplicate((int) $data['vat_registration'], (int) $data['year'], $selfId);
            if ($duplicate !== null) {
                $result->addError(
                    'year',
                    "Pro rok {$data['year']} už tato registrace koeficient má (záznam #{$duplicate}). Upravte existující záznam.",
                    'duplicate',
                );
            }
        }

        return $result;
    }

    /**
     * Prázdné = NULL; jinak číslo v ⟨0; 1⟩ s přesností na setiny.
     *
     * @param array<string, mixed> $data
     */
    private function validateCoefficient(ValidationResult $result, array &$data, string $column): void
    {
        $value = $data[$column] ?? null;
        if ($value === null || $value === '') {
            $data[$column] = null;
            return;
        }
        if (!is_numeric($value)) {
            $result->addError($column, 'Koeficient musí být číslo', 'invalid_value');
            return;
        }
        $float = (float) $value;
        if ($float < 0.0 || $float > 1.0) {
            $result->addError($column, 'Koeficient musí být v intervalu 0,00–1,00 (0,80 = 80 %)', 'invalid_range');
            return;
        }
        // Setiny: 0.80 → 80.0; 0.805 → 80.5 (nevyhoví).
        if (abs(round($float * 100) - $float * 100) > 1e-9) {
            $result->addError(
                $column,
                'Koeficient se zadává na celá procenta (dvě desetinná místa, např. 0,80) — ZDPH zaokrouhluje na celé procento nahoru.',
                'coefficient_precision',
            );
            return;
        }
        $data[$column] = round($float, 4);
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** Id živého záznamu téže registrace a roku (kromě sebe sama), null když není. */
    protected function findDuplicate(int $regId, int $year, ?int $selfId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM [economy_vat_deduction_coefficients]'
            . ' WHERE [vat_registration] = %i AND [year] = %i AND [docState] != %i AND [id] != %i'
            . ' ORDER BY [id] LIMIT 1',
            $regId, $year, self::DOC_STATE_DELETED, $selfId ?? 0,
        );
        return $id !== null && $id !== false ? (int) $id : null;
    }
}
