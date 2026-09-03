<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;

/**
 * Instance daňového tvrzení (`economy_vat_report_periods`, issue #55 D7).
 *
 * Validace: povinná pole, `date_begin <= date_end`, **žádný překryv** živých
 * instancí v rámci (registrace, typ) — tvrdá chyba; díra k sousední
 * instanci je jen varování (uživatel ji může chtít, např. přerušené
 * plátcovství).
 *
 * Guardy zrušení (přechod do 90 přes stateTransitionsRunDocumentHooks
 * i tvrdé smazání): zamčená instance, přiřazené doklady, podání (bod
 * rozšíření — tabulky podání zatím neexistují).
 *
 * DB dotazy jsou v protected metodách, aby šly v testech přepsat bez
 * mockování dibi.
 */
class ReportPeriodDocument extends Document
{
    public const TYPES = ['return', 'cs', 'rs'];

    private const DOC_STATE_DELETED = 90;

    /** Sloupce docs_core_heads mířící na instanci dle typu (extension economy.vat). */
    public const HEAD_COLUMN_BY_TYPE = [
        'return' => 'vat_period',
        'cs'     => 'cs_period',
        'rs'     => 'rs_period',
    ];

    /** Rozsah se při tomto uložení změnil → afterPersist spustí přepočet. */
    private bool $rangeChanged = false;

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->rangeChanged = $originalData !== null
            && !empty($data['id'])
            && (
                $this->isoDate($data['date_begin'] ?? '') !== $this->isoDate($originalData['date_begin'] ?? '')
                || $this->isoDate($data['date_end'] ?? '') !== $this->isoDate($originalData['date_end'] ?? '')
                || (int) ($data['docState'] ?? 10) !== (int) ($originalData['docState'] ?? 10)
            );
    }

    /**
     * Uvnitř save transakce po zápisu: změna rozsahu (nebo stavu — smazaná
     * instance přestává pokrývat) přepočítá zařazení dotčených dokladů
     * (VatPeriodRecalculator). Atomické s uložením instance.
     */
    public function afterPersist(array $data): void
    {
        if (!$this->rangeChanged || $this->db === null || empty($data['id'])) {
            return;
        }
        $this->recalculate((int) $data['id']);
    }

    protected function recalculate(int $instanceId): void
    {
        $cfg = $this->config?->cfgItem('economy.vat.reports.cz');
        $mapping = is_array($cfg) ? new VatOutputsMapping($cfg) : null;
        (new VatPeriodRecalculator(new DataSourceConnection($this->db), $mapping))
            ->recomputeForInstance($instanceId);
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        if (empty($data['vat_registration'])) {
            $result->addError('vat_registration', 'Registrace DPH je povinná', 'required');
        }
        $type = (string) ($data['report_type'] ?? '');
        if ($type === '') {
            $result->addError('report_type', 'Typ tvrzení je povinný', 'required');
        } elseif (!in_array($type, self::TYPES, true)) {
            $result->addError('report_type', 'Neznámý typ tvrzení', 'invalid_value');
        }
        if (empty($data['name'])) {
            $result->addError('name', 'Název je povinný', 'required');
        }
        if (empty($data['date_begin'])) {
            $result->addError('date_begin', 'Začátek období je povinný', 'required');
        }
        if (empty($data['date_end'])) {
            $result->addError('date_end', 'Konec období je povinný', 'required');
        }

        if (!$result->isValid()) {
            return $result;
        }

        $begin = $this->isoDate($data['date_begin']);
        $end   = $this->isoDate($data['date_end']);
        if ($begin > $end) {
            $result->addError('date_end', 'Konec období musí být později nebo stejný den jako začátek.', 'invalid_range');
            return $result;
        }

        $regId = (int) $data['vat_registration'];
        $selfId = isset($data['id']) ? (int) $data['id'] : null;
        $state = (int) ($data['docState'] ?? 10);

        // Smazaná instance se do překryvů nepočítá (a sama nic neblokuje).
        if ($state !== self::DOC_STATE_DELETED) {
            $overlaps = $this->findOverlapping($regId, $type, $begin, $end, $selfId);
            if ($overlaps !== []) {
                $names = implode(', ', array_map(static fn (array $r): string => (string) $r['name'], $overlaps));
                $result->addError(
                    'date_begin',
                    "Rozsah se překrývá s instancí téhož typu: {$names}. Instance jednoho tvrzení na sebe musí navazovat bez průniku.",
                    'overlap',
                );
                return $result;
            }

            [$prevEnd, $nextBegin] = $this->findNeighbours($regId, $type, $begin, $end, $selfId);
            if ($prevEnd !== null && $this->nextDay($prevEnd) !== $begin) {
                $result->addWarning(
                    'date_begin',
                    "Mezi předchozí instancí (do {$prevEnd}) a touto je mezera — doklady s datem v mezeře nespadnou do žádného tvrzení.",
                    'gap',
                );
            }
            if ($nextBegin !== null && $this->nextDay($end) !== $nextBegin) {
                $result->addWarning(
                    'date_end',
                    "Mezi touto a následující instancí (od {$nextBegin}) je mezera — doklady s datem v mezeře nespadnou do žádného tvrzení.",
                    'gap',
                );
            }
        }

        // Guardy zrušení — přechod do Smazáno.
        if ($selfId !== null && $state === self::DOC_STATE_DELETED) {
            $current = $this->loadCurrent($selfId);
            if ($current !== null && (int) ($current['docState'] ?? 0) !== self::DOC_STATE_DELETED) {
                foreach ($this->cancellationBlockers($current) as $message) {
                    $result->addError(ValidationError::FIELD_FORM, $message, 'cancellation_blocked');
                }
            }
        }

        return $result;
    }

    /**
     * Tvrdé smazání (DELETE) — stejné guardy jako zrušení. Výjimka = rollback
     * v TableGateway::deleteDocument.
     */
    public function beforeDelete(array $data): void
    {
        $blockers = $this->cancellationBlockers($data);
        if ($blockers !== []) {
            throw new \DomainException(implode(' ', $blockers));
        }
    }

    /**
     * Důvody, proč instanci nelze zrušit ani smazat. Prázdné = lze.
     *
     * @param array<string, mixed> $row aktuální řádek instance
     * @return list<string>
     */
    protected function cancellationBlockers(array $row): array
    {
        $blockers = [];
        if (!empty($row['locked'])) {
            $blockers[] = 'Instance je uzamčená — nejdřív ji odemkněte.';
        }

        $type = (string) ($row['report_type'] ?? '');
        $column = self::HEAD_COLUMN_BY_TYPE[$type] ?? null;
        if ($column !== null && isset($row['id'])) {
            $count = $this->countAssignedDocuments($column, (int) $row['id']);
            if ($count > 0) {
                $blockers[] = "Na instanci je přiřazeno {$count} dokladů — nejdřív je přepřiřaďte"
                    . ' (založte správné instance a doklady se při přepočtu chytí jich).';
            }
        }

        // Bod rozšíření: podání (Fáze 2 dle #55). Až tabulky podání vzniknou,
        // instance, na kterou odkazuje podání, se nesmí zrušit.

        return $blockers;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Živé instance téže registrace a typu, jejichž rozsah se protíná
     * s [$begin, $end], kromě sebe sama.
     *
     * @return list<array{id: int, name: string}>
     */
    protected function findOverlapping(int $regId, string $type, string $begin, string $end, ?int $selfId): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_begin] <= %d AND [date_end] >= %d AND [id] != %i',
            $regId, $type, self::DOC_STATE_DELETED, $end, $begin, $selfId ?? 0,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $out;
    }

    /**
     * Konec nejbližší předchozí a začátek nejbližší následující živé
     * instance (ISO), null když neexistují.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function findNeighbours(int $regId, string $type, string $begin, string $end, ?int $selfId): array
    {
        if ($this->db === null) {
            return [null, null];
        }
        $prev = $this->db->fetchSingle(
            'SELECT MAX([date_end]) FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_end] < %d AND [id] != %i',
            $regId, $type, self::DOC_STATE_DELETED, $begin, $selfId ?? 0,
        );
        $next = $this->db->fetchSingle(
            'SELECT MIN([date_begin]) FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_begin] > %d AND [id] != %i',
            $regId, $type, self::DOC_STATE_DELETED, $end, $selfId ?? 0,
        );
        return [
            $prev !== null && $prev !== false ? $this->isoDate($prev) : null,
            $next !== null && $next !== false ? $this->isoDate($next) : null,
        ];
    }

    /** @return ?array<string, mixed> */
    protected function loadCurrent(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM [economy_vat_report_periods] WHERE [id] = %i', $id);
        return $row !== null ? $row->toArray() : null;
    }

    protected function countAssignedDocuments(string $headColumn, int $periodId): int
    {
        if ($this->db === null) {
            return 0;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM [docs_core_heads] WHERE %n = %i AND [docState] != %i',
            $headColumn, $periodId, self::DOC_STATE_DELETED,
        );
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    protected function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    protected function nextDay(string $isoDate): string
    {
        return (new \DateTimeImmutable($isoDate))->modify('+1 day')->format('Y-m-d');
    }
}
