<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\BookingHistory;

/**
 * Kandidáti seed pravidel `IČO → obsahový štítek` (D32) — streamovaný
 * akumulátor nad reverzními štítky záznamů.
 *
 * Prahy: dominantní štítek musí mít `share >= 0.8` (podíl **řádků** mezi
 * řádky s rozřešeným štítkem), `docCount >= 3` a od D37 také
 * `coverage >= 0.5` (podíl řádků IČO, kterým reverz vůbec dal štítek).
 * `docCount` se bere z dominantního štítku, ne z celého IČO — práh tak
 * stojí na důkazech pro ten konkrétní štítek. Je to horní odhad počtu
 * dokladů (jeden doklad spadá pod víc agregačních klíčů), takže práh 3 je
 * spodní mez, kterou odhad může jen nadstřelit.
 *
 * Práh pokrytí přišel z pilotu: kandidáti s pokrytím 20–43 % stavěli
 * pravidlo na malém výseku historie dodavatele — u zbytku jeho řádků
 * reverz nevěděl nic, takže „100% dominance" byla dominance mezi třemi
 * řádky z dvaceti. Takový kandidát se **nezahazuje**, jen neprojde:
 * {@see previewCandidates()} ho vrací dál se stavem, ať je v reportu vidět.
 *
 * Remíza dominance → bez kandidáta: dodavatel s pestrým sortimentem
 * pravidlo nedostane (stejná logika jako mazání kolizních `learned`
 * pravidel v ContentTagRuleCaptureHandler).
 *
 * Záznamy bez IČO ({@see BookingHistoryRecord::$companyId} null) se
 * nepočítají do ničeho než do statistiky přeskočených.
 */
final class BookingHistorySeedBuilder
{
    public const DEFAULT_MIN_SHARE = 0.8;
    public const DEFAULT_MIN_DOC_COUNT = 3;
    public const DEFAULT_MIN_COVERAGE = 0.5;

    /** @var array<string, array{totalRows: int, tags: array<string, array{rows: int, docs: int}>}> */
    private array $companies = [];

    private int $recordsWithoutCompanyId = 0;

    public function __construct(
        private readonly float $minShare = self::DEFAULT_MIN_SHARE,
        private readonly int $minDocCount = self::DEFAULT_MIN_DOC_COUNT,
        private readonly float $minCoverage = self::DEFAULT_MIN_COVERAGE,
    ) {}

    public function minShare(): float
    {
        return $this->minShare;
    }

    public function minDocCount(): int
    {
        return $this->minDocCount;
    }

    public function minCoverage(): float
    {
        return $this->minCoverage;
    }

    public function add(BookingHistoryRecord $record, AccountTagMatch $match): void
    {
        if ($record->companyId === null) {
            $this->recordsWithoutCompanyId++;
            return;
        }

        $company = $this->companies[$record->companyId] ?? ['totalRows' => 0, 'tags' => []];
        $company['totalRows'] += $record->rowCount;

        if ($match->tag !== null) {
            $tag = $company['tags'][$match->tag] ?? ['rows' => 0, 'docs' => 0];
            $tag['rows'] += $record->rowCount;
            $tag['docs'] += $record->docCount;
            $company['tags'][$match->tag] = $tag;
        }

        $this->companies[$record->companyId] = $company;
    }

    /**
     * Kandidáti splňující **všechny** prahy, nejsilnější podpora první —
     * jediný vstup pro `--apply-seed`.
     *
     * @return list<SeedCandidate>
     */
    public function candidates(): array
    {
        return array_values(array_filter(
            $this->previewCandidates(),
            static fn (SeedCandidate $candidate): bool => $candidate->isAccepted(),
        ));
    }

    /**
     * Kandidáti pro náhled v reportu — včetně zamítnutých prahem pokrytí
     * ({@see SeedCandidate::$rejectedBy}). Transparence: člověk má vidět,
     * co těsně nevyšlo, ne jen výsledné počty.
     *
     * @return list<SeedCandidate>
     */
    public function previewCandidates(): array
    {
        $out = [];
        foreach ($this->companies as $companyId => $company) {
            $candidate = $this->evaluate((string) $companyId, $company);
            if ($candidate instanceof SeedCandidate) {
                $out[] = $candidate;
            }
        }
        usort($out, static function (SeedCandidate $a, SeedCandidate $b): int {
            return [$b->rows, $b->docs, $a->companyId] <=> [$a->rows, $a->docs, $b->companyId];
        });
        return $out;
    }

    /**
     * Proč IČO kandidátem není — vstup do reportu, aby prahy nebyly
     * neprůhledné.
     *
     * @return array{noCompanyIdRecords: int, companies: int, noResolvedTag: int, tie: int, belowShare: int, belowDocCount: int, belowCoverage: int}
     */
    public function skipped(): array
    {
        $counters = [
            'noCompanyIdRecords' => $this->recordsWithoutCompanyId,
            'companies'          => count($this->companies),
            'noResolvedTag'      => 0,
            'tie'                => 0,
            'belowShare'         => 0,
            'belowDocCount'      => 0,
            'belowCoverage'      => 0,
        ];
        foreach ($this->companies as $companyId => $company) {
            $result = $this->evaluate((string) $companyId, $company);
            if (is_string($result)) {
                $counters[$result]++;
            } elseif ($result->rejectedBy === SeedCandidate::REJECTED_COVERAGE) {
                $counters['belowCoverage']++;
            }
        }
        return $counters;
    }

    /**
     * @param array{totalRows: int, tags: array<string, array{rows: int, docs: int}>} $company
     * @return SeedCandidate|string kandidát (i zamítnutý pokrytím), nebo
     *         důvod, proč kandidát nevznikl vůbec
     */
    private function evaluate(string $companyId, array $company): SeedCandidate|string
    {
        if ($company['tags'] === []) {
            return 'noResolvedTag';
        }

        $resolvedRows = 0;
        $bestTag = null;
        $bestRows = -1;
        $tieAtBest = false;
        foreach ($company['tags'] as $tag => $stats) {
            $resolvedRows += $stats['rows'];
            if ($stats['rows'] > $bestRows) {
                $bestRows = $stats['rows'];
                $bestTag = (string) $tag;
                $tieAtBest = false;
            } elseif ($stats['rows'] === $bestRows) {
                $tieAtBest = true;
            }
        }
        if ($resolvedRows <= 0 || $bestTag === null) {
            return 'noResolvedTag';
        }
        if ($tieAtBest) {
            return 'tie';
        }

        $share = $bestRows / $resolvedRows;
        if ($share < $this->minShare) {
            return 'belowShare';
        }
        $docs = $company['tags'][$bestTag]['docs'];
        if ($docs < $this->minDocCount) {
            return 'belowDocCount';
        }

        $coverage = $company['totalRows'] > 0 ? $resolvedRows / $company['totalRows'] : 0.0;

        return new SeedCandidate(
            companyId: $companyId,
            tag: $bestTag,
            rows: $bestRows,
            docs: $docs,
            resolvedRows: $resolvedRows,
            totalRows: $company['totalRows'],
            share: $share,
            coverage: $coverage,
            // Pod prahem pokrytí kandidát neprojde, ale zůstane v náhledu.
            rejectedBy: $coverage < $this->minCoverage ? SeedCandidate::REJECTED_COVERAGE : null,
        );
    }
}
