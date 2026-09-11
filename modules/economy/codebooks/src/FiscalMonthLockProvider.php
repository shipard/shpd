<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;

/**
 * Zámek fiskálního měsíce nad doklady (`documentLockProviders` pro
 * `docs_core_heads`, #55 D27): doklad je zamčený, když jeho původní
 * `fiscal_month` nebo nový měsíc (dopočtený z `accounting_date` stejným
 * pravidlem jako DocDocument, `FiscalMonthLookup`) míří na měsíc
 * s `locked = 1` — **bez ohledu na obsah a stav dokladu**, koncepty včetně.
 * Bez účetního data na nové straně nic; původní strana platí vždy.
 *
 * Roční `economy_codebooks_fiscal_years.locked` se tu nevynucuje —
 * sémantika uzavřeného roku přijde s uzávěrkou.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class FiscalMonthLockProvider extends AbstractDocumentLockProvider
{
    public const SOURCE = 'fiscal_month';

    /** tableId `economy_codebooks_fiscal_months` */
    public const SUBJECT_TABLE_ID = 314;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($this->db === null) {
            return [];
        }

        $candidates = [];
        $originalMonth = self::monthOf($original);
        if ($originalMonth !== null) {
            $candidates[$originalMonth] = true;
        }

        $newDate = FiscalMonthLookup::isoDate($data['accounting_date'] ?? null);
        if ($newDate !== null) {
            // Nezměněné účetní datum → stejný měsíc jako uložený (bez dotazu).
            $originalDate = $original !== null ? FiscalMonthLookup::isoDate($original['accounting_date'] ?? null) : null;
            $newMonth = ($originalMonth !== null && $newDate === $originalDate)
                ? $originalMonth
                : $this->monthIdForDate($newDate);
            if ($newMonth !== null) {
                $candidates[$newMonth] = true;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $reasons = [];
        foreach ($this->lockedMonths(array_keys($candidates)) as $monthId => $month) {
            $label = sprintf('%04d/%02d', (int) $month['calendar_year'], (int) $month['calendar_month']);
            $reasons[] = new DocumentLockReason(
                source: self::SOURCE,
                title: "Fiskální měsíc {$label} je uzamčený",
                message: 'Doklad má účetní datum v uzamčeném fiskálním měsíci — uložení, oprava, storno'
                    . ' i smazání vyžadují jeho odemknutí (Fiskální období → Měsíce).',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $monthId,
                params: ['year' => (int) $month['calendar_year'], 'month' => (int) $month['calendar_month'], 'label' => $label],
            );
        }
        return $reasons;
    }

    /** @param array<string, mixed>|null $row */
    private static function monthOf(?array $row): ?int
    {
        $value = $row['fiscal_month'] ?? null;
        return $value !== null && $value !== '' && (int) $value > 0 ? (int) $value : null;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    protected function monthIdForDate(string $date): ?int
    {
        return $this->db !== null ? FiscalMonthLookup::monthIdForDate($this->db, $date) : null;
    }

    /**
     * Zamčené měsíce z daných id.
     *
     * @param list<int> $ids
     * @return array<int, array{calendar_year: int, calendar_month: int}>
     */
    protected function lockedMonths(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [calendar_year], [calendar_month] FROM [economy_codebooks_fiscal_months]'
            . ' WHERE [id] IN %in AND [locked] = 1',
            $ids,
        ) as $row) {
            $out[(int) $row['id']] = [
                'calendar_year'  => (int) $row['calendar_year'],
                'calendar_month' => (int) $row['calendar_month'],
            ];
        }
        return $out;
    }
}
