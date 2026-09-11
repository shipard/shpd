<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\LockStamp;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Fiskální měsíc — sub-tabulka fiskálního roku.
 *
 * Zámek měsíce (#55 D27): `locked` smí mít jen běžný měsíc (`period_type`
 * 1); zamčený měsíc nemění rozsah, typ ani rok — jediná povolená mutace
 * je přepnutí `locked`. Nad doklady zámek vynucuje FiscalMonthLockProvider.
 * `locked_at`/`locked_by` stampuje LockStamp z CurrentUser.
 *
 * DB dotazy jsou v protected metodách, aby šly v testech přepsat.
 */
class FiscalMonthDocument extends Document
{
    private const VALID_PERIOD_TYPES = [0, 1, 2];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['fiscal_year'])) {
            $result->addError('fiscal_year', 'Fiskální rok je povinný', 'required');
        }
        if (empty($data['date_begin'])) {
            $result->addError('date_begin', 'Začátek období je povinný', 'required');
        }
        if (empty($data['date_end'])) {
            $result->addError('date_end', 'Konec období je povinný', 'required');
        }
        if (!isset($data['period_type']) || $data['period_type'] === '' || $data['period_type'] === null) {
            $result->addError('period_type', 'Typ období je povinný', 'required');
        }

        if (!empty($data['date_begin']) && !empty($data['date_end'])
            && (string) $data['date_begin'] > (string) $data['date_end']
        ) {
            $result->addError(
                'date_end',
                'Konec období musí být později nebo stejný den jako začátek.',
                'invalid_range',
            );
        }

        if (isset($data['period_type']) && $data['period_type'] !== '' && $data['period_type'] !== null
            && !in_array((int) $data['period_type'], self::VALID_PERIOD_TYPES, true)
        ) {
            $result->addError(
                'period_type',
                'Neplatný typ období.',
                'invalid_value',
            );
        }

        if (!empty($data['locked']) && isset($data['period_type'])
            && (int) $data['period_type'] !== FiscalMonthLookup::PERIOD_TYPE_REGULAR
        ) {
            $result->addError('locked', 'Zamknout lze jen běžný měsíc (Otevření a Uzavření patří k uzávěrce roku).', 'invalid_value');
        }

        $current = !empty($data['id']) ? $this->loadCurrent((int) $data['id']) : null;

        // Zamykání (0→1): varovat, neblokovat, když za podané přiznání
        // končící v měsíci zůstává nevypořádaná DPH na 343 (#55 D27/D31).
        if (!empty($data['locked']) && empty($current['locked']) && $result->isValid()
            && !empty($data['date_begin']) && !empty($data['date_end'])
        ) {
            $begin = (string) FiscalMonthLookup::isoDate($data['date_begin']);
            $end   = (string) FiscalMonthLookup::isoDate($data['date_end']);
            foreach ($this->closedPeriodBalancesForRange($begin, $end) as $f) {
                $amount = number_format((float) $f['balance'], 2, ',', ' ');
                $result->addWarning(
                    'locked',
                    "Za podané přiznání {$f['period_name']} zůstává na {$f['account']} {$amount} Kč"
                        . ' — chybí zaúčtování přiznání, nebo se DPH po podání změnila.',
                    'closed_period_balance',
                );
            }
        }

        if ($current !== null && !empty($current['locked'])) {
            $frozenChanged = (int) ($current['fiscal_year'] ?? 0) !== (int) ($data['fiscal_year'] ?? 0)
                || (int) ($current['period_type'] ?? 1) !== (int) ($data['period_type'] ?? 1)
                || FiscalMonthLookup::isoDate($current['date_begin'] ?? null) !== FiscalMonthLookup::isoDate($data['date_begin'] ?? null)
                || FiscalMonthLookup::isoDate($current['date_end'] ?? null) !== FiscalMonthLookup::isoDate($data['date_end'] ?? null);
            if ($frozenChanged) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Fiskální měsíc je uzamčený — rozsah, typ ani rok nelze měnit. Nejdřív ho odemkněte.',
                    'locked',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        LockStamp::apply($data, $originalData, $this->currentUserId(), $this->now());

        if (empty($data['date_begin'])) {
            return;
        }

        $begin = new \DateTimeImmutable((string) $data['date_begin']);
        $data['calendar_year'] = (int) $begin->format('Y');
        $data['calendar_month'] = (int) $begin->format('n');
    }

    // ── DB přístup a kontext (přepsatelné v testech) ────────────────────────

    /** @return ?array<string, mixed> */
    protected function loadCurrent(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM [economy_codebooks_fiscal_months] WHERE [id] = %i', $id);
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * Nevypořádané zůstatky 343 za podané instance přiznání s koncem období
     * v rozsahu. Měkká vazba na `economy.vat`: běží jen s přítomnou tabulkou
     * instancí (modul aktivní na DS) — codebooks na vat nezávisí.
     *
     * @return list<array{period_id: int, period_name: string, date_end: string, account: string, balance: float}>
     */
    protected function closedPeriodBalancesForRange(string $begin, string $end): array
    {
        if ($this->db === null) {
            return [];
        }
        $present = $this->db->fetchSingle('SHOW TABLES LIKE %s', 'economy_vat_report_periods');
        if ($present === null || $present === false) {
            return [];
        }
        $service = new \Shipard\Module\Economy\Vat\ClosedPeriodBalanceService(new DataSourceConnection($this->db));
        return $service->findingsForRange($begin, $end);
    }

    protected function currentUserId(): ?int
    {
        return CurrentUser::id();
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
